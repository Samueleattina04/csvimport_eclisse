<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'codice_articolo',
        'shopify_product_id',
        'handle',
        'title',
        'body_html',
        'vendor',
        'gender',
        'category_path',
        'collection_name',
        'meta_description',
        'main_image_url',
        'status',
        'is_active',
        'last_seen_import_run_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function lastSeenImportRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'last_seen_import_run_id');
    }
}
