<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stato "grezzo" scaricato in un run: sopravvive al run per audit/debug ravvicinato,
        // viene ripulito periodicamente da un comando di retention.
        Schema::create('staging_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();

            $table->string('codice_articolo');
            $table->string('handle');
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('vendor')->nullable();
            $table->string('gender')->nullable();
            $table->string('category_path')->nullable();
            $table->string('collection_name')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('main_image_url')->nullable();
            $table->string('visibility_raw')->nullable();
            $table->enum('status', ['published', 'draft'])->default('draft');
            $table->char('content_hash', 64);

            $table->timestamps();

            $table->index(['import_run_id', 'codice_articolo']);
            $table->unique(['import_run_id', 'codice_articolo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staging_products');
    }
};
