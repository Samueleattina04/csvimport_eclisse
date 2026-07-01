<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'shopify_variant_id',
        'codice_ean',
        'color',
        'size',
        'length',
        'price',
        'cost',
        'quantity',
        'image_url',
        'position',
        'is_active',
        'last_seen_import_run_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lastSeenImportRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'last_seen_import_run_id');
    }
}
