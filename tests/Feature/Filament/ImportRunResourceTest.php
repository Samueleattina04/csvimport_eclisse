<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\LogsRelationManager;
use App\Jobs\RunImportJob;
use App\Jobs\RunRollbackJob;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\User;
use App\Services\Snapshot\SnapshotService;
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

    public function test_rollback_action_is_hidden_for_dry_run_or_unsuccessful_imports(): void
    {
        $dryRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => true]);

        Livewire::test(ViewImportRun::class, ['record' => $dryRun->getRouteKey()])
            ->assertActionHidden('rollback');

        $failed = ImportRun::create(['trigger_type' => 'manual', 'status' => 'failed', 'dry_run' => false]);

        Livewire::test(ViewImportRun::class, ['record' => $failed->getRouteKey()])
            ->assertActionHidden('rollback');
    }

    public function test_rollback_action_is_hidden_without_a_snapshot(): void
    {
        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        Livewire::test(ViewImportRun::class, ['record' => $run->getRouteKey()])
            ->assertActionHidden('rollback');
    }

    public function test_rollback_action_dispatches_a_dry_run_by_default(): void
    {
        Queue::fake();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);
        Product::create(['codice_articolo' => 'ABC', 'handle' => 'abc', 'title' => 'ABC', 'status' => 'published', 'is_active' => true]);
        (new SnapshotService)->snapshot($run);

        Livewire::test(ViewImportRun::class, ['record' => $run->getRouteKey()])
            ->callAction('rollback')
            ->assertNotified();

        $rollbackRun = ImportRun::where('trigger_type', 'rollback')->sole();

        $this->assertTrue($rollbackRun->dry_run);
        $this->assertSame($run->id, $rollbackRun->rollback_of_import_run_id);
        Queue::assertPushed(RunRollbackJob::class, fn (RunRollbackJob $job) => $job->targetImportRunId === $run->id);
    }

    public function test_rollback_action_refuses_to_start_when_an_import_is_already_running(): void
    {
        Queue::fake();

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);
        Product::create(['codice_articolo' => 'ABC', 'handle' => 'abc', 'title' => 'ABC', 'status' => 'published', 'is_active' => true]);
        (new SnapshotService)->snapshot($run);

        ImportRun::create(['trigger_type' => 'scheduled', 'status' => 'syncing', 'dry_run' => false]);

        Livewire::test(ViewImportRun::class, ['record' => $run->getRouteKey()])
            ->callAction('rollback')
            ->assertNotified();

        $this->assertSame(0, ImportRun::where('trigger_type', 'rollback')->count());
        Queue::assertNotPushed(RunRollbackJob::class);
    }
}
