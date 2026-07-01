<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportSnapshotProduct extends Model
{
    protected $fillable = [
        'import_run_id',
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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ImportSnapshotVariant::class);
    }
}
