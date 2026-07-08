<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Models\SyncSetting;
use App\Services\Csv\CsvDownloader;
use App\Services\Csv\Dto\ParsedProduct;
use App\Services\Csv\Dto\ParseLogEntry;
use App\Services\Csv\EncodingSanitizer;
use App\Services\Csv\ProductCsvParser;
use Illuminate\Support\Facades\DB;

/**
 * Orchestratore della prima fase della sync: scarica il CSV, lo sanitizza,
 * lo fa passare da ProductCsvParser e ne salva il risultato nelle tabelle di
 * staging, aggiornando contatori/stato dell'ImportRun passo per passo cosi'
 * la dashboard puo' mostrare progresso live.
 *
 * Si ferma qui di proposito: il motore di diff che confronta lo staging con lo
 * stato attuale e lo applica a Shopify e' la fase successiva della pipeline
 * (non ancora costruita). A fine corsa senza anomalie il run resta in stato
 * "parsing", pronto per essere ripreso da quella fase.
 */
class StagingImporter
{
    public function __construct(
        private readonly CsvDownloader $downloader = new CsvDownloader,
        private readonly EncodingSanitizer $sanitizer = new EncodingSanitizer,
        private readonly ProductCsvParser $parser = new ProductCsvParser,
    ) {}

    public function run(ImportRun $importRun): ImportRun
    {
        $importRun->forceFill(['started_at' => now()])->save();

        try {
            $csvPath = $this->downloadCsv($importRun);
            $sanitizedPath = $this->sanitizeCsv($importRun, $csvPath);
            $this->parseAndStage($importRun, $sanitizedPath);
        } catch (\Throwable $e) {
            $this->fail($importRun, $e->getMessage());
            throw $e;
        }

        return $importRun->refresh();
    }

    private function downloadCsv(ImportRun $importRun): string
    {
        $importRun->forceFill(['status' => 'downloading'])->save();

        $settings = SyncSetting::current();
        $importRun->forceFill(['source_url' => $settings->csv_source_url])->save();

        $destination = storage_path("app/private/imports/{$importRun->uuid}.csv");
        $result = $this->downloader->download($settings->csv_source_url, $destination);

        $importRun->forceFill(['csv_sha256' => $result->sha256])->save();

        return $result->path;
    }

    private function sanitizeCsv(ImportRun $importRun, string $csvPath): string
    {
        $importRun->forceFill(['status' => 'parsing'])->save();

        $sanitized = storage_path("app/private/imports/{$importRun->uuid}.sanitized.csv");
        $result = $this->sanitizer->sanitize($csvPath, $sanitized);

        if ($result->wasModified()) {
            $this->insertLogs($importRun, [ParseLogEntry::info(
                sprintf(
                    '%d righe non erano UTF-8 valido e sono state convertite da Windows-1252 (prime righe: %s).',
                    count($result->fallbackLines),
                    implode(', ', array_slice($result->fallbackLines, 0, 10)),
                ),
            )]);
        }

        return $result->path;
    }

    private function parseAndStage(ImportRun $importRun, string $sanitizedPath): void
    {
        $productsFailed = 0;
        $buffer = [];
        $bufferSize = 0;
        $flushEvery = 200;

        $flushBuffer = function () use (&$buffer, &$bufferSize, $importRun) {
            if ($buffer === []) {
                return;
            }
            $this->persistProducts($importRun, $buffer);
            $buffer = [];
            $bufferSize = 0;
        };

        $summary = $this->parser->parse($sanitizedPath, function (ParsedProduct $product) use (&$buffer, &$bufferSize, $flushBuffer, $flushEvery) {
            $buffer[] = $product;
            $bufferSize++;
            if ($bufferSize >= $flushEvery) {
                $flushBuffer();
            }
        });
        $flushBuffer();

        $this->insertLogs($importRun, $summary->logs);

        $previousSuccessfulCount = ImportRun::query()
            ->whereIn('status', ['completed', 'completed_with_warnings'])
            ->where('id', '!=', $importRun->id)
            ->orderByDesc('finished_at')
            ->value('csv_product_count');

        $importRun->forceFill([
            'csv_row_count' => $summary->csvRowCount,
            'csv_product_count' => $summary->productsParsed,
            'previous_successful_product_count' => $previousSuccessfulCount,
            'products_failed' => $summary->productsFailed,
        ])->save();

        $anomaly = $this->detectAnomaly($summary->productsParsed, $previousSuccessfulCount);

        if ($anomaly !== null) {
            $this->fail($importRun, $anomaly, anomaly: true);

            return;
        }

        $importRun->forceFill(['status' => 'parsing'])->save();
    }

