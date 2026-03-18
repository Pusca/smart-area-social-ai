<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetVariable extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'kind',
        'asset_role',
        'description',
        'asset_ids',
        'canonical_asset_id',
        'voice_asset_id',
        'voice_provider',
        'voice_provider_voice_id',
        'voice_status',
        'identity_mode',
        'consistency_threshold',
        'profile',
        'is_active',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'profile' => 'array',
        'canonical_asset_id' => 'integer',
        'voice_asset_id' => 'integer',
        'consistency_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function canonicalAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class, 'canonical_asset_id');
    }

    public function voiceAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class, 'voice_asset_id');
    }
}
