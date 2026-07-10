<?php

namespace App\Services\Shopify;

use App\Services\Shopify\Dto\ShopifyMutationResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Wrapper sottile sulla Admin API GraphQL di Shopify: autenticazione, retry
 * con backoff quando Shopify risponde "throttled" (il costo delle query ha
 * superato la soglia disponibile), e logging di ogni chiamata su
 * shopify_api_logs (riassunto, non l'intero payload).
 *
 * ATTENZIONE: questa classe non e' ancora stata verificata contro uno store
 * Shopify reale (nessuna credenziale disponibile in questo ambiente di
 * sviluppo). E' testata via Http::fake simulando la forma delle risposte
 * documentate da Shopify. Va validata con attenzione contro lo store di
 * sviluppo prima di qualunque uso reale, come da percorso Fase 1 concordato.
 */
class ShopifyGraphQLClient
{
    public function __construct(
        private readonly ?string $storeDomain = null,
        private readonly ?string $accessToken = null,
        private readonly ?string $apiVersion = null,
        private readonly int $maxRetries = 3,
        private readonly int $retryDelayMs = 1000,
    ) {}

    public function call(
        string $query,
        array $variables,
        string $operationName,
        ?int $importRunId = null,
        ?string $entityReference = null,
    ): ShopifyMutationResult {
        $domain = $this->storeDomain ?? config('services.shopify.store_domain');
        $token = $this->accessToken ?? config('services.shopify.access_token');
        $version = $this->apiVersion ?? config('services.shopify.api_version');

        if (! $domain || ! $token) {
            throw new ShopifyNotConfiguredException(
                'Credenziali Shopify non configurate: imposta SHOPIFY_STORE_DOMAIN e SHOPIFY_ADMIN_API_ACCESS_TOKEN nel file .env.'
            );
        }

        $url = "https://{$domain}/admin/api/{$version}/graphql.json";

        $start = microtime(true);
        $retried = 0;
        $response = $this->sendWithRetry($url, $token, $query, $variables, $retried);
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $result = $this->parseResponse($response);

        $this->log($importRunId, $operationName, $entityReference, $variables, $result, $durationMs, $retried);

        return $result;
    }

    private function sendWithRetry(string $url, string $token, string $query, array $variables, int &$retried): Response
    {
        $attempt = 0;

        while (true) {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->timeout(30)
                // Shopify vuole "variables" come oggetto JSON: un array PHP vuoto
                // si serializza come "[]" invece di "{}" e viene rifiutato con
                // "Invalid variables parameter" (verificato contro il dev store reale).
                ->post($url, ['query' => $query, 'variables' => $variables === [] ? new \stdClass : $variables]);

            $attempt++;

            if (! $this->wasThrottled($response) || $attempt > $this->maxRetries) {
                return $response;
            }

            $retried++;
            usleep($this->retryDelayMs * 1000 * $attempt);
        }
    }

    private function wasThrottled(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        foreach ((array) $response->json('errors', []) as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    private function parseResponse(Response $response): ShopifyMutationResult
    {
        $body = $response->json() ?? [];
        $errors = $this->normalizeErrors($body['errors'] ?? []);
        $data = $body['data'] ?? null;
        $userErrors = $this->extractUserErrors($data);
        $wasThrottled = $this->wasThrottled($response);

        return new ShopifyMutationResult(
            success: $response->successful() && $errors === [] && $userErrors === [],
            data: $data,
            errors: $errors,
            userErrors: $userErrors,
            httpStatus: $response->status(),
            wasThrottled: $wasThrottled,
        );
    }

    /**
     * Normalmente "errors" e' una lista di oggetti {"message": ...}, ma non
     * e' garantito: visto contro il dev store reale, uno store sospeso
     * risponde 404 con {"errors": "Not Found"} (una stringa nuda, non una
     * lista) - senza normalizzare qui, ShopifyMutationResult va in errore di
     * tipo invece di riportare un messaggio d'errore leggibile.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeErrors(mixed $errors): array
    {
        if (is_string($errors)) {
            return [['message' => $errors]];
        }

        if (! is_array($errors)) {
            return [];
        }

        return array_map(
            fn ($error) => is_array($error) ? $error : ['message' => (string) $error],
            array_values($errors),
        );
    }

    /**
     * Le mutation Shopify restituiscono convenzionalmente un campo "userErrors"
     * dentro il payload della mutation stessa (es. data.productCreate.userErrors),
     * ma alcune (es. productCreateMedia) usano un nome diverso per lo stesso scopo
     * (es. "mediaUserErrors"): controllare solo "userErrors" alla lettera fa
     * passare per riusciti errori applicativi reali (verificato contro il dev
     * store: un URL immagine non valido tornava "mediaUserErrors" non intercettato,
     * quindi loggato come successo mentre nessuna immagine veniva davvero allegata).
     */
    private function extractUserErrors(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $userErrors = [];
        foreach ($data as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            foreach ($payload as $key => $value) {
                if (str_ends_with(strtolower((string) $key), 'usererrors') && is_array($value)) {
                    array_push($userErrors, ...$value);
                }
            }
        }

        return $userErrors;
    }

    private function log(
        ?int $importRunId,
        string $operationName,
        ?string $entityReference,
        array $variables,
        ShopifyMutationResult $result,
        int $durationMs,
        int $retried,
    ): void {
        DB::table('shopify_api_logs')->insert([
            'import_run_id' => $importRunId,
            'operation_name' => $operationName,
            'entity_reference' => $entityReference,
            'request_summary' => Str::limit(json_encode($variables), 1000),
            'response_summary' => Str::limit(json_encode([
                'errors' => $result->errors,
                'userErrors' => $result->userErrors,
            ]), 1000),
            'http_status' => $result->httpStatus,
            'success' => $result->success,
            'user_errors' => $result->userErrors !== [] ? json_encode($result->userErrors) : null,
            'duration_ms' => $durationMs,
            'retried_count' => $retried,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
