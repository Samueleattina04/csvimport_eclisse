<?php

namespace App\Services\Diff;

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\StagingProduct;
use App\Models\StagingVariant;
use App\Services\Diff\Dto\DiffResult;
use App\Services\Diff\Dto\ProductDiff;
use App\Services\Diff\Dto\VariantDiff;
use Illuminate\Support\Collection;

/**
 * Confronta lo stato appena scaricato (staging_products/staging_variants di un
 * run) con lo stato canonico attuale (products/product_variants, cio' che
 * risulta essere davvero su Shopify dopo l'ultimo sync riuscito) e produce un
 * piano di modifiche. Non scrive nulla: e' la fase di applicazione a Shopify
 * (prossima, non ancora costruita) che consumera' questo risultato e, solo
 * dopo una chiamata riuscita, aggiornera' le tabelle canoniche.
 *
 * Nota sull'handle: una volta assegnato a un prodotto esistente non viene mai
 * ricalcolato/aggiornato, anche se l'HandleResolver di un run successivo
 * produrrebbe uno slug diverso (puo' succedere nei rari casi di collisione,
 * es. "56605 0000" vs "56605-0000", se il secondo arriva in un run futuro).
 * Cambiare l'handle di un prodotto gia' pubblicato rompe URL pubblici/SEO su
 * Shopify, quindi resta quello assegnato alla creazione.
 */
class DiffEngine
{
    private const PRODUCT_FIELDS = [
        'title', 'body_html', 'vendor', 'gender', 'category_path',
        'collection_name', 'meta_description', 'main_image_url', 'status',
    ];

    private const VARIANT_FIELDS = ['color', 'size', 'length', 'price', 'cost', 'quantity', 'image_url'];

    public function diff(ImportRun $importRun): DiffResult
    {
        $stagingProducts = StagingProduct::query()
            ->where('import_run_id', $importRun->id)
            ->get();

        $stagingVariantsByProduct = StagingVariant::query()
            ->join('staging_products', 'staging_products.id', '=', 'staging_variants.staging_product_id')
            ->where('staging_products.import_run_id', $importRun->id)
            ->select('staging_variants.*')
            ->get()
            ->groupBy('staging_product_id');

        $existingProducts = Product::query()->with('variants')->get()->keyBy('codice_articolo');

        $productDiffs = [];
        $seenCodici = [];

        foreach ($stagingProducts as $stagingProduct) {
            $seenCodici[$stagingProduct->codice_articolo] = true;
            $existingProduct = $existingProducts->get($stagingProduct->codice_articolo);
            $stagingVariants = $stagingVariantsByProduct->get($stagingProduct->id, collect());

            $productDiffs[] = $this->diffProduct($stagingProduct, $stagingVariants, $existingProduct);
        }

        foreach ($existingProducts as $codiceArticolo => $existingProduct) {
            if (isset($seenCodici[$codiceArticolo])) {
                continue;
            }

            $productDiffs[] = $this->diffRemovedProduct($existingProduct);
        }

        return $this->summarize($productDiffs);
    }

    /**
     * Ricalcola il diff per UN SOLO prodotto, senza caricare l'intero catalogo.
     * Usato dai job di sync per rileggere dati freschi al momento dell'esecuzione
     * invece di far viaggiare l'intero DiffResult nel payload della coda.
     */
    public function diffSingleProduct(int $importRunId, string $codiceArticolo): ?ProductDiff
    {
        $stagingProduct = StagingProduct::query()
            ->where('import_run_id', $importRunId)
            ->where('codice_articolo', $codiceArticolo)
            ->with('variants')
            ->first();

        $existingProduct = Product::query()
            ->where('codice_articolo', $codiceArticolo)
            ->with('variants')
            ->first();

        if ($stagingProduct === null && $existingProduct === null) {
            return null;
        }

        if ($stagingProduct === null) {
            return $this->diffRemovedProduct($existingProduct);
        }

        return $this->diffProduct($stagingProduct, $stagingProduct->variants, $existingProduct);
    }

    /**
     * @param  Collection<int, StagingVariant>  $stagingVariants
     */
    private function diffProduct(StagingProduct $stagingProduct, $stagingVariants, ?Product $existingProduct): ProductDiff
    {
        $stagingData = $this->normalizeStagingProduct($stagingProduct);
        $existingVariantsByEan = $existingProduct ? $existingProduct->variants->keyBy('codice_ean') : collect();

        $variantDiffs = [];
        $seenEans = [];
        foreach ($stagingVariants as $stagingVariant) {
            $seenEans[$stagingVariant->codice_ean] = true;
            $variantDiffs[] = $this->diffVariant($stagingVariant, $existingVariantsByEan->get($stagingVariant->codice_ean));
        }
        foreach ($existingVariantsByEan as $ean => $existingVariant) {
            if (! isset($seenEans[$ean])) {
                $variantDiffs[] = $this->diffRemovedVariant($existingVariant);
            }
        }

        if ($existingProduct === null) {
            return new ProductDiff('create', $stagingProduct->codice_articolo, [], $stagingData, null, $variantDiffs);
        }

        $existingData = $this->normalizeExistingProduct($existingProduct);
        $fieldChanges = $this->compareFields($stagingData, $existingData, self::PRODUCT_FIELDS);

        $hasVariantChanges = collect($variantDiffs)->contains(fn (VariantDiff $v) => $v->action !== 'unchanged');
        $action = ($fieldChanges !== [] || $hasVariantChanges) ? 'update' : 'unchanged';

        return new ProductDiff($action, $stagingProduct->codice_articolo, $fieldChanges, $stagingData, $existingData, $variantDiffs);
    }

