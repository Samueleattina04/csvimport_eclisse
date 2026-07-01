<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyApiLog extends Model
{
    protected $fillable = [
        'import_run_id',
        'operation_name',
        'entity_reference',
        'request_summary',
        'response_summary',
        'http_status',
        'success',
        'user_errors',
        'duration_ms',
        'retried_count',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'user_errors' => 'array',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
