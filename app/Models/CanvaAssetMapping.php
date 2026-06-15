<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanvaAssetMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'brand_asset_id',
        'content_item_id',
        'canva_asset_id',
        'asset_kind',
        'source_path',
        'sync_status',
        'synced_at',
        'meta',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
