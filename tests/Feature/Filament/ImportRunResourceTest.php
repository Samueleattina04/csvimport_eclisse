<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\LogsRelationManager;
use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ImportRunResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_import_runs(): void
    {
        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed',
            'dry_run' => true,
            'products_created' => 3,
        ]);

        Livewire::test(ListImportRuns::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$run]);
    }

    public function test_view_page_renders_infolist_and_logs(): void
    {
        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed_with_warnings',
            'dry_run' => true,
            'anomaly_detected' => true,
            'anomaly_reason' => 'Calo prodotti superiore alla soglia',
        ]);

        $log = $run->logs()->create([
            'level' => 'warning',
            'codice_articolo' => 'ABC123',
            'message' => 'EAN duplicato',
        ]);

        Livewire::test(ViewImportRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertSee('Calo prodotti superiore alla soglia');

        Livewire::test(LogsRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => ViewImportRun::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$log]);
    }

    public function test_importa_ora_action_dispatches_a_dry_run_by_default(): void
    {
        Queue::fake();

        Livewire::test(ListImportRuns::class)
            ->callAction(TestAction::make('importaOra')->table())
            ->assertNotified();

        $run = ImportRun::sole();

        $this->assertTrue($run->dry_run);
        $this->assertSame('manual', $run->trigger_type);

        Queue::assertPushed(RunImportJob::class);
    }

    public function test_importa_ora_action_can_dispatch_a_live_run(): void
    {
        Queue::fake();

        Livewire::test(ListImportRuns::class)
            ->callAction(TestAction::make('importaOra')->table(), data: ['live' => true])
            ->assertNotified();

        $run = ImportRun::sole();

        $this->assertFalse($run->dry_run);
    }

    public function test_importa_ora_action_refuses_to_start_when_an_import_is_already_running(): void
    {
        Queue::fake();

        ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'syncing',
            'dry_run' => false,
        ]);

        Livewire::test(ListImportRuns::class)
            ->callAction(TestAction::make('importaOra')->table())
            ->assertNotified();

        $this->assertSame(1, ImportRun::count());
        Queue::assertNotPushed(RunImportJob::class);
    }
}
