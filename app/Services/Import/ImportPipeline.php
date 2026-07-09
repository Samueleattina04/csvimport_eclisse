<?php

namespace App\Services\Import;

use App\Jobs\SyncProductToShopifyJob;
use App\Models\ImportRun;
use App\Services\Diff\DiffEngine;
use App\Services\Diff\Dto\DiffResult;
use App\Services\Diff\Dto\ProductDiff;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mette in sequenza le fasi della sync: staging (fetch+parsing), diff, e
 * (solo se non e' dry-run) applicazione a Shopify.
 *
 * In modalita' dry-run (il default, sempre finche' non lo si disattiva
 * esplicitamente) il run si conclude subito dopo il diff: il piano e i log
 * gia' scritti SONO il report dry-run richiesto ("cosa farebbe senza
 * chiamare le API"). In modalita' live, un job per prodotto (uno per ogni
 * voce del diff diversa da "unchanged") viene accodato in un Bus::batch:
 * ogni prodotto e' un'unita' di lavoro piccola e indipendente, cosi' un
 * fallimento su un singolo prodotto non blocca gli altri e, se il worker si
 * interrompe a meta', i job non ancora eseguiti restano in coda pronti per
 * riprendere - nessuno stato "a meta'" nascosto.
 */
class ImportPipeline
{
    public function __construct(
        private readonly StagingImporter $stagingImporter = new StagingImporter,
        private readonly DiffEngine $diffEngine = new DiffEngine,
    ) {}

    public function run(ImportRun $importRun): ImportRun
    {
        $this->stagingImporter->run($importRun);
        $importRun->refresh();

        // StagingImporter ha gia' marcato il run come 'failed' (errore o anomalia):
        // niente da fare, il diff non ha senso su dati che non ci si fida ad usare.
        if ($importRun->status !== 'parsing') {
            return $importRun;
        }

        $importRun->forceFill(['status' => 'diffing'])->save();

        $diffResult = $this->diffEngine->diff($importRun);

        $this->logDiff($importRun, $diffResult);

        $importRun->forceFill([
            'products_created' => $diffResult->productsCreated,
            'products_updated' => $diffResult->productsUpdated,
            'products_unchanged' => $diffResult->productsUnchanged,
            'products_removed' => $diffResult->productsRemoved,
            'variants_created' => $diffResult->variantsCreated,
            'variants_updated' => $diffResult->variantsUpdated,
            'variants_removed' => $diffResult->variantsRemoved,
        ])->save();

        if ($importRun->dry_run) {
            $this->finalizeDryRun($importRun);

            return $importRun->refresh();
        }

        $this->dispatchLiveSync($importRun, $diffResult);

        return $importRun->refresh();
    }

    private function dispatchLiveSync(ImportRun $importRun, DiffResult $diffResult): void
    {
        $jobs = collect($diffResult->products)
            ->filter(fn (ProductDiff $p) => $p->action !== 'unchanged')
            ->map(fn (ProductDiff $p) => new SyncProductToShopifyJob($importRun->id, $p->codiceArticolo))
            ->all();

        if ($jobs === []) {
            $this->finalize($importRun, additionalFailures: 0);

            return;
        }

        $importRun->forceFill(['status' => 'syncing'])->save();
        $importRunId = $importRun->id;

        Bus::batch($jobs)
            ->allowFailures()
            ->name("import-run-{$importRunId}-sync")
            ->finally(function (Batch $batch) use ($importRunId) {
                $run = ImportRun::find($importRunId);
                if ($run !== null) {
                    $this->finalize($run, additionalFailures: $batch->failedJobs);
                }
            })
            ->dispatch();
    }

    private function finalize(ImportRun $importRun, int $additionalFailures): void
    {
        $importRun->forceFill([
            'status' => $additionalFailures > 0 ? 'completed_with_warnings' : 'completed',
            'products_failed' => $importRun->products_failed + $additionalFailures,
            'finished_at' => now(),
            'duration_seconds' => $importRun->started_at ? now()->diffInSeconds($importRun->started_at) : null,
        ])->save();
    }

    private function finalizeDryRun(ImportRun $importRun): void
    {
        $hasIssues = $importRun->logs()->whereIn('level', ['warning', 'error'])->exists();

        $importRun->forceFill([
            'status' => $hasIssues ? 'completed_with_warnings' : 'completed',
            'finished_at' => now(),
            'duration_seconds' => $importRun->started_at ? now()->diffInSeconds($importRun->started_at) : null,
        ])->save();
    }

    private function logDiff(ImportRun $importRun, DiffResult $diffResult): void
    {
        $rows = [];
        foreach ($diffResult->products as $productDiff) {
            if ($productDiff->action === 'unchanged') {
                continue;
            }

            $rows[] = [
                'import_run_id' => $importRun->id,
                'level' => 'info',
                'codice_articolo' => $productDiff->codiceArticolo,
                'codice_ean' => null,
                'csv_row_number' => null,
                'message' => $this->formatMessage($productDiff),
                'context' => json_encode(['field_changes' => $productDiff->fieldChanges]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_logs')->insert($chunk);
        }
    }

    private function formatMessage(ProductDiff $productDiff): string
    {
        $variantSummary = $this->summarizeVariants($productDiff);

        return match ($productDiff->action) {
            'create' => sprintf(
                'Prodotto nuovo: %s (%s)%s',
                $productDiff->title(),
                $productDiff->codiceArticolo,
                $variantSummary !== '' ? ", {$variantSummary}" : '',
            ),
            'update' => sprintf(
                'Prodotto aggiornato (%s, %s)%s%s',
                $productDiff->codiceArticolo,
                $productDiff->title(),
                $productDiff->fieldChanges !== [] ? ': '.$this->formatFieldChanges($productDiff->fieldChanges) : '',
                $variantSummary !== '' ? " [{$variantSummary}]" : '',
            ),
            'remove' => sprintf(
                'Prodotto sparito dal CSV (%s, %s): verra\' nascosto e le varianti azzerate, non eliminato.',
                $productDiff->codiceArticolo,
                $productDiff->title(),
            ),
            default => "Prodotto {$productDiff->codiceArticolo}: {$productDiff->action}",
        };
    }

    private function summarizeVariants(ProductDiff $productDiff): string
    {
        $counts = ['create' => 0, 'update' => 0, 'remove' => 0];
        foreach ($productDiff->variants as $variantDiff) {
            if (isset($counts[$variantDiff->action])) {
                $counts[$variantDiff->action]++;
            }
        }

        $parts = [];
        if ($counts['create'] > 0) {
            $parts[] = "{$counts['create']} varianti nuove";
        }
        if ($counts['update'] > 0) {
            $parts[] = "{$counts['update']} varianti aggiornate";
        }
        if ($counts['remove'] > 0) {
            $parts[] = "{$counts['remove']} varianti rimosse";
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $fieldChanges
     */
    private function formatFieldChanges(array $fieldChanges): string
    {
        $parts = [];
        foreach ($fieldChanges as $field => [$old, $new]) {
            $parts[] = sprintf('%s: "%s" -> "%s"', $field, $this->truncate($old), $this->truncate($new));
        }

        return implode(', ', $parts);
    }

    private function truncate(mixed $value): string
    {
        return Str::limit((string) $value, 60);
    }
}
