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
        'identity_asset_id',
        'voice_asset_id',
        'voice_provider',
        'voice_provider_voice_id',
        'voice_status',
        'identity_mode',
        'consistency_threshold',
        'profile',
        'identity_pack',
        'is_active',
    ];

    protected $casts = [
        'asset_ids'            => 'array',
        'profile'              => 'array',
        'identity_pack'        => 'array',
        'canonical_asset_id'   => 'integer',
        'identity_asset_id'    => 'integer',
        'voice_asset_id'       => 'integer',
        'consistency_threshold' => 'integer',
        'is_active'            => 'boolean',
    ];

    public function canonicalAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class, 'canonical_asset_id');
    }

    public function voiceAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class, 'voice_asset_id');
    }

    public function identityAsset(): BelongsTo
    {
        return $this->belongsTo(IdentityAsset::class);
    }

    /** True se questa variabile ha un'identità gestita dal nuovo sistema. */
    public function hasIdentityAsset(): bool
    {
        return $this->identity_asset_id !== null;
    }
}
