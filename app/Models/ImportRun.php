<?php

namespace App\Models;

use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus as BusFacade;

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
        'batch_id',
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

    /**
     * Secondi trascorsi da "started_at" ad adesso, come intero non negativo
     * per la colonna unsignedInteger "duration_seconds". Da Carbon 3,
     * diffInSeconds() di default e' firmato e restituisce un float
     * (comportamento diverso da Carbon 2): senza "absolute: true" e un cast
     * esplicito, un ordine "sbagliato" degli argomenti produce un numero
     * negativo con decimali che MySQL rifiuta su una colonna unsigned.
     */
    public function secondsSinceStart(): ?int
    {
        return $this->started_at
            ? (int) round($this->started_at->diffInSeconds(now(), absolute: true))
            : null;
    }

    public function batch(): ?Batch
    {
        return $this->batch_id ? BusFacade::findBatch($this->batch_id) : null;
    }

    /**
     * @return array{total: int, processed: int, failed: int, percent: int}|null
     */
    public function syncProgress(): ?array
    {
        $batch = $this->batch();
        if ($batch === null || $batch->totalJobs === 0) {
            return null;
        }

        $processed = $batch->totalJobs - $batch->pendingJobs;

        return [
            'total' => $batch->totalJobs,
            'processed' => $processed,
            'failed' => $batch->failedJobs,
            'percent' => (int) round(($processed / $batch->totalJobs) * 100),
        ];
    }
}
