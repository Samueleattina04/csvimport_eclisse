<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->enum('trigger_type', ['scheduled', 'manual', 'rollback'])->default('manual');
            $table->enum('status', [
                'pending', 'downloading', 'parsing', 'diffing', 'syncing',
                'completed', 'completed_with_warnings', 'failed',
            ])->default('pending');
            $table->boolean('dry_run')->default(true);

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Se e' un rollback, punta al run il cui snapshot e' stato ripristinato.
            $table->foreignId('rollback_of_import_run_id')->nullable()
                ->constrained('import_runs')->nullOnDelete();

            $table->string('source_url')->nullable();
            $table->string('csv_sha256', 64)->nullable();
            $table->unsignedInteger('csv_row_count')->nullable();
            $table->unsignedInteger('csv_product_count')->nullable();
            $table->unsignedInteger('previous_successful_product_count')->nullable();

            $table->boolean('anomaly_detected')->default(false);
            $table->text('anomaly_reason')->nullable();

            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_unchanged')->default(0);
            $table->unsignedInteger('products_removed')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->unsignedInteger('variants_created')->default(0);
            $table->unsignedInteger('variants_updated')->default(0);
            $table->unsignedInteger('variants_removed')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
