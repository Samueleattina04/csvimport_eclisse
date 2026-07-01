<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();

            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->string('codice_articolo')->nullable();
            $table->string('codice_ean')->nullable();
            $table->unsignedInteger('csv_row_number')->nullable();
            $table->text('message');
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['import_run_id', 'level']);
            $table->index(['codice_articolo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
