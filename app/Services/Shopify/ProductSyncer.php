<?php

namespace App\Services\Shopify;

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Diff\Dto\ProductDiff;
use App\Services\Diff\Dto\VariantDiff;
use App\Services\Shopify\Support\CategoryPathParser;
use App\Services\Shopify\Support\ShopifyGid;
use Illuminate\Support\Collection;

/**
 * Applica UN ProductDiff a Shopify (create/update/remove) e, solo dopo che la
 * chiamata e' andata a buon fine, aggiorna le tabelle canoniche products/
 * product_variants/product_images di conseguenza.
 *
 * ATTENZIONE: le mutation qui sotto sono scritte secondo lo schema Admin API
 * GraphQL documentato da Shopify, ma non sono ancora state verificate contro
 * uno store reale (nessuna credenziale disponibile in questo ambiente). Vanno
 * validate con attenzione sul development store prima di qualunque uso reale.
 */
class ProductSyncer
{
    private const OPTION_FIELDS = ['Colore' => 'color', 'Taglia' => 'size', 'Lunghezza' => 'length'];

    public function __construct(
        private readonly ShopifyGraphQLClient $client,
        private readonly LocationResolver $locations,
        private readonly CollectionResolver $collections,
    ) {}

    public function sync(ImportRun $importRun, ProductDiff $diff): void
    {
        match ($diff->action) {
            'create' => $this->create($importRun, $diff),
            'update' => $this->update($importRun, $diff),
            'remove' => $this->remove($importRun, $diff),
            default => null,
        };
    }

    private function create(ImportRun $importRun, ProductDiff $diff): void
    {
        $staging = $diff->staging;
        $collectionIds = $this->resolveCollectionIds($staging['category_path'] ?? null, $importRun);

        $input = [
            'title' => $staging['title'],
            'handle' => $staging['handle'],
            'descriptionHtml' => $staging['body_html'],
            'vendor' => $staging['vendor'],
            'status' => $this->shopifyStatus($staging['status']),
            'productOptions' => $this->buildProductOptions($diff->variants),
            'collectionsToJoin' => $collectionIds,
        ];

        $result = $this->client->call(self::PRODUCT_CREATE_MUTATION, ['input' => $input], 'productCreate', $importRun->id, $diff->codiceArticolo);
        $this->assertSuccess($result, "Creazione prodotto {$diff->codiceArticolo}");

        $shopifyProductGid = $result->data['productCreate']['product']['id'];

        $product = Product::create([
            'codice_articolo' => $diff->codiceArticolo,
            'shopify_product_id' => ShopifyGid::toNumericId($shopifyProductGid),
            'handle' => $staging['handle'],
            'title' => $staging['title'],
            'body_html' => $staging['body_html'],
            'vendor' => $staging['vendor'],
            'gender' => $staging['gender'],
            'category_path' => $staging['category_path'],
            'collection_name' => $staging['collection_name'],
            'meta_description' => $staging['meta_description'],
            'main_image_url' => $staging['main_image_url'],
            'status' => $staging['status'],
            'is_active' => true,
            'last_seen_import_run_id' => $importRun->id,
            'last_synced_at' => now(),
        ]);

        $created = array_filter($diff->variants, fn (VariantDiff $v) => $v->action !== 'remove');
        $this->createVariants($importRun, $product, $shopifyProductGid, $created);

        if ($staging['main_image_url']) {
            $this->attachImage($importRun, $product, $shopifyProductGid, $staging['main_image_url']);
        }
    }

    private function update(ImportRun $importRun, ProductDiff $diff): void
    {
        $product = Product::where('codice_articolo', $diff->codiceArticolo)->firstOrFail();
        $shopifyProductGid = ShopifyGid::toGid('Product', $diff->existing['shopify_product_id']);

        if ($diff->fieldChanges !== []) {
            $staging = $diff->staging;
            $input = ['id' => $shopifyProductGid];
            $fieldMap = ['title' => 'title', 'body_html' => 'descriptionHtml', 'vendor' => 'vendor'];

            foreach ($diff->fieldChanges as $field => $unused) {
                if ($field === 'status') {
                    $input['status'] = $this->shopifyStatus($staging['status']);
                } elseif (isset($fieldMap[$field])) {
                    $input[$fieldMap[$field]] = $staging[$field];
                }
            }

            if (array_key_exists('category_path', $diff->fieldChanges)) {
                $input['collectionsToJoin'] = $this->resolveCollectionIds($staging['category_path'] ?? null, $importRun);
            }

            $result = $this->client->call(self::PRODUCT_UPDATE_MUTATION, ['input' => $input], 'productUpdate', $importRun->id, $diff->codiceArticolo);
            $this->assertSuccess($result, "Aggiornamento prodotto {$diff->codiceArticolo}");

            $product->fill([
                'title' => $staging['title'],
                'body_html' => $staging['body_html'],
                'vendor' => $staging['vendor'],
                'gender' => $staging['gender'],
                'category_path' => $staging['category_path'],
                'collection_name' => $staging['collection_name'],
                'meta_description' => $staging['meta_description'],
                'main_image_url' => $staging['main_image_url'],
                'status' => $staging['status'],
            ]);
        }

        $this->syncVariants($importRun, $product, $shopifyProductGid, $diff->variants);

        $product->forceFill([
            'is_active' => true,
            'last_seen_import_run_id' => $importRun->id,
            'last_synced_at' => now(),
        ])->save();
    }

