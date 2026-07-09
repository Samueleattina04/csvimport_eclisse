<?php

namespace Tests\Feature\Rollback;

use App\Jobs\SyncProductToShopifyJob;
use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Rollback\RollbackPipeline;
use App\Services\Snapshot\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RollbackPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_rollback_dry_run_calcola_il_piano_per_tornare_allo_stato_dello_snapshot(): void
    {
        $targetRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        $productA = Product::create([
            'codice_articolo' => 'A',
            'handle' => 'prodotto-a',
            'title' => 'Prodotto A',
            'status' => 'published',
            'is_active' => true,
        ]);

        $productB = Product::create([
            'codice_articolo' => 'B',
            'handle' => 'prodotto-b',
            'title' => 'Prodotto B',
            'status' => 'published',
            'is_active' => true,
        ]);

        // Scatta lo stato "buono" con A e B cosi' come sono ora.
        (new SnapshotService)->snapshot($targetRun);

        // Simula cio' che succede DOPO lo snapshot: A cambia titolo, B viene
        // rimosso da un import successivo (nascosto, mai eliminato), e un
        // nuovo prodotto C compare (non esisteva al momento dello snapshot).
        $productA->update(['title' => 'Prodotto A modificato']);
        $productB->update(['status' => 'draft', 'is_active' => false]);
        Product::create([
            'codice_articolo' => 'C',
            'handle' => 'prodotto-c',
            'title' => 'Prodotto C',
            'status' => 'published',
            'is_active' => true,
        ]);

        $rollbackRun = ImportRun::create([
            'trigger_type' => 'rollback',
            'status' => 'pending',
            'dry_run' => true,
            'rollback_of_import_run_id' => $targetRun->id,
        ]);

        $result = (new RollbackPipeline)->run($rollbackRun, $targetRun);

        $this->assertContains($result->status, ['completed', 'completed_with_warnings']);
        $this->assertSame(0, $result->products_created);
        $this->assertSame(2, $result->products_updated); // A (titolo) e B (ripristinato)
        $this->assertSame(1, $result->products_removed); // C (non nello snapshot)

        // Dry-run: nessuna scrittura canonica, il catalogo resta quello "corrente".
        $this->assertSame('Prodotto A modificato', $productA->fresh()->title);
        $this->assertFalse((bool) $productB->fresh()->is_active);
    }

    public function test_un_rollback_live_dispatcha_un_job_per_prodotto_cambiato(): void
    {
        Queue::fake();

        $targetRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        Product::create([
            'codice_articolo' => 'A',
            'handle' => 'prodotto-a',
            'title' => 'Prodotto A',
            'status' => 'published',
            'is_active' => true,
        ]);

        (new SnapshotService)->snapshot($targetRun);

        Product::create([
            'codice_articolo' => 'C',
            'handle' => 'prodotto-c',
            'title' => 'Prodotto C',
            'status' => 'published',
            'is_active' => true,
        ]);

        $rollbackRun = ImportRun::create([
            'trigger_type' => 'rollback',
            'status' => 'pending',
            'dry_run' => false,
            'rollback_of_import_run_id' => $targetRun->id,
        ]);

        (new RollbackPipeline)->run($rollbackRun, $targetRun);

        // Solo C e' cambiato rispetto allo snapshot (va rimosso/nascosto): A e'
        // identico, quindi "unchanged" e non genera nessun job.
        Queue::assertPushed(SyncProductToShopifyJob::class, 1);
        Queue::assertPushed(fn (SyncProductToShopifyJob $job) => $job->codiceArticolo === 'C');
    }

    public function test_fallisce_pulito_se_il_run_target_non_ha_snapshot(): void
    {
        $targetRun = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        $rollbackRun = ImportRun::create([
            'trigger_type' => 'rollback',
            'status' => 'pending',
            'dry_run' => true,
            'rollback_of_import_run_id' => $targetRun->id,
        ]);

        $result = (new RollbackPipeline)->run($rollbackRun, $targetRun);

        $this->assertSame('failed', $result->status);
    }
}
