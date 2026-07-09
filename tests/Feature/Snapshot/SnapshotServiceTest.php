<?php

namespace Tests\Feature\Snapshot;

use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Snapshot\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_congela_prodotti_e_varianti_canonici_legati_al_run(): void
    {
        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'syncing', 'dry_run' => false]);

        $product = Product::create([
            'codice_articolo' => 'ABC123',
            'shopify_product_id' => 111,
            'handle' => 'giacca-blu',
            'title' => 'Giacca Blu',
            'vendor' => 'Eclisse',
            'status' => 'published',
            'is_active' => true,
        ]);

        $product->variants()->create([
            'shopify_variant_id' => 222,
            'codice_ean' => '8001234567890',
            'color' => 'Blu',
            'size' => 'M',
            'price' => 99.90,
            'quantity' => 10,
            'is_active' => true,
            'position' => 1,
        ]);

        (new SnapshotService)->snapshot($run);

        $this->assertDatabaseHas('import_snapshot_products', [
            'import_run_id' => $run->id,
            'codice_articolo' => 'ABC123',
            'shopify_product_id' => 111,
            'title' => 'Giacca Blu',
            'status' => 'published',
            'is_active' => 1,
        ]);

        $snapshotProduct = $run->snapshotProducts()->first();

        $this->assertDatabaseHas('import_snapshot_variants', [
            'import_snapshot_product_id' => $snapshotProduct->id,
            'codice_ean' => '8001234567890',
            'quantity' => 10,
        ]);
    }

    public function test_non_scrive_nulla_se_non_ci_sono_prodotti_canonici(): void
    {
        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'syncing', 'dry_run' => false]);

        (new SnapshotService)->snapshot($run);

        $this->assertDatabaseCount('import_snapshot_products', 0);
    }
}
