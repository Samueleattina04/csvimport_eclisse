<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia storica immutabile di "products" al termine di ogni import riuscito
        // (compresi i rollback). E' la base per calcolare un rollback vero: diff tra
        // stato attuale e uno di questi snapshot, non un semplice "rilancia il vecchio file".
        Schema::create('import_snapshot_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();

            $table->string('codice_articolo');
            $table->unsignedBigInteger('shopify_product_id')->nullable();
            $table->string('handle');
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('vendor')->nullable();
            $table->string('gender')->nullable();
            $table->string('category_path')->nullable();
            $table->string('collection_name')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('main_image_url')->nullable();
            $table->enum('status', ['published', 'draft'])->default('draft');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['import_run_id', 'codice_articolo']);
            $table->unique(['import_run_id', 'codice_articolo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_snapshot_products');
    }
};
