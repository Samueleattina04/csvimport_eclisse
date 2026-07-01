<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StagingVariant extends Model
{
    protected $fillable = [
        'staging_product_id',
        'codice_ean',
        'color',
        'size',
        'length',
        'price',
        'cost',
        'quantity',
        'quantity_raw',
        'image_url',
        'position',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    public function stagingProduct(): BelongsTo
    {
        return $this->belongsTo(StagingProduct::class);
    }
}
