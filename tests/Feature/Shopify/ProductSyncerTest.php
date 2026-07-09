<?php

namespace Tests\Feature\Shopify;

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\StagingProduct;
use App\Services\Csv\CsvDownloader;
use App\Services\Diff\DiffEngine;
use App\Services\Import\StagingImporter;
use App\Services\Shopify\CollectionResolver;
use App\Services\Shopify\LocationResolver;
use App\Services\Shopify\ProductSyncer;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica ProductSyncer contro risposte che RIPRODUCONO la forma
 * documentata delle mutation Shopify (via Http::fake): non c'e' ancora uno
 * store reale collegato, quindi questi test validano la logica del nostro
 * codice (mapping dei campi, upsert canonico dopo il successo, gestione
 * errori) non la sintassi esatta accettata da un vero endpoint Shopify.
 */
class ProductSyncerTest extends TestCase
{
    use RefreshDatabase;

    private ImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        Http::fake(['gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200)]);

        $this->run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter(downloader: new CsvDownloader(retries: 1, retryDelayMs: 0)))->run($this->run);
    }

    private function operationName(Request $request): ?string
    {
        $query = $request->data()['query'] ?? '';
        preg_match('/(?:query|mutation)\s+(\w+)/', $query, $matches);

        return $matches[1] ?? null;
    }

    private function syncer(): ProductSyncer
    {
        // Dominio/token fittizi passati esplicitamente al costruttore, non via
        // config(): cosi' questo test non puo' MAI colpire un negozio Shopify
        // reale, a prescindere da cosa contiene il .env di chi lo esegue.
        // (config() + Http::fake avrebbe dovuto bastare, ma un giro di test
        // lanciato con delle credenziali vere nel .env ha comunque creato 9
        // prodotti reali sul dev store prima che phpunit.xml azzerasse le
        // variabili SHOPIFY_* per tutta la suite: doppia sicurezza da qui in poi.)
        $client = new ShopifyGraphQLClient(
            storeDomain: 'test-store.myshopify.com',
            accessToken: 'shpat_test',
            apiVersion: '2025-01',
            retryDelayMs: 0,
        );

        return new ProductSyncer($client, new LocationResolver($client), new CollectionResolver($client));
    }

