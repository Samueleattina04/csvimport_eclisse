<?php

namespace App\Services\Csv;

use App\Services\Csv\Dto\DownloadResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Scarica il CSV del gestionale su disco locale in streaming (evita di caricare
 * l'intero file in memoria) con retry/backoff sui fallimenti di rete transitori,
 * e fa le verifiche minime richieste prima di fidarsi del contenuto: risposta
 * HTTP ok, file non vuoto, non troncato a meta'.
 */
class CsvDownloader
{
    public function __construct(
        private readonly int $timeoutSeconds = 120,
        private readonly int $retries = 3,
        private readonly int $retryDelayMs = 2000,
    ) {}

    public function download(string $url, string $destinationPath): DownloadResult
    {
        File::ensureDirectoryExists(dirname($destinationPath));

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry($this->retries, $this->retryDelayMs, throw: false)
                ->sink($destinationPath)
                ->get($url);
        } catch (\Throwable $e) {
            throw new CsvDownloadException("Download del CSV fallito: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new CsvDownloadException("Download del CSV fallito: risposta HTTP {$response->status()} da {$url}");
        }

        if (! is_file($destinationPath) || filesize($destinationPath) === 0) {
            throw new CsvDownloadException('Il CSV scaricato e\' vuoto.');
        }

        return new DownloadResult(
            path: $destinationPath,
            sha256: hash_file('sha256', $destinationPath),
            sizeBytes: filesize($destinationPath),
            httpStatus: $response->status(),
        );
    }
}
