<?php

namespace Tests\Feature\Rollback;

use App\Models\ImportRun;
use App\Services\Rollback\RollbackStagingImporter;
use App\Services\Snapshot\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RollbackStagingImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_popola_lo_staging_a_partire_dallo_snapshot_del_run_target(): void
    {
        $targetRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        DB::table('products')->insert([
            'codice_articolo' => 'ABC123',
            'handle' => 'giacca-blu',
            'title' => 'Giacca Blu',
            'status' => 'published',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new SnapshotService)->snapshot($targetRun);

        $rollbackRun = ImportRun::create([
            'trigger_type' => 'rollback',
            'status' => 'pending',
            'dry_run' => true,
            'rollback_of_import_run_id' => $targetRun->id,
        ]);

        $result = (new RollbackStagingImporter)->run($rollbackRun, $targetRun);

        $this->assertSame('parsing', $result->status);
        $this->assertSame(1, $result->csv_product_count);
        $this->assertDatabaseHas('staging_products', [
            'import_run_id' => $rollbackRun->id,
            'codice_articolo' => 'ABC123',
            'title' => 'Giacca Blu',
            'status' => 'published',
        ]);
    }

    public function test_fallisce_se_il_run_target_non_ha_uno_snapshot(): void
    {
        $targetRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        $rollbackRun = ImportRun::create([
            'trigger_type' => 'rollback',
            'status' => 'pending',
            'dry_run' => true,
            'rollback_of_import_run_id' => $targetRun->id,
        ]);

        $result = (new RollbackStagingImporter)->run($rollbackRun, $targetRun);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('snapshot', $result->error_message);
        $this->assertDatabaseHas('import_logs', [
            'import_run_id' => $rollbackRun->id,
            'level' => 'error',
        ]);
    }
}