    private function diffRemovedProduct(Product $existingProduct): ProductDiff
    {
        $existingData = $this->normalizeExistingProduct($existingProduct);

        $variantDiffs = $existingProduct->variants
            ->map(fn ($variant) => $this->diffRemovedVariant($variant))
            ->all();

        return new ProductDiff('remove', $existingProduct->codice_articolo, [], null, $existingData, $variantDiffs);
    }

    private function diffVariant(StagingVariant $stagingVariant, $existingVariant): VariantDiff
    {
        $stagingData = $this->normalizeStagingVariant($stagingVariant);

        if ($existingVariant === null) {
            return new VariantDiff('create', $stagingVariant->codice_ean, [], $stagingData, null);
        }

        $existingData = $this->normalizeExistingVariant($existingVariant);
        $fieldChanges = $this->compareFields($stagingData, $existingData, self::VARIANT_FIELDS);
        $action = $fieldChanges !== [] ? 'update' : 'unchanged';

        return new VariantDiff($action, $stagingVariant->codice_ean, $fieldChanges, $stagingData, $existingData);
    }

    private function diffRemovedVariant($existingVariant): VariantDiff
    {
        return new VariantDiff('remove', $existingVariant->codice_ean, [], null, $this->normalizeExistingVariant($existingVariant));
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function compareFields(array $staging, array $existing, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $existing[$field] ?? null;
            $new = $staging[$field] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    private function normalizeStagingProduct(StagingProduct $p): array
    {
        return [
            'handle' => $p->handle,
            'title' => $p->title,
            'body_html' => $p->body_html,
            'vendor' => $p->vendor,
            'gender' => $p->gender,
            'category_path' => $p->category_path,
            'collection_name' => $p->collection_name,
            'meta_description' => $p->meta_description,
            'main_image_url' => $p->main_image_url,
            'status' => $p->status,
        ];
    }

    private function normalizeExistingProduct(Product $p): array
    {
        return [
            'handle' => $p->handle,
            'shopify_product_id' => $p->shopify_product_id,
            'title' => $p->title,
            'body_html' => $p->body_html,
            'vendor' => $p->vendor,
            'gender' => $p->gender,
            'category_path' => $p->category_path,
            'collection_name' => $p->collection_name,
            'meta_description' => $p->meta_description,
            'main_image_url' => $p->main_image_url,
            'status' => $p->status,
        ];
    }

    private function normalizeStagingVariant(StagingVariant $v): array
    {
        return [
            'color' => $v->color,
            'size' => $v->size,
            'length' => $v->length,
            'price' => $v->price,
            'cost' => $v->cost,
            'quantity' => $v->quantity,
            'image_url' => $v->image_url,
        ];
    }

    private function normalizeExistingVariant($v): array
    {
        return [
            'shopify_variant_id' => $v->shopify_variant_id,
            'shopify_inventory_item_id' => $v->shopify_inventory_item_id,
            'color' => $v->color,
            'size' => $v->size,
            'length' => $v->length,
            'price' => $v->price,
            'cost' => $v->cost,
            'quantity' => $v->quantity,
            'image_url' => $v->image_url,
        ];
    }

    /**
     * @param  list<ProductDiff>  $productDiffs
     */
    private function summarize(array $productDiffs): DiffResult
    {
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'remove' => 0];
        $variantCounts = ['create' => 0, 'update' => 0, 'remove' => 0];

        foreach ($productDiffs as $productDiff) {
            $counts[$productDiff->action]++;
            foreach ($productDiff->variants as $variantDiff) {
                if (isset($variantCounts[$variantDiff->action])) {
                    $variantCounts[$variantDiff->action]++;
                }
            }
        }

        return new DiffResult(
            products: $productDiffs,
            productsCreated: $counts['create'],
            productsUpdated: $counts['update'],
            productsUnchanged: $counts['unchanged'],
            productsRemoved: $counts['remove'],
            variantsCreated: $variantCounts['create'],
            variantsUpdated: $variantCounts['update'],
            variantsRemoved: $variantCounts['remove'],
        );
    }
}
