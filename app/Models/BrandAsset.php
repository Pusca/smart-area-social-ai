<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandAsset extends Model
{
    protected $fillable = [
        'tenant_id',
        'content_plan_id',
        'kind',
        'path',
        'original_name',
        'size',
        'mime',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function canvaMappings(): HasMany
    {
        return $this->hasMany(CanvaAssetMapping::class);
    }
}
