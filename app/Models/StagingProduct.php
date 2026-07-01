<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StagingProduct extends Model
{
    protected $fillable = [
        'import_run_id',
        'codice_articolo',
        'handle',
        'title',
        'body_html',
        'vendor',
        'gender',
        'category_path',
        'collection_name',
        'meta_description',
        'main_image_url',
        'visibility_raw',
        'status',
        'content_hash',
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(StagingVariant::class);
    }
}
