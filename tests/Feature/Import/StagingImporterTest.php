<?php

namespace Tests\Feature\Import;

use App\Models\ImportRun;
use App\Models\SyncSetting;
use App\Services\Csv\CsvDownloader;
use App\Services\Import\StagingImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StagingImporterTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCsvResponse(): void
    {
        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');

        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200),
        ]);
    }

    public function test_scarica_e_mette_in_staging_lintero_fixture(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);
        $run->refresh();

        $this->assertSame('parsing', $run->status);
        $this->assertSame(42, $run->csv_row_count);
        $this->assertSame(9, $run->csv_product_count);
        $this->assertSame(0, $run->products_failed);
        $this->assertNotNull($run->csv_sha256);
        $this->assertNotNull($run->started_at);

        $this->assertDatabaseCount('staging_products', 9);
        $this->assertDatabaseCount('staging_variants', 40);

        $this->assertDatabaseHas('staging_products', [
            'import_run_id' => $run->id,
            'codice_articolo' => '00040EM286CEF',
            'title' => 'T-shirt Calvin Klein con monogramma da uomo',
            'status' => 'published',
        ]);
    }

    public function test_registra_i_log_di_parsing_legati_al_run(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);

        $this->assertDatabaseHas('import_logs', [
            'import_run_id' => $run->id,
            'level' => 'info',
        ]);

        $negativeQtyWarning = $run->logs()->where('message', 'like', '%negativa%')->exists();
        $this->assertTrue($negativeQtyWarning);
    }

    public function test_primo_import_in_assoluto_non_scatena_anomalia(): void
    {
        $this->fakeCsvResponse();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);
        $run->refresh();

        $this->assertFalse((bool) $run->anomaly_detected);
        $this->assertSame('parsing', $run->status);
    }

    public function test_un_crollo_di_prodotti_rispetto_allultimo_run_riuscito_blocca_limport(): void
    {
        $this->fakeCsvResponse();

        ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'completed',
            'csv_product_count' => 1000,
            'finished_at' => now()->subDay(),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);
        $run->refresh();

        $this->assertTrue((bool) $run->anomaly_detected);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->anomaly_reason);
        $this->assertStringContainsString('Crollo prodotti', $run->anomaly_reason);

        // I dati restano in staging per essere ispezionati, non vengono cancellati.
        $this->assertDatabaseCount('staging_products', 9);
    }

    public function test_una_piccola_variazione_sotto_soglia_non_scatena_anomalia(): void
    {
        $this->fakeCsvResponse();

        ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'completed',
            'csv_product_count' => 10,
            'finished_at' => now()->subDay(),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);
        $run->refresh();

        // 9 su 10 = -10%, sotto la soglia di default (30%).
        $this->assertFalse((bool) $run->anomaly_detected);
        $this->assertSame('parsing', $run->status);
    }

    public function test_la_soglia_di_anomalia_e_configurabile(): void
    {
        $this->fakeCsvResponse();

        SyncSetting::current()->update(['anomaly_drop_threshold_percent' => 5]);

        ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'completed',
            'csv_product_count' => 10,
            'finished_at' => now()->subDay(),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);
        (new StagingImporter)->run($run);
        $run->refresh();

        // 9 su 10 = -10%, sopra la soglia abbassata a 5%.
        $this->assertTrue((bool) $run->anomaly_detected);
    }

    public function test_un_download_fallito_marca_il_run_come_failed(): void
    {
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response('errore', 500),
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);

        try {
            (new StagingImporter(downloader: new CsvDownloader(retries: 1, retryDelayMs: 0)))->run($run);
            $this->fail('Doveva lanciare un\'eccezione.');
        } catch (\Throwable) {
            // atteso
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
    }
}
