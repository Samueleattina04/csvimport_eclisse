<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staging_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staging_product_id')->constrained('staging_products')->cascadeOnDelete();

            $table->string('codice_ean');
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('length')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->nullable();
            // Quantita' clampata a 0 se il CSV riporta un valore negativo (vedi quantity_raw per il dato originale).
            $table->unsignedInteger('quantity')->default(0);
            $table->integer('quantity_raw')->default(0);
            $table->string('image_url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->char('content_hash', 64);

            $table->timestamps();

            $table->index(['staging_product_id']);
            $table->index(['codice_ean']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staging_variants');
    }
};
