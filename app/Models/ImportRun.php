<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    use HasUuids;

    /**
     * "id" resta l'autoincrement standard (FK piu' leggere); "uuid" e' solo un identificatore
     * pubblico stabile, quindi va generato su quella colonna e non sulla primary key.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'trigger_type',
        'status',
        'dry_run',
        'initiated_by_user_id',
        'rollback_of_import_run_id',
        'source_url',
        'csv_sha256',
        'csv_row_count',
        'csv_product_count',
        'previous_successful_product_count',
        'anomaly_detected',
        'anomaly_reason',
        'products_created',
        'products_updated',
        'products_unchanged',
        'products_removed',
        'products_failed',
        'variants_created',
        'variants_updated',
        'variants_removed',
        'error_message',
        'started_at',
        'finished_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'anomaly_detected' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_import_run_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ImportLog::class);
    }

    public function shopifyApiLogs(): HasMany
    {
        return $this->hasMany(ShopifyApiLog::class);
    }

    public function stagingProducts(): HasMany
    {
        return $this->hasMany(StagingProduct::class);
    }

    public function snapshotProducts(): HasMany
    {
        return $this->hasMany(ImportSnapshotProduct::class);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_warnings'], true);
    }
}
