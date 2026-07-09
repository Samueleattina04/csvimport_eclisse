<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_products(): void
    {
        $product = Product::create([
            'codice_articolo' => 'ABC123',
            'handle' => 'giacca-blu',
            'title' => 'Giacca Blu',
            'status' => 'published',
            'is_active' => true,
        ]);

        Livewire::test(ListProducts::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$product]);
    }

    public function test_view_page_renders_infolist_and_variants(): void
    {
        $product = Product::create([
            'codice_articolo' => 'ABC123',
            'handle' => 'giacca-blu',
            'title' => 'Giacca Blu',
            'vendor' => 'Eclisse',
            'status' => 'published',
            'is_active' => true,
        ]);

        $variant = $product->variants()->create([
            'codice_ean' => '8001234567890',
            'color' => 'Blu',
            'size' => 'M',
            'price' => 99.90,
            'quantity' => 10,
            'is_active' => true,
            'position' => 1,
        ]);

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Giacca Blu')
            ->assertSee('Eclisse');

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => ViewProduct::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$variant]);
    }
}
