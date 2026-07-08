<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Import\ImportPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper queueable sottile: la logica vera vive in ImportPipeline (testabile
 * senza infrastruttura di coda). Non ritenta automaticamente: un import fallito
 * va rivisto, non rilanciato alla cieca dal sistema di code.
 */
class RunImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $importRunId) {}

    /**
     * Un solo import alla volta: se il job viene accodato due volte per errore
     * (doppio trigger schedulato + manuale, retry duplicato, ecc.) la seconda
     * esecuzione si ferma qui invece di correre in parallelo con la prima.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-run'))->dontRelease()];
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $importRun = ImportRun::findOrFail($this->importRunId);

        try {
            $pipeline->run($importRun);
        } catch (\Throwable $e) {
            Log::error('Import CSV -> Shopify fallito', [
                'import_run_id' => $importRun->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
