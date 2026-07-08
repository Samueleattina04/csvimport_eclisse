<?php

namespace Tests\Feature\Import;

use App\Models\ImportRun;
use App\Services\Csv\CsvDownloader;
use App\Services\Import\ImportPipeline;
use App\Services\Import\StagingImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCsvResponse(): void
    {
        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200),
        ]);
    }

    private function pipeline(): ImportPipeline
    {
        return new ImportPipeline(new StagingImporter(downloader: new CsvDownloader(retries: 1, retryDelayMs: 0)));
    }

    public function test_esegue_staging_e_diff_in_sequenza_e_aggiorna_i_contatori(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        $this->pipeline()->run($run);
        $run->refresh();

        // Dry-run di default: il run si conclude qui, il piano E' il risultato.
        $this->assertTrue((bool) $run->dry_run);
        $this->assertContains($run->status, ['completed', 'completed_with_warnings']);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(9, $run->products_created);
        $this->assertSame(0, $run->products_updated);
        $this->assertSame(0, $run->products_unchanged);
        $this->assertSame(0, $run->products_removed);
        $this->assertSame(40, $run->variants_created);

        // Il dry-run non tocca mai lo stato canonico: nessuna chiamata a Shopify e' avvenuta.
        $this->assertDatabaseCount('products', 0);
    }

    public function test_una_run_live_fallisce_esplicitamente_perche_non_ancora_implementata(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending', 'dry_run' => false]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('non ancora implementata', $run->error_message);
        // Il piano e' comunque stato calcolato e i contatori popolati, solo non applicato.
        $this->assertSame(9, $run->products_created);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_scrive_un_log_leggibile_per_ogni_prodotto_nuovo(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        $this->pipeline()->run($run);

        $this->assertDatabaseHas('import_logs', [
            'import_run_id' => $run->id,
            'codice_articolo' => '00040EM286CEF',
            'level' => 'info',
        ]);

        $log = $run->logs()->where('codice_articolo', '00040EM286CEF')->first();
        $this->assertStringContainsString('Prodotto nuovo', $log->message);
    }

    public function test_se_lo_staging_fallisce_il_diff_non_viene_calcolato(): void
    {
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response('errore', 500),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);

        try {
            $this->pipeline()->run($run);
        } catch (\Throwable) {
            // atteso: lo StagingImporter rilancia l'eccezione dopo aver marcato il run fallito
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->products_created);
    }

    public function test_unanomalia_di_crollo_prodotti_impedisce_il_diff(): void
    {
        $this->fakeCsvResponse();

        ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'completed',
            'csv_product_count' => 1000,
            'finished_at' => now()->subDay(),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertTrue((bool) $run->anomaly_detected);
        $this->assertSame(0, $run->products_created);
    }
}