    /**
     * Simula uno store Shopify vuoto: nessuna collezione esistente ancora,
     * quindi "Uomo" e "Nuova Collezione Estate 25" verranno entrambe create.
     */
    private function fakeSuccessfulShopifyResponses(): void
    {
        Http::fake(function (Request $request) {
            $operation = $this->operationName($request);
            $variables = $request->data()['variables'] ?? [];

            return match ($operation) {
                'FindCollection' => Http::response(['data' => ['collections' => ['nodes' => []]]], 200),
                'CreateCollection' => Http::response([
                    'data' => ['collectionCreate' => [
                        'collection' => ['id' => 'gid://shopify/Collection/'.crc32($variables['input']['title'])],
                        'userErrors' => [],
                    ]],
                ], 200),
                // Shopify crea un prodotto con opzioni gia' con UNA variante di
                // default (combinazione del primo valore di ogni opzione): il fake
                // la riproduce cosi' i test esercitano davvero il percorso di
                // "claim" invece di quello (piu' semplice, e sbagliato) senza.
                'ProductCreate' => Http::response([
                    'data' => ['productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/500',
                            'variants' => ['nodes' => [[
                                'id' => 'gid://shopify/ProductVariant/599',
                                'selectedOptions' => collect($variables['input']['productOptions'] ?? [])
                                    ->map(fn ($opt) => ['name' => $opt['name'], 'value' => $opt['values'][0]['name']])
                                    ->all(),
                                'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/699'],
                            ]]],
                        ],
                        'userErrors' => [],
                    ]],
                ], 200),
                'ProductUpdate' => Http::response([
                    'data' => ['productUpdate' => ['product' => ['id' => $variables['input']['id']], 'userErrors' => []]],
                ], 200),
                'ProductVariantsBulkCreate' => Http::response([
                    'data' => ['productVariantsBulkCreate' => [
                        'productVariants' => collect($variables['variants'])->map(fn ($v, $i) => [
                            'id' => 'gid://shopify/ProductVariant/'.(600 + $i),
                            'barcode' => $v['barcode'],
                            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/'.(700 + $i)],
                        ])->values()->all(),
                        'userErrors' => [],
                    ]],
                ], 200),
                'ProductVariantsBulkUpdate' => Http::response([
                    'data' => ['productVariantsBulkUpdate' => ['productVariants' => [], 'userErrors' => []]],
                ], 200),
                'InventorySetQuantities' => Http::response([
                    'data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]],
                ], 200),
                'ProductCreateMedia' => Http::response([
                    'data' => ['productCreateMedia' => ['media' => [['id' => 'gid://shopify/MediaImage/900']], 'mediaUserErrors' => []]],
                ], 200),
                'PrimaryLocation' => Http::response([
                    'data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/10', 'name' => 'Sede principale']]]],
                ], 200),
                default => Http::response(['errors' => [['message' => "Operazione non prevista dal fake: {$operation}"]]], 500),
            };
        });
    }

    public function test_crea_un_prodotto_nuovo_con_varianti_inventario_e_immagine(): void
    {
        $this->fakeSuccessfulShopifyResponses();

        $diff = (new DiffEngine)->diffSingleProduct($this->run->id, '00040EM286CEF');
        $this->syncer()->sync($this->run, $diff);

        $product = Product::where('codice_articolo', '00040EM286CEF')->firstOrFail();
        $this->assertSame(500, $product->shopify_product_id);
        $this->assertSame('published', $product->status);
        $this->assertTrue($product->is_active);
        $this->assertSame($this->run->id, $product->last_seen_import_run_id);

        $this->assertCount(5, $product->variants);
        $variant = $product->variants()->where('codice_ean', '8720639845873')->firstOrFail();
        $this->assertNotNull($variant->shopify_variant_id);
        $this->assertNotNull($variant->shopify_inventory_item_id);
        $this->assertSame('L', $variant->size);

        $this->assertCount(1, $product->images);
        $this->assertSame(900, $product->images->first()->shopify_media_id);

        $this->assertDatabaseHas('shopify_api_logs', ['operation_name' => 'productCreate', 'success' => true]);
        $this->assertDatabaseHas('shopify_api_logs', ['operation_name' => 'inventorySetQuantities', 'success' => true]);
    }

    /**
     * '15228232' nel fixture ha 2 varianti: una con LUNGHEZZA valorizzata (quella
     * "di default" auto-creata da Shopify insieme al prodotto) e una senza. Se
     * "hasLength" non viene propagato correttamente alla seconda variante (creata
     * separatamente via bulkCreate), Shopify la rifiuta perche' il prodotto ha
     * gia' dichiarato "Lunghezza" come opzione ma quella variante non le da' un
     * valore - il bug reale trovato testando contro il dev store.
     */
    public function test_una_variante_senza_lunghezza_riceve_comunque_un_valore_placeholder_per_lopzione(): void
    {
        $this->fakeSuccessfulShopifyResponses();

        $diff = (new DiffEngine)->diffSingleProduct($this->run->id, '15228232');
        $this->assertCount(2, $diff->variants);

        $this->syncer()->sync($this->run, $diff);

        $product = Product::where('codice_articolo', '15228232')->firstOrFail();
        $this->assertCount(2, $product->variants);

        Http::assertSent(function (Request $request) {
            if ($this->operationName($request) !== 'ProductCreate') {
                return true;
            }

            $options = collect($request->data()['variables']['input']['productOptions']);
            $lunghezza = $options->firstWhere('name', 'Lunghezza');

            return $lunghezza !== null
                && collect($lunghezza['values'])->pluck('name')->all() === ['32.00', 'Standard'];
        });

        Http::assertSent(function (Request $request) {
            if ($this->operationName($request) !== 'ProductVariantsBulkCreate') {
                return true;
            }

            $variant = collect($request->data()['variables']['variants'])->first();
            $lunghezza = collect($variant['optionValues'])->firstWhere('optionName', 'Lunghezza');

            return $lunghezza !== null && $lunghezza['name'] === 'Standard';
        });
    }

    public function test_un_prodotto_creato_fallisce_e_non_scrive_nulla_in_canonico(): void
    {
        Http::fake(function (Request $request) {
            $operation = $this->operationName($request);
            if ($operation === 'FindCollection') {
                return Http::response(['data' => ['collections' => ['nodes' => []]]], 200);
            }
            if ($operation === 'CreateCollection') {
                return Http::response(['data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/1'], 'userErrors' => []]]], 200);
            }

            // productCreate fallisce con un errore applicativo.
            return Http::response([
                'data' => ['productCreate' => ['product' => null, 'userErrors' => [['field' => ['title'], 'message' => 'Titolo gia\' in uso']]]],
            ], 200);
        });

        $diff = (new DiffEngine)->diffSingleProduct($this->run->id, '00040EM286CEF');

        $this->expectExceptionMessage('Titolo gia\' in uso');
        $this->syncer()->sync($this->run, $diff);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_aggiorna_un_prodotto_esistente_e_ne_riflette_i_cambi_in_canonico(): void
    {
        $this->fakeSuccessfulShopifyResponses();

        $staging = StagingProduct::where('import_run_id', $this->run->id)->where('codice_articolo', '00040EM286CEF')->with('variants')->firstOrFail();

        $product = Product::create([
            'codice_articolo' => $staging->codice_articolo,
            'shopify_product_id' => 12345,
            'handle' => $staging->handle,
            'title' => 'Titolo vecchio da aggiornare',
            'vendor' => $staging->vendor,
            'status' => $staging->status,
        ]);
        foreach ($staging->variants as $i => $sv) {
            $product->variants()->create([
                'shopify_variant_id' => 100 + $i,
                'shopify_inventory_item_id' => 200 + $i,
                'codice_ean' => $sv->codice_ean,
                'color' => $sv->color,
                'size' => $sv->size,
                'price' => $sv->price,
                'quantity' => $sv->quantity,
                'position' => $sv->position,
            ]);
        }

        $diff = (new DiffEngine)->diffSingleProduct($this->run->id, '00040EM286CEF');
        $this->assertSame('update', $diff->action);

        $this->syncer()->sync($this->run, $diff);

        $product->refresh();
        $this->assertSame('T-shirt Calvin Klein con monogramma da uomo', $product->title);
        $this->assertSame(12345, $product->shopify_product_id); // l'id Shopify non cambia in un update

        $this->assertDatabaseHas('shopify_api_logs', ['operation_name' => 'productUpdate', 'success' => true]);
    }

    public function test_rimuove_un_prodotto_sparito_dal_csv_senza_eliminarlo(): void
    {
        $this->fakeSuccessfulShopifyResponses();

        $product = Product::create([
            'codice_articolo' => 'ARTICOLO-ESTINTO-999',
            'shopify_product_id' => 777,
            'handle' => 'articolo-estinto-999',
            'title' => 'Prodotto non piu\' nel gestionale',
            'status' => 'published',
            'is_active' => true,
        ]);
        $product->variants()->create([
            'shopify_variant_id' => 888,
            'shopify_inventory_item_id' => 999,
            'codice_ean' => '1112223334445',
            'color' => 'Nero',
            'size' => 'M',
            'price' => '19.90',
            'quantity' => 4,
            'is_active' => true,
        ]);

        $diff = (new DiffEngine)->diffSingleProduct($this->run->id, 'ARTICOLO-ESTINTO-999');
        $this->assertSame('remove', $diff->action);

        $this->syncer()->sync($this->run, $diff);

        $product->refresh();
        $this->assertSame('draft', $product->status);
        $this->assertFalse($product->is_active);
        // Mai eliminato: il record e le sue varianti restano nel DB.
        $this->assertDatabaseHas('products', ['codice_articolo' => 'ARTICOLO-ESTINTO-999']);

        $variant = $product->variants()->first();
        $this->assertSame(0, $variant->quantity);
        $this->assertFalse($variant->is_active);

        $this->assertDatabaseHas('shopify_api_logs', ['operation_name' => 'inventorySetQuantities', 'success' => true]);
    }
}
