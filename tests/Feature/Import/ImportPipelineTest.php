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

    public function test_una_run_live_senza_credenziali_shopify_fallisce_prodotto_per_prodotto_senza_bloccarsi(): void
    {
        // Nessuna credenziale Shopify configurata in questo ambiente: ogni job
        // fallira' con ShopifyNotConfiguredException, ma il batch continua
        // (allowFailures) invece di bloccarsi al primo prodotto.
        //
        // Il driver "sync" (default nei test) rilancia l'eccezione del PRIMO job
        // fallito interrompendo il ciclo di dispatch dell'intero batch: e' un
        // limite noto del driver sync (SyncQueue::handleException fa "throw $e"
        // dopo aver comunque registrato il fallimento), non del nostro codice.
        // Un vero worker (php artisan queue:work, quello usato in produzione)
        // isola le eccezioni job per job, quindi qui passiamo al driver
        // "database" + un vero giro di queue:work per validare il comportamento
        // reale invece di quello (fuorviante) del driver sync.
        config(['queue.default' => 'database']);
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending', 'dry_run' => false]);
        $this->pipeline()->run($run);
        $run->refresh();

        $this->assertSame('syncing', $run->status);

        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);
        $run->refresh();

        $this->assertSame('completed_with_warnings', $run->status);
        $this->assertSame(9, $run->products_created); // il piano resta quello calcolato dal diff
        $this->assertSame(9, $run->products_failed); // tutti e 9 falliti per mancanza di credenziali
        $this->assertDatabaseCount('products', 0); // nessuna scrittura canonica senza successo Shopify
        $this->assertDatabaseHas('import_logs', [
            'import_run_id' => $run->id,
            'level' => 'error',
        ]);
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
