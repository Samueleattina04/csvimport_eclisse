<?php

namespace App\Services\Snapshot;

use App\Models\ImportRun;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Congela lo stato canonico (products/product_variants) a fine di ogni run
 * live andato a buon fine, legandolo all'ImportRun appena concluso. E' la
 * base del rollback: per tornare indietro non si rilancia un vecchio CSV
 * (che nel frattempo puo' essere cambiato o sparito), si confronta lo stato
 * attuale con uno di questi scatti e si applica la differenza, con le stesse
 * garanzie della sync normale (mai eliminare, solo nascondere/azzerare).
 */
class SnapshotService
{
    public function snapshot(ImportRun $importRun): void
    {
        Product::query()->with('variants')->chunkById(200, function ($products) use ($importRun) {
            foreach ($products as $product) {
                $snapshotProductId = DB::table('import_snapshot_products')->insertGetId([
                    'import_run_id' => $importRun->id,
                    'codice_articolo' => $product->codice_articolo,
                    'shopify_product_id' => $product->shopify_product_id,
                    'handle' => $product->handle,
                    'title' => $product->title,
                    'body_html' => $product->body_html,
                    'vendor' => $product->vendor,
                    'gender' => $product->gender,
                    'category_path' => $product->category_path,
                    'collection_name' => $product->collection_name,
                    'meta_description' => $product->meta_description,
                    'main_image_url' => $product->main_image_url,
                    'status' => $product->status,
                    'is_active' => $product->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($product->variants->isEmpty()) {
                    continue;
                }

                $variantRows = $product->variants->map(fn ($variant) => [
                    'import_snapshot_product_id' => $snapshotProductId,
                    'shopify_variant_id' => $variant->shopify_variant_id,
                    'codice_ean' => $variant->codice_ean,
                    'color' => $variant->color,
                    'size' => $variant->size,
                    'length' => $variant->length,
                    'price' => $variant->price,
                    'cost' => $variant->cost,
                    'quantity' => $variant->quantity,
                    'image_url' => $variant->image_url,
                    'position' => $variant->position,
                    'is_active' => $variant->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                DB::table('import_snapshot_variants')->insert($variantRows);
            }
        });
    }
}
