<?php

namespace Tests\Feature\Console;

use App\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_esegue_limport_in_modalita_sincrona(): void
    {
        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200),
        ]);

        $this->artisan('import:run --sync')
            ->assertExitCode(0);

        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseCount('staging_products', 9);

        $run = ImportRun::first();
        $this->assertSame('manual', $run->trigger_type);
        $this->assertTrue((bool) $run->dry_run);
        $this->assertContains($run->status, ['completed', 'completed_with_warnings']);
        // Nessun prodotto esiste ancora in canonico: sono tutti nuovi.
        $this->assertSame(9, $run->products_created);
        $this->assertSame(0, $run->products_updated);
    }

    public function test_senza_flag_live_il_run_e_sempre_dry_run(): void
    {
        $fixture = base_path('tests/Fixtures/csv/prodotti_web_sample.csv');
        Http::fake([
            'gestionale.eclisse.moda/*' => Http::response(file_get_contents($fixture), 200),
        ]);

        $this->artisan('import:run --sync --live')
            ->assertExitCode(1);

        $run = ImportRun::first();
        $this->assertFalse((bool) $run->dry_run);
        $this->assertSame('failed', $run->status);
    }

    public function test_rifiuta_di_partire_se_un_import_e_gia_in_corso(): void
    {
        ImportRun::create(['trigger_type' => 'scheduled', 'status' => 'parsing']);

        $this->artisan('import:run --sync')
            ->assertExitCode(1);

        $this->assertDatabaseCount('import_runs', 1);
    }
}
