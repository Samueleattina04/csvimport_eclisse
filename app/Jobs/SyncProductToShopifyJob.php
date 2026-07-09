<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Diff\DiffEngine;
use App\Services\Shopify\ProductSyncer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Sincronizza UN prodotto con Shopify. Payload volutamente minimo (solo gli
 * ID, non l'intero diff): il diff viene ricalcolato al momento dell'esecuzione
 * per avere sempre dati freschi, invece di far viaggiare oggetti grandi nella
 * coda. Un prodotto fallito viene loggato e conteggiato, ma non blocca gli
 * altri job del batch (vedi ImportPipeline::dispatchLiveSync).
 */
class SyncProductToShopifyJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $importRunId,
        public readonly string $codiceArticolo,
    ) {}

    public function handle(DiffEngine $diffEngine, ProductSyncer $syncer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importRun = ImportRun::findOrFail($this->importRunId);
        $diff = $diffEngine->diffSingleProduct($this->importRunId, $this->codiceArticolo);

        if ($diff === null || $diff->action === 'unchanged') {
            return;
        }

        try {
            $syncer->sync($importRun, $diff);
        } catch (\Throwable $e) {
            DB::table('import_logs')->insert([
                'import_run_id' => $importRun->id,
                'level' => 'error',
                'codice_articolo' => $this->codiceArticolo,
                'codice_ean' => null,
                'csv_row_number' => null,
                'message' => "Sync Shopify fallita per {$this->codiceArticolo}: {$e->getMessage()}",
                'context' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }
}
