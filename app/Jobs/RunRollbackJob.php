<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Rollback\RollbackPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper queueable sottile, stesso schema di RunImportJob: la logica vera
 * vive in RollbackPipeline (testabile senza infrastruttura di coda). Condivide
 * la stessa chiave di WithoutOverlapping di un import normale: un rollback e
 * una sync scaricano/scrivono le stesse tabelle canoniche, non devono mai
 * correre in parallelo.
 */
class RunRollbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $importRunId,
        public readonly int $targetImportRunId,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-run'))->dontRelease()];
    }

    public function handle(RollbackPipeline $pipeline): void
    {
        $importRun = ImportRun::findOrFail($this->importRunId);
        $targetRun = ImportRun::findOrFail($this->targetImportRunId);

        try {
            $pipeline->run($importRun, $targetRun);
        } catch (\Throwable $e) {
            Log::error('Rollback fallito', [
                'import_run_id' => $importRun->id,
                'target_import_run_id' => $targetRun->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
