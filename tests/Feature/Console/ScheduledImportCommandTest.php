<?php

namespace Tests\Feature\Console;

use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use App\Models\SyncSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_accoda_un_import_schedulato_rispettando_dry_run_mode(): void
    {
        Queue::fake();

        SyncSetting::current()->update(['auto_sync_enabled' => true, 'dry_run_mode' => false]);

        $this->artisan('import:scheduled-run')->assertExitCode(0);

        $run = ImportRun::sole();
        $this->assertSame('scheduled', $run->trigger_type);
        $this->assertFalse((bool) $run->dry_run);

        Queue::assertPushed(RunImportJob::class);
    }

    public function test_non_fa_nulla_se_il_sync_automatico_e_disattivato(): void
    {
        Queue::fake();

        SyncSetting::current()->update(['auto_sync_enabled' => false]);

        $this->artisan('import:scheduled-run')->assertExitCode(0);

        $this->assertDatabaseCount('import_runs', 0);
        Queue::assertNotPushed(RunImportJob::class);
    }

    public function test_salta_il_giro_se_un_import_e_gia_in_corso(): void
    {
        Queue::fake();

        SyncSetting::current()->update(['auto_sync_enabled' => true]);
        ImportRun::create(['trigger_type' => 'manual', 'status' => 'syncing', 'dry_run' => false]);

        $this->artisan('import:scheduled-run')->assertExitCode(0);

        $this->assertSame(1, ImportRun::count());
        Queue::assertNotPushed(RunImportJob::class);
    }
}
