<?php

namespace Tests\Feature\Diff;

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\StagingProduct;
use App\Services\Csv\CsvDownloader;
use App\Services\Diff\DiffEngine;
use App\Services\Import\StagingImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le combinazioni di stato "canonico" (create/update/remove/unchanged) sono
 * seedate a mano a partire dai valori REALI scritti in staging dallo
 * StagingImporter durante il setUp, cosi' i test restano ancorati ai dati
 * veri del fixture invece che a valori trascritti a mano.
 */
class DiffEngineTest extends TestCase
{
    use RefreshDatabase;

    private ImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200),
        ]);

        $this->run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter(downloader: new CsvDownloader(retries: 1, retryDelayMs: 0)))->run($this->run);
    }

    private function stagingProduct(string $codiceArticolo): StagingProduct
    {
        return StagingProduct::where('import_run_id', $this->run->id)
            ->where('codice_articolo', $codiceArticolo)
            ->firstOrFail();
    }

    private function seedCanonicalFromStaging(StagingProduct $staging, array $productOverrides = []): Product
    {
        $product = Product::create(array_merge([
            'codice_articolo' => $staging->codice_articolo,
            'shopify_product_id' => random_int(1000, 999999),
            'handle' => $staging->handle,
            'title' => $staging->title,
            'body_html' => $staging->body_html,
            'vendor' => $staging->vendor,
            'gender' => $staging->gender,
            'category_path' => $staging->category_path,
            'collection_name' => $staging->collection_name,
            'meta_description' => $staging->meta_description,
            'main_image_url' => $staging->main_image_url,
            'status' => $staging->status,
        ], $productOverrides));

        foreach ($staging->variants as $stagingVariant) {
            $product->variants()->create([
                'shopify_variant_id' => random_int(1000, 999999),
                'codice_ean' => $stagingVariant->codice_ean,
                'color' => $stagingVariant->color,
                'size' => $stagingVariant->size,
                'length' => $stagingVariant->length,
                'price' => $stagingVariant->price,
                'cost' => $stagingVariant->cost,
                'quantity' => $stagingVariant->quantity,
                'image_url' => $stagingVariant->image_url,
                'position' => $stagingVariant->position,
            ]);
        }

        return $product;
    }

    public function test_senza_nessun_prodotto_canonico_tutto_e_nuovo(): void
    {
        $result = (new DiffEngine)->diff($this->run);

        $this->assertSame(9, $result->productsCreated);
        $this->assertSame(0, $result->productsUpdated);
        $this->assertSame(0, $result->productsRemoved);
        $this->assertSame(0, $result->productsUnchanged);
        $this->assertSame(40, $result->variantsCreated);

        $created = collect($result->products)->firstWhere('codiceArticolo', '00040EM286CEF');
        $this->assertSame('create', $created->action);
        $this->assertNull($created->existing);
        $this->assertCount(5, $created->variants);
    }

    public function test_prodotto_identico_al_canonico_risulta_invariato(): void
    {
        $staging = $this->stagingProduct('000NM2399');
        $this->seedCanonicalFromStaging($staging);

        $result = (new DiffEngine)->diff($this->run);

        $diff = collect($result->products)->firstWhere('codiceArticolo', '000NM2399');
        $this->assertSame('unchanged', $diff->action);
        $this->assertSame([], $diff->fieldChanges);
        $this->assertSame('unchanged', $diff->variants[0]->action);
    }

    public function test_un_campo_prodotto_diverso_genera_un_update_con_i_valori_vecchionuovo(): void
    {
        $staging = $this->stagingProduct('00040EM286CEF');
        $this->seedCanonicalFromStaging($staging, ['title' => 'Vecchio titolo da aggiornare']);

        $result = (new DiffEngine)->diff($this->run);

        $diff = collect($result->products)->firstWhere('codiceArticolo', '00040EM286CEF');
        $this->assertSame('update', $diff->action);
        $this->assertArrayHasKey('title', $diff->fieldChanges);
        $this->assertSame('Vecchio titolo da aggiornare', $diff->fieldChanges['title'][0]);
        $this->assertSame('T-shirt Calvin Klein con monogramma da uomo', $diff->fieldChanges['title'][1]);
    }

    public function test_un_prezzo_variante_diverso_genera_un_update_a_livello_di_variante(): void
    {
        $staging = $this->stagingProduct('00040EM286CEF');
        $product = $this->seedCanonicalFromStaging($staging);
        $product->variants()->where('codice_ean', $staging->variants[0]->codice_ean)->update(['price' => '999.00']);

        $result = (new DiffEngine)->diff($this->run);

        $diff = collect($result->products)->firstWhere('codiceArticolo', '00040EM286CEF');
        $this->assertSame('update', $diff->action);
        $this->assertSame([], $diff->fieldChanges); // nessun campo PRODOTTO e' cambiato, solo la variante

        $variantDiff = collect($diff->variants)->firstWhere('codiceEan', $staging->variants[0]->codice_ean);
        $this->assertSame('update', $variantDiff->action);
        $this->assertSame(['999.00', $staging->variants[0]->price], $variantDiff->fieldChanges['price']);
    }

    public function test_un_prodotto_canonico_assente_dal_nuovo_csv_viene_marcato_come_rimosso(): void
    {
        $extinct = Product::create([
            'codice_articolo' => 'ARTICOLO-ESTINTO-001',
            'handle' => 'articolo-estinto-001',
            'title' => 'Prodotto non piu\' nel gestionale',
            'status' => 'published',
        ]);
        $extinct->variants()->create([
            'codice_ean' => '0000000000000',
            'color' => 'Nero',
            'size' => 'M',
            'price' => '19.90',
            'quantity' => 3,
        ]);

        $result = (new DiffEngine)->diff($this->run);

        $this->assertSame(1, $result->productsRemoved);
        $diff = collect($result->products)->firstWhere('codiceArticolo', 'ARTICOLO-ESTINTO-001');
        $this->assertSame('remove', $diff->action);
        $this->assertNull($diff->staging);
        $this->assertCount(1, $diff->variants);
        $this->assertSame('remove', $diff->variants[0]->action);
        $this->assertSame('0000000000000', $diff->variants[0]->codiceEan);
    }

    public function test_una_variante_sparita_ma_il_prodotto_resta_genera_update_col_prodotto_e_remove_sulla_variante(): void
    {
        $staging = $this->stagingProduct('00040EM286CEF'); // 5 varianti nel fixture
        $product = $this->seedCanonicalFromStaging($staging);

        // Una taglia in piu' rispetto al nuovo CSV: discontinuata.
        $product->variants()->create([
            'codice_ean' => '9999999999999',
            'color' => 'DARK SAPPHIRE',
            'size' => 'XXL',
            'price' => '39.90',
            'quantity' => 2,
        ]);

        $result = (new DiffEngine)->diff($this->run);

        $diff = collect($result->products)->firstWhere('codiceArticolo', '00040EM286CEF');
        $this->assertSame('update', $diff->action);
        $this->assertSame([], $diff->fieldChanges);

        $removedVariant = collect($diff->variants)->firstWhere('codiceEan', '9999999999999');
        $this->assertSame('remove', $removedVariant->action);

        $others = collect($diff->variants)->reject(fn ($v) => $v->codiceEan === '9999999999999');
        $this->assertTrue($others->every(fn ($v) => $v->action === 'unchanged'));
    }

    public function test_lhandle_di_un_prodotto_esistente_non_viene_mai_toccato(): void
    {
        $staging = $this->stagingProduct('00040EM286CEF');
        $this->seedCanonicalFromStaging($staging, ['handle' => 'handle-storico-diverso']);

        $result = (new DiffEngine)->diff($this->run);

        $diff = collect($result->products)->firstWhere('codiceArticolo', '00040EM286CEF');
        // Anche se lo staging calcolerebbe un handle diverso, non e' tra i campi confrontati/aggiornabili.
        $this->assertArrayNotHasKey('handle', $diff->fieldChanges);
    }
}
