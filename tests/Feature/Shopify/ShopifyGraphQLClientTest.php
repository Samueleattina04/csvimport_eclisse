<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ShopifyNotConfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica il client contro risposte che RIPRODUCONO la forma documentata
 * delle risposte Shopify GraphQL (successo, userErrors, throttling) via
 * Http::fake: non c'e' ancora uno store Shopify reale collegato a questo
 * ambiente di sviluppo, quindi questi test non validano la sintassi esatta
 * delle mutation reali, solo la logica del client (auth, parsing, retry, log).
 */
class ShopifyGraphQLClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(int $maxRetries = 3, int $retryDelayMs = 0): ShopifyGraphQLClient
    {
        return new ShopifyGraphQLClient(
            storeDomain: 'test-store.myshopify.com',
            accessToken: 'shpat_test_token',
            apiVersion: '2025-01',
            maxRetries: $maxRetries,
            retryDelayMs: $retryDelayMs,
        );
    }

    public function test_lancia_eccezione_se_le_credenziali_non_sono_configurate(): void
    {
        $client = new ShopifyGraphQLClient(storeDomain: null, accessToken: null);

        $this->expectException(ShopifyNotConfiguredException::class);
        $client->call('query { shop { name } }', [], 'testQuery');
    }

    public function test_invia_il_token_nellheader_corretto_e_riconosce_un_successo(): void
    {
        Http::fake([
            'test-store.myshopify.com/*' => Http::response([
                'data' => [
                    'productCreate' => [
                        'product' => ['id' => 'gid://shopify/Product/123'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client()->call('mutation { productCreate { product { id } userErrors { field message } } }', ['input' => ['title' => 'Test']], 'productCreate');

        $this->assertTrue($result->success);
        $this->assertSame('gid://shopify/Product/123', $result->data['productCreate']['product']['id']);
        $this->assertSame(200, $result->httpStatus);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Shopify-Access-Token', 'shpat_test_token')
                && $request->url() === 'https://test-store.myshopify.com/admin/api/2025-01/graphql.json';
        });
    }

    public function test_riconosce_gli_usererrors_della_mutation_come_fallimento(): void
    {
        Http::fake([
            'test-store.myshopify.com/*' => Http::response([
                'data' => [
                    'productCreate' => [
                        'product' => null,
                        'userErrors' => [['field' => ['title'], 'message' => 'Il titolo non puo\' essere vuoto']],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->client()->call('mutation {...}', [], 'productCreate');

        $this->assertFalse($result->success);
        $this->assertCount(1, $result->userErrors);
        $this->assertSame('Il titolo non puo\' essere vuoto', $result->firstErrorMessage());
    }

    public function test_riprova_quando_shopify_risponde_throttled_e_poi_va_a_buon_fine(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response([
                    'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                ], 200);
            }

            return Http::response(['data' => ['shop' => ['name' => 'Eclisse']]], 200);
        });

        $result = $this->client(maxRetries: 2, retryDelayMs: 0)->call('query {...}', [], 'shopQuery');

        $this->assertTrue($result->success);
        $this->assertSame(2, $callCount);
    }

    public function test_registra_ogni_chiamata_su_shopify_api_logs(): void
    {
        Http::fake([
            'test-store.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'Eclisse']]], 200),
        ]);

        $this->client()->call('query {...}', ['x' => 1], 'shopQuery', importRunId: null, entityReference: 'ABC123');

        $this->assertDatabaseHas('shopify_api_logs', [
            'operation_name' => 'shopQuery',
            'entity_reference' => 'ABC123',
            'success' => true,
            'retried_count' => 0,
        ]);
    }

    public function test_smette_di_riprovare_oltre_il_numero_massimo_di_tentativi(): void
    {
        Http::fake([
            'test-store.myshopify.com/*' => Http::response([
                'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            ], 200),
        ]);

        $result = $this->client(maxRetries: 2, retryDelayMs: 0)->call('query {...}', [], 'shopQuery');

        $this->assertFalse($result->success);
        $this->assertTrue($result->wasThrottled);

        $this->assertDatabaseHas('shopify_api_logs', ['retried_count' => 2]);
    }
}
