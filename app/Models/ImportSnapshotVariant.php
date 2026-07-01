<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSnapshotVariant extends Model
{
    protected $fillable = [
        'import_snapshot_product_id',
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
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function snapshotProduct(): BelongsTo
    {
        return $this->belongsTo(ImportSnapshotProduct::class, 'import_snapshot_product_id');
    }
}
