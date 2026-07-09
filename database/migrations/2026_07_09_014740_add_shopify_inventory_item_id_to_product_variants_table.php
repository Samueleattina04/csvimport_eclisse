<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serve per poter azzerare/aggiornare l'inventario di una variante gia'
        // esistente (inventorySetQuantities vuole l'inventory item, non il variant
        // id) senza dover interrogare Shopify di nuovo ad ogni sync successiva.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_inventory_item_id')->nullable()->after('shopify_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('shopify_inventory_item_id');
        });
    }
};
