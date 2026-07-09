<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\CurrentImportWidget;
use App\Filament\Widgets\ImportStatsOverview;
use App\Filament\Widgets\RecentImportsWidget;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_stats_overview_reflects_current_state(): void
    {
        Product::create([
            'codice_articolo' => 'ABC123',
            'handle' => 'abc123',
            'title' => 'Prodotto attivo',
            'status' => 'published',
            'is_active' => true,
        ]);

        ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed',
            'dry_run' => false,
            'finished_at' => now(),
            'products_created' => 2,
            'products_updated' => 4,
        ]);

        Livewire::test(ImportStatsOverview::class)
            ->assertOk()
            ->assertSee('Prodotti attivi su Shopify')
            ->assertSee('1')
            ->assertSee('2 nuovi, 4 aggiornati');
    }

    public function test_current_import_widget_shows_the_running_import(): void
    {
        $run = ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'syncing',
            'dry_run' => false,
        ]);

        Livewire::test(CurrentImportWidget::class)
            ->assertOk()
            ->assertSee("Import #{$run->id} in corso")
            ->assertSee('Sincronizzazione con Shopify in corso');
    }

    public function test_current_import_widget_shows_idle_state_when_nothing_is_running(): void
    {
        ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed',
            'dry_run' => false,
        ]);

        Livewire::test(CurrentImportWidget::class)
            ->assertOk()
            ->assertSee('Nessun import in corso al momento.');
    }

    public function test_recent_imports_widget_lists_import_runs(): void
    {
        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed',
            'dry_run' => true,
        ]);

        Livewire::test(RecentImportsWidget::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$run]);
    }
}
