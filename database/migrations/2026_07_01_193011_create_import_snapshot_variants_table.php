<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_snapshot_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_snapshot_product_id')->constrained('import_snapshot_products')->cascadeOnDelete();

            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->string('codice_ean');
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('length')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('image_url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['import_snapshot_product_id']);
            $table->index(['codice_ean']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_snapshot_variants');
    }
};
