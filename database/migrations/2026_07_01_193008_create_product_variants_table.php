<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_variant_id')->nullable()->unique();

            $table->string('codice_ean')->unique();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('length')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('image_url')->nullable();
            $table->unsignedInteger('position')->default(0);

            // false quando la variante e' sparita dal CSV: quantita' forzata a 0 e nascosta,
            // il record resta per storia/riconciliazione futura.
            $table->boolean('is_active')->default(true);

            $table->foreignId('last_seen_import_run_id')->nullable()
                ->constrained('import_runs')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
