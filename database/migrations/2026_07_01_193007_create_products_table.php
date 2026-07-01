<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stato canonico attuale: cio' che DEVE esserci su Shopify adesso. Mutabile,
        // aggiornato ad ogni sync riuscita. codice_articolo e' la chiave di riconciliazione,
        // mai il titolo o l'handle.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('codice_articolo')->unique();
            $table->unsignedBigInteger('shopify_product_id')->nullable()->unique();

            $table->string('handle')->unique();
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('vendor')->nullable();
            $table->string('gender')->nullable();
            $table->string('category_path')->nullable();
            $table->string('collection_name')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('main_image_url')->nullable();
            $table->enum('status', ['published', 'draft'])->default('draft');

            // false quando il prodotto e' sparito dal CSV: viene nascosto/azzerato, mai eliminato.
            $table->boolean('is_active')->default(true);

            $table->foreignId('last_seen_import_run_id')->nullable()
                ->constrained('import_runs')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
