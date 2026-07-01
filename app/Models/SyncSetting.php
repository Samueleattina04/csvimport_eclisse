<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncSetting extends Model
{
    protected $fillable = [
        'csv_source_url',
        'scheduled_run_time',
        'auto_sync_enabled',
        'anomaly_drop_threshold_percent',
        'dry_run_mode',
        'notification_email',
        'notify_on_success',
        'notify_on_failure',
        'notify_on_anomaly',
        'slack_webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'auto_sync_enabled' => 'boolean',
            'dry_run_mode' => 'boolean',
            'notify_on_success' => 'boolean',
            'notify_on_failure' => 'boolean',
            'notify_on_anomaly' => 'boolean',
        ];
    }

    /**
     * Riga singola di configurazione: la crea al volo con i default se non esiste ancora.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'csv_source_url' => 'https://gestionale.eclisse.moda/output/export/prodotti_web.csv',
        ]);
    }
}