    private function remove(ImportRun $importRun, ProductDiff $diff): void
    {
        $product = Product::where('codice_articolo', $diff->codiceArticolo)->firstOrFail();
        $shopifyProductGid = ShopifyGid::toGid('Product', $diff->existing['shopify_product_id']);

        // Mai eliminare: solo nascondere (draft) e azzerare lo stock, come deciso.
        $result = $this->client->call(
            self::PRODUCT_UPDATE_MUTATION,
            ['input' => ['id' => $shopifyProductGid, 'status' => 'DRAFT']],
            'productUpdate',
            $importRun->id,
            $diff->codiceArticolo,
        );
        $this->assertSuccess($result, "Nascondere prodotto sparito {$diff->codiceArticolo}");

        $quantities = $this->zeroQuantitiesFor($product->variants);
        $this->setInventory($importRun, $diff->codiceArticolo, $quantities);

        $product->variants()->update([
            'quantity' => 0,
            'is_active' => false,
            'last_seen_import_run_id' => $importRun->id,
            'last_synced_at' => now(),
        ]);

        $product->forceFill([
            'status' => 'draft',
            'is_active' => false,
            'last_seen_import_run_id' => $importRun->id,
            'last_synced_at' => now(),
        ])->save();
    }

    /**
     * @param  list<VariantDiff>  $variantDiffs
     */
    private function syncVariants(ImportRun $importRun, Product $product, string $shopifyProductGid, array $variantDiffs): void
    {
        $toCreate = array_values(array_filter($variantDiffs, fn (VariantDiff $v) => $v->action === 'create'));
        $toUpdate = array_values(array_filter($variantDiffs, fn (VariantDiff $v) => $v->action === 'update'));
        $toRemove = array_values(array_filter($variantDiffs, fn (VariantDiff $v) => $v->action === 'remove'));

        if ($toCreate !== []) {
            $this->createVariants($importRun, $product, $shopifyProductGid, $toCreate);
        }

        if ($toUpdate !== []) {
            $bulkInput = array_map(fn (VariantDiff $v) => [
                'id' => ShopifyGid::toGid('ProductVariant', $v->existing['shopify_variant_id']),
                'price' => $v->staging['price'],
            ], $toUpdate);

            $result = $this->client->call(
                self::VARIANTS_BULK_UPDATE_MUTATION,
                ['productId' => $shopifyProductGid, 'variants' => $bulkInput],
                'productVariantsBulkUpdate',
                $importRun->id,
                $product->codice_articolo,
            );
            $this->assertSuccess($result, "Aggiornamento varianti {$product->codice_articolo}");

            $quantities = [];
            foreach ($toUpdate as $variantDiff) {
                $product->variants()->where('codice_ean', $variantDiff->codiceEan)->update([
                    'color' => $variantDiff->staging['color'],
                    'size' => $variantDiff->staging['size'],
                    'length' => $variantDiff->staging['length'],
                    'price' => $variantDiff->staging['price'],
                    'cost' => $variantDiff->staging['cost'],
                    'quantity' => $variantDiff->staging['quantity'],
                    'image_url' => $variantDiff->staging['image_url'],
                    'last_seen_import_run_id' => $importRun->id,
                    'last_synced_at' => now(),
                ]);

                if ($variantDiff->existing['shopify_inventory_item_id'] ?? null) {
                    $quantities[] = [
                        'inventoryItemId' => ShopifyGid::toGid('InventoryItem', $variantDiff->existing['shopify_inventory_item_id']),
                        'locationId' => $this->locations->primaryLocationId($importRun->id),
                        'quantity' => (int) $variantDiff->staging['quantity'],
                    ];
                }
            }
            $this->setInventory($importRun, $product->codice_articolo, $quantities);
        }

        if ($toRemove !== []) {
            $existingVariants = $product->variants()->whereIn('codice_ean', array_map(fn (VariantDiff $v) => $v->codiceEan, $toRemove))->get();
            $quantities = $this->zeroQuantitiesFor($existingVariants);
            $this->setInventory($importRun, $product->codice_articolo, $quantities);

            $product->variants()->whereIn('codice_ean', array_map(fn (VariantDiff $v) => $v->codiceEan, $toRemove))->update([
                'quantity' => 0,
                'is_active' => false,
                'last_seen_import_run_id' => $importRun->id,
                'last_synced_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<VariantDiff>  $variantDiffs
     */
    private function createVariants(ImportRun $importRun, Product $product, string $shopifyProductGid, array $variantDiffs): void
    {
        if ($variantDiffs === []) {
            return;
        }

        $hasLength = collect($variantDiffs)->contains(fn (VariantDiff $v) => ! empty($v->staging['length']));

        $bulkInput = array_map(fn (VariantDiff $v) => [
            'price' => $v->staging['price'],
            'barcode' => $v->codiceEan,
            'optionValues' => $this->optionValuesFor($v, $hasLength),
            'inventoryItem' => array_filter([
                'tracked' => true,
                'cost' => $v->staging['cost'],
            ], fn ($value) => $value !== null),
        ], $variantDiffs);

        $result = $this->client->call(
            self::VARIANTS_BULK_CREATE_MUTATION,
            ['productId' => $shopifyProductGid, 'variants' => $bulkInput],
            'productVariantsBulkCreate',
            $importRun->id,
            $product->codice_articolo,
        );
        $this->assertSuccess($result, "Creazione varianti {$product->codice_articolo}");

        $createdByEan = collect($result->data['productVariantsBulkCreate']['productVariants'] ?? [])->keyBy('barcode');
        $locationId = $this->locations->primaryLocationId($importRun->id);
        $quantities = [];

        foreach ($variantDiffs as $variantDiff) {
            $created = $createdByEan->get($variantDiff->codiceEan);
            if ($created === null) {
                throw new ShopifySyncException("Shopify non ha restituito la variante creata per EAN {$variantDiff->codiceEan} ({$product->codice_articolo}).");
            }

            $inventoryItemGid = $created['inventoryItem']['id'];

            $product->variants()->create([
                'shopify_variant_id' => ShopifyGid::toNumericId($created['id']),
                'shopify_inventory_item_id' => ShopifyGid::toNumericId($inventoryItemGid),
                'codice_ean' => $variantDiff->codiceEan,
                'color' => $variantDiff->staging['color'],
                'size' => $variantDiff->staging['size'],
                'length' => $variantDiff->staging['length'],
                'price' => $variantDiff->staging['price'],
                'cost' => $variantDiff->staging['cost'],
                'quantity' => $variantDiff->staging['quantity'],
                'image_url' => $variantDiff->staging['image_url'],
                'is_active' => true,
                'last_seen_import_run_id' => $importRun->id,
                'last_synced_at' => now(),
            ]);

            $quantities[] = [
                'inventoryItemId' => $inventoryItemGid,
                'locationId' => $locationId,
                'quantity' => (int) $variantDiff->staging['quantity'],
            ];
        }

        $this->setInventory($importRun, $product->codice_articolo, $quantities);
    }

    private function optionValuesFor(VariantDiff $variantDiff, bool $hasLength): array
    {
        $values = [
            ['optionName' => 'Colore', 'name' => $variantDiff->staging['color']],
            ['optionName' => 'Taglia', 'name' => $variantDiff->staging['size']],
        ];

        if ($hasLength && ! empty($variantDiff->staging['length'])) {
            $values[] = ['optionName' => 'Lunghezza', 'name' => $variantDiff->staging['length']];
        }

        return $values;
    }

    /**
     * @param  list<VariantDiff>  $variantDiffs
     */
    private function buildProductOptions(array $variantDiffs): array
    {
        $active = array_filter($variantDiffs, fn (VariantDiff $v) => $v->action !== 'remove');
        $hasLength = collect($active)->contains(fn (VariantDiff $v) => ! empty($v->staging['length']));

        $fields = $hasLength ? self::OPTION_FIELDS : array_slice(self::OPTION_FIELDS, 0, 2);

        $options = [];
        foreach ($fields as $label => $field) {
            $values = collect($active)
                ->map(fn (VariantDiff $v) => $v->staging[$field] ?? null)
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->unique()
                ->values()
                ->map(fn ($value) => ['name' => $value])
                ->all();

            if ($values !== []) {
                $options[] = ['name' => $label, 'values' => $values];
            }
        }

        return $options;
    }

    private function resolveCollectionIds(?string $categoryPath, ImportRun $importRun): array
    {
        return array_map(
            fn (string $name) => $this->collections->resolveId($name, $importRun->id),
            CategoryPathParser::collectionNames($categoryPath),
        );
    }

    private function attachImage(ImportRun $importRun, Product $product, string $shopifyProductGid, string $url): void
    {
        $result = $this->client->call(
            self::PRODUCT_CREATE_MEDIA_MUTATION,
            ['productId' => $shopifyProductGid, 'media' => [['originalSource' => $url, 'mediaContentType' => 'IMAGE']]],
            'productCreateMedia',
            $importRun->id,
            $product->codice_articolo,
        );

        // Un'immagine fallita non deve bloccare la creazione dell'intero prodotto.
        if (! $result->success) {
            return;
        }

        $media = $result->data['productCreateMedia']['media'][0] ?? null;
        if ($media !== null && isset($media['id'])) {
            $product->images()->create([
                'url' => $url,
                'shopify_media_id' => ShopifyGid::toNumericId($media['id']),
                'position' => 0,
            ]);
        }
    }

    private function setInventory(ImportRun $importRun, string $codiceArticolo, array $quantities): void
    {
        if ($quantities === []) {
            return;
        }

        $result = $this->client->call(
            self::INVENTORY_SET_QUANTITIES_MUTATION,
            ['input' => [
                'name' => 'available',
                'reason' => 'correction',
                'ignoreCompareQuantity' => true,
                'quantities' => $quantities,
            ]],
            'inventorySetQuantities',
            $importRun->id,
            $codiceArticolo,
        );
        $this->assertSuccess($result, "Impostazione inventario {$codiceArticolo}");
    }

    /**
     * @param  Collection<int, ProductVariant>|list<ProductVariant>  $variants
     */
    private function zeroQuantitiesFor(iterable $variants): array
    {
        $locationId = null;
        $quantities = [];

        foreach ($variants as $variant) {
            if (! $variant->shopify_inventory_item_id) {
                continue;
            }
            $locationId ??= $this->locations->primaryLocationId();
            $quantities[] = [
                'inventoryItemId' => ShopifyGid::toGid('InventoryItem', $variant->shopify_inventory_item_id),
                'locationId' => $locationId,
                'quantity' => 0,
            ];
        }

        return $quantities;
    }

    private function shopifyStatus(string $status): string
    {
        return $status === 'published' ? 'ACTIVE' : 'DRAFT';
    }

    private function assertSuccess(Dto\ShopifyMutationResult $result, string $context): void
    {
        if (! $result->success) {
            throw new ShopifySyncException("{$context} fallita: ".($result->firstErrorMessage() ?? 'errore Shopify sconosciuto.'));
        }
    }

    private const PRODUCT_CREATE_MUTATION = <<<'GQL'
        mutation ProductCreate($input: ProductInput!) {
            productCreate(input: $input) {
                product { id }
                userErrors { field message }
            }
        }
        GQL;

    private const PRODUCT_UPDATE_MUTATION = <<<'GQL'
        mutation ProductUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product { id }
                userErrors { field message }
            }
        }
        GQL;

    private const VARIANTS_BULK_CREATE_MUTATION = <<<'GQL'
        mutation ProductVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkCreate(productId: $productId, variants: $variants) {
                productVariants { id barcode inventoryItem { id } }
                userErrors { field message }
            }
        }
        GQL;

    private const VARIANTS_BULK_UPDATE_MUTATION = <<<'GQL'
        mutation ProductVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                productVariants { id barcode }
                userErrors { field message }
            }
        }
        GQL;

    private const INVENTORY_SET_QUANTITIES_MUTATION = <<<'GQL'
        mutation InventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup { createdAt }
                userErrors { field message code }
            }
        }
        GQL;

    private const PRODUCT_CREATE_MEDIA_MUTATION = <<<'GQL'
        mutation ProductCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
            productCreateMedia(productId: $productId, media: $media) {
                media { id }
                mediaUserErrors { field message }
            }
        }
        GQL;
}
