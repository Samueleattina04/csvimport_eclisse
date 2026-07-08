<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Services\Diff\DiffEngine;
use App\Services\Diff\Dto\DiffResult;
use App\Services\Diff\Dto\ProductDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mette in sequenza le fasi della sync: staging (fetch+parsing) e poi diff.
 * Si ferma qui di proposito: applicare il diff a Shopify e' la fase
 * successiva, non ancora costruita. A fine corsa senza anomalie il run resta
 * in stato "diffing", con il piano gia' calcolato pronto per essere applicato.
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

        return $importRun->refresh();
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
