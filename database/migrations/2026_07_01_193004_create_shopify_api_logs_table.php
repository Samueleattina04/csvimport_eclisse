<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();

            $table->string('operation_name');
            $table->string('entity_reference')->nullable();
            $table->text('request_summary')->nullable();
            $table->text('response_summary')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(false);
            $table->json('user_errors')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('retried_count')->default(0);

            $table->timestamps();

            $table->index(['import_run_id']);
            $table->index(['operation_name']);
            $table->index(['success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_api_logs');
    }
};
