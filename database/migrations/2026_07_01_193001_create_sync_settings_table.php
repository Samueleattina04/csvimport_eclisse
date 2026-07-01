<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riga singola di configurazione operativa, editabile da Filament.
        // Le credenziali Shopify restano in .env: sono segreti per-ambiente, non vanno esposte in una UI.
        Schema::create('sync_settings', function (Blueprint $table) {
            $table->id();
            $table->string('csv_source_url');
            $table->time('scheduled_run_time')->default('01:00:00');
            $table->boolean('auto_sync_enabled')->default(true);
            $table->unsignedTinyInteger('anomaly_drop_threshold_percent')->default(30);
            $table->boolean('dry_run_mode')->default(true);
            $table->string('notification_email')->nullable();
            $table->boolean('notify_on_success')->default(false);
            $table->boolean('notify_on_failure')->default(true);
            $table->boolean('notify_on_anomaly')->default(true);
            $table->string('slack_webhook_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_settings');
    }
};
