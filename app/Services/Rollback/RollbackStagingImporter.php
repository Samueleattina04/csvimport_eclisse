<?php

namespace App\Services\Rollback;

use App\Models\ImportRun;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente di StagingImporter per un rollback: invece di scaricare e
 * parsare un CSV, popola le tabelle di staging copiando lo scatto
 * (import_snapshot_products/variants) del run target. Da questo punto in poi
 * il rollback e' indistinguibile da una sync normale per il resto della
 * pipeline (ImportPipeline::continueAfterStaging): stesso diff engine, stessa
 * applicazione a Shopify, stesse garanzie (mai eliminare).
 */
class RollbackStagingImporter
{
    public function run(ImportRun $importRun, ImportRun $targetRun): ImportRun
    {
        $importRun->forceFill(['started_at' => now()])->save();

        $snapshotProducts = DB::table('import_snapshot_products')
            ->where('import_run_id', $targetRun->id)
            ->get();

        if ($snapshotProducts->isEmpty()) {
            $this->fail($importRun, "L'import #{$targetRun->id} non ha uno scatto (snapshot) disponibile: rollback impossibile.");

            return $importRun->refresh();
        }

        $totalVariants = 0;

        DB::transaction(function () use ($importRun, $snapshotProducts, &$totalVariants) {
            foreach ($snapshotProducts as $snapshotProduct) {
                $stagingProductId = DB::table('staging_products')->insertGetId([
                    'import_run_id' => $importRun->id,
                    'codice_articolo' => $snapshotProduct->codice_articolo,
                    'handle' => $snapshotProduct->handle,
                    'title' => $snapshotProduct->title,
                    'body_html' => $snapshotProduct->body_html,
                    'vendor' => $snapshotProduct->vendor,
                    'gender' => $snapshotProduct->gender,
                    'category_path' => $snapshotProduct->category_path,
                    'collection_name' => $snapshotProduct->collection_name,
                    'meta_description' => $snapshotProduct->meta_description,
                    'main_image_url' => $snapshotProduct->main_image_url,
                    'visibility_raw' => null,
                    'status' => $snapshotProduct->status,
                    'content_hash' => hash('sha256', json_encode($snapshotProduct)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $snapshotVariants = DB::table('import_snapshot_variants')
                    ->where('import_snapshot_product_id', $snapshotProduct->id)
                    ->get();

                if ($snapshotVariants->isEmpty()) {
                    continue;
                }

                $variantRows = $snapshotVariants->map(function ($snapshotVariant) use ($stagingProductId) {
                    return [
                        'staging_product_id' => $stagingProductId,
                        'codice_ean' => $snapshotVariant->codice_ean,
                        'color' => $snapshotVariant->color,
                        'size' => $snapshotVariant->size,
                        'length' => $snapshotVariant->length,
                        'price' => $snapshotVariant->price,
                        'cost' => $snapshotVariant->cost,
                        'quantity' => $snapshotVariant->quantity,
                        'quantity_raw' => $snapshotVariant->quantity,
                        'image_url' => $snapshotVariant->image_url,
                        'position' => $snapshotVariant->position,
                        'content_hash' => hash('sha256', json_encode($snapshotVariant)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();

                DB::table('staging_variants')->insert($variantRows);
                $totalVariants += count($variantRows);
            }
        });

        $importRun->forceFill([
            'csv_row_count' => $totalVariants,
            'csv_product_count' => $snapshotProducts->count(),
            'status' => 'parsing',
        ])->save();

        return $importRun->refresh();
    }

    private function fail(ImportRun $importRun, string $message): void
    {
        $importRun->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
            'duration_seconds' => $importRun->secondsSinceStart(),
        ])->save();

        $importRun->logs()->create([
            'level' => 'error',
            'message' => $message,
        ]);
    }
}
