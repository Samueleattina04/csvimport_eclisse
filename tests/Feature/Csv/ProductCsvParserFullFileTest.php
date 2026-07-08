<?php

namespace Tests\Feature\Csv;

use App\Services\Csv\Dto\ParsedProduct;
use App\Services\Csv\EncodingSanitizer;
use App\Services\Csv\ProductCsvParser;
use Tests\TestCase;

/**
 * Test di integrazione sul file reale fornito dal cliente (33.096 righe, 7.927
 * prodotti), tenuto compresso in tests/Fixtures/csv/prodotti_web_full.csv.gz.
 * I numeri attesi sono stati ricavati analizzando il file con uno script Python
 * indipendente (stessa logica di consolidamento, implementazione separata), non
 * dedotti dal comportamento del parser stesso.
 */
class ProductCsvParserFullFileTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $gz = base_path('tests/Fixtures/csv/prodotti_web_full.csv.gz');
        $this->csvPath = sys_get_temp_dir().'/'.uniqid('prodotti_web_full_', true).'.csv';

        $in = gzopen($gz, 'rb');
        $out = fopen($this->csvPath, 'wb');
        while (! gzeof($in)) {
            fwrite($out, gzread($in, 1024 * 1024));
        }
        gzclose($in);
        fclose($out);
    }

    protected function tearDown(): void
    {
        if (is_file($this->csvPath)) {
            unlink($this->csvPath);
        }

        parent::tearDown();
    }

    public function test_il_parser_riproduce_i_conteggi_attesi_sul_file_reale_completo(): void
    {
        $sanitizeResult = (new EncodingSanitizer)->sanitize($this->csvPath, $this->csvPath.'.sanitized');

        $productCount = 0;
        $variantCount = 0;
        $handles = [];
        $codici = [];

        $summary = (new ProductCsvParser)->parse($sanitizeResult->path, function (ParsedProduct $product) use (&$productCount, &$variantCount, &$handles, &$codici) {
            $productCount++;
            $variantCount += count($product->variants);
            $handles[] = $product->handle;
            $codici[] = $product->codiceArticolo;
        });

        $this->assertSame(33096, $summary->csvRowCount);
        $this->assertSame(7927, $summary->productsParsed);
        $this->assertSame(0, $summary->productsFailed);
        $this->assertSame(7927, $productCount);
        $this->assertSame(32466, $variantCount);

        // Ogni CODICE ARTICOLO deve produrre esattamente un prodotto, ed ogni handle deve essere unico
        // (compresi i 4 casi di collisione di slug risolti dall'HandleResolver).
        $this->assertCount(7927, array_unique($codici));
        $this->assertCount(7927, array_unique($handles));

        // Le combinazioni duplicate colore+taglia devono generare un warning tracciabile.
        $mergeWarnings = collect($summary->logs)->filter(
            fn ($log) => $log->level === 'warning' && str_contains($log->message, 'duplicata')
        );
        $this->assertSame(600, $mergeWarnings->count());

        if ($sanitizeResult->wasModified()) {
            unlink($sanitizeResult->path);
        }
    }
}
