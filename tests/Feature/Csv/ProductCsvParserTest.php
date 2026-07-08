<?php

namespace Tests\Feature\Csv;

use App\Services\Csv\Dto\ParsedProduct;
use App\Services\Csv\Dto\ParseSummary;
use App\Services\Csv\EncodingSanitizer;
use App\Services\Csv\ProductCsvParser;
use Tests\TestCase;

class ProductCsvParserTest extends TestCase
{
    /** @var array<string, ParsedProduct> */
    private array $products = [];

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        $sanitized = sys_get_temp_dir().'/'.uniqid('sample_sanitized_', true).'.csv';

        $result = (new EncodingSanitizer)->sanitize($fixture, $sanitized);

        $summary = (new ProductCsvParser)->parse($result->path, function (ParsedProduct $product) {
            $this->products[$product->codiceArticolo] = $product;
        });

        $this->logs = $summary->logs;
        $this->summary = $summary;

        if ($result->wasModified()) {
            unlink($sanitized);
        }
    }

    private ParseSummary $summary;

    public function test_riconosce_tutti_i_gruppi_di_prodotti_del_fixture(): void
    {
        $this->assertCount(9, $this->products);
        $this->assertSame(9, $this->summary->productsParsed);
        $this->assertSame(0, $this->summary->productsFailed);
    }

    public function test_prodotto_normale_con_piu_varianti(): void
    {
        $product = $this->products['00040EM286CEF'];

        $this->assertSame('T-shirt Calvin Klein con monogramma da uomo', $product->title);
        $this->assertSame('published', $product->status);
        $this->assertSame('Calvin Klein', $product->vendor);
        $this->assertSame('Uomo', $product->gender);
        $this->assertSame('https://gestionale.eclisse.moda/uploads/img/00040EM286_CEF_main-min.jpg', $product->mainImageUrl);
        $this->assertCount(5, $product->variants);

        $sizes = array_map(fn ($v) => $v->size, $product->variants);
        $this->assertEqualsCanonicalizing(['L', 'M', 'S', 'XL', 'XS'], $sizes);
    }

    public function test_decodifica_correttamente_le_righe_in_windows_1252(): void
    {
        $product = $this->products['15327661'];

        $this->assertCount(8, $product->variants);
        $colors = array_unique(array_map(fn ($v) => $v->color, $product->variants));
        $this->assertContains('Cocoa Créme', $colors);
    }

    public function test_quantita_negativa_viene_clampata_a_zero_e_loggata(): void
    {
        $product = $this->products['000NM1966EGWT'];

        $variant = collect($product->variants)->firstWhere('size', 'M');
        $this->assertSame(0, $variant->quantity);
        $this->assertSame(-3, $variant->quantityRaw);

        $warning = collect($this->logs)->first(fn ($log) => $log->codiceEan === $variant->codiceEan && str_contains($log->message, 'negativa'));
        $this->assertNotNull($warning);
        $this->assertSame('warning', $warning->level);
    }

    public function test_varianti_duplicate_colore_taglia_vengono_consolidate_sommando_le_quantita(): void
    {
        $product = $this->products['04511 6137'];

        // 10 righe nel CSV, 2 coppie duplicate (taglia 32 e taglia 34) -> 8 varianti finali.
        $this->assertCount(8, $product->variants);

        $size32 = collect($product->variants)->firstWhere('size', '32');
        $this->assertSame('5401128633111', $size32->codiceEan); // primo EAN incontrato
        $this->assertSame(0, $size32->quantity); // 0 + 0

        $consolidationWarnings = collect($this->logs)->filter(
            fn ($log) => $log->codiceArticolo === '04511 6137' && str_contains($log->message, 'duplicata')
        );
        $this->assertCount(2, $consolidationWarnings);
    }

    public function test_categoria_prodotto_usa_la_riga_con_il_titolo_anche_se_altre_varianti_divergono(): void
    {
        $product = $this->products['00040EM286YAA'];

        // La variante XL ha CATEGORIA "Outlet Estate 26", ma la riga con il titolo (L) ha
        // "Nuova Collezione Estate 25": deve vincere quest'ultima a livello di prodotto.
        $this->assertSame('Root|Prodotti|Uomo||Root|Prodotti|Uomo|Nuova Collezione Estate 25', $product->categoryPath);
        $this->assertCount(5, $product->variants);
    }

    public function test_lunghezza_viene_preservata_come_stringa_opzione_non_come_prezzo(): void
    {
        $product = $this->products['04511-5222'];

        foreach ($product->variants as $variant) {
            $this->assertSame('32.00', $variant->length);
        }
    }

    public function test_prodotto_con_una_sola_variante(): void
    {
        $product = $this->products['000NM2399'];

        $this->assertCount(1, $product->variants);
        $this->assertSame(0, $product->variants[0]->quantity);
        $this->assertSame(-3, $product->variants[0]->quantityRaw);
    }

    public function test_gruppo_senza_titolo_usa_il_codice_articolo_come_fallback(): void
    {
        $product = $this->products['12278618'];

        $this->assertSame('12278618', $product->title);
        $this->assertCount(5, $product->variants);

        $warning = collect($this->logs)->first(
            fn ($log) => $log->codiceArticolo === '12278618' && str_contains($log->message, 'NOME PRODOTTO')
        );
        $this->assertNotNull($warning);
    }

    public function test_colore_mancante_usa_il_placeholder_standard(): void
    {
        $product = $this->products['15228232'];

        $this->assertCount(2, $product->variants);

        $withPlaceholder = collect($product->variants)->firstWhere('size', 'm');
        $this->assertSame('Standard', $withPlaceholder->color);

        $warning = collect($this->logs)->first(
            fn ($log) => $log->codiceArticolo === '15228232' && str_contains($log->message, 'COLORE mancante')
        );
        $this->assertNotNull($warning);
    }

    public function test_ogni_variante_ha_un_content_hash_calcolato(): void
    {
        foreach ($this->products as $product) {
            $this->assertNotEmpty($product->contentHash);
            foreach ($product->variants as $variant) {
                $this->assertNotEmpty($variant->contentHash);
            }
        }
    }
}