    private function detectAnomaly(int $currentProductCount, ?int $previousSuccessfulCount): ?string
    {
        if ($previousSuccessfulCount === null || $previousSuccessfulCount === 0) {
            return null;
        }

        $threshold = SyncSetting::current()->anomaly_drop_threshold_percent;
        $dropPercent = (1 - ($currentProductCount / $previousSuccessfulCount)) * 100;

        if ($dropPercent >= $threshold) {
            return sprintf(
                'Crollo prodotti rilevato: %d nel nuovo CSV contro %d dell\'ultimo import riuscito (-%.1f%%, soglia configurata %d%%). Import bloccato per sicurezza.',
                $currentProductCount,
                $previousSuccessfulCount,
                $dropPercent,
                $threshold,
            );
        }

        return null;
    }

    /**
     * @param  list<ParsedProduct>  $products
     */
    private function persistProducts(ImportRun $importRun, array $products): void
    {
        DB::transaction(function () use ($importRun, $products) {
            foreach ($products as $product) {
                $stagingProductId = DB::table('staging_products')->insertGetId([
                    'import_run_id' => $importRun->id,
                    'codice_articolo' => $product->codiceArticolo,
                    'handle' => $product->handle,
                    'title' => $product->title,
                    'body_html' => $product->bodyHtml,
                    'vendor' => $product->vendor,
                    'gender' => $product->gender,
                    'category_path' => $product->categoryPath,
                    'collection_name' => $product->collectionName,
                    'meta_description' => $product->metaDescription,
                    'main_image_url' => $product->mainImageUrl,
                    'visibility_raw' => $product->visibilityRaw,
                    'status' => $product->status,
                    'content_hash' => $product->contentHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $variantRows = array_map(fn ($variant) => [
                    'staging_product_id' => $stagingProductId,
                    'codice_ean' => $variant->codiceEan,
                    'color' => $variant->color,
                    'size' => $variant->size,
                    'length' => $variant->length,
                    'price' => $variant->price,
                    'cost' => $variant->cost,
                    'quantity' => $variant->quantity,
                    'quantity_raw' => $variant->quantityRaw,
                    'image_url' => $variant->imageUrl,
                    'position' => $variant->position,
                    'content_hash' => $variant->contentHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $product->variants);

                DB::table('staging_variants')->insert($variantRows);
            }
        });
    }

    /**
     * @param  list<ParseLogEntry>  $logs
     */
    private function insertLogs(ImportRun $importRun, array $logs): void
    {
        if ($logs === []) {
            return;
        }

        $rows = array_map(fn (ParseLogEntry $log) => [
            'import_run_id' => $importRun->id,
            'level' => $log->level,
            'codice_articolo' => $log->codiceArticolo,
            'codice_ean' => $log->codiceEan,
            'csv_row_number' => $log->csvRowNumber,
            'message' => $log->message,
            'context' => $log->context !== null ? json_encode($log->context) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $logs);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_logs')->insert($chunk);
        }
    }

    private function fail(ImportRun $importRun, string $message, bool $anomaly = false): void
    {
        $importRun->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'anomaly_detected' => $anomaly,
            'anomaly_reason' => $anomaly ? $message : $importRun->anomaly_reason,
            'finished_at' => now(),
            'duration_seconds' => $importRun->started_at ? now()->diffInSeconds($importRun->started_at) : null,
        ])->save();

        $this->insertLogs($importRun, [ParseLogEntry::error($message)]);
    }
}
