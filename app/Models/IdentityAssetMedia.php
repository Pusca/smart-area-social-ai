<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class IdentityAssetMedia extends Pivot
{
    protected $table = 'identity_asset_media';

    protected $fillable = [
        'identity_asset_id',
        'brand_asset_id',
        'role',
        'sort_order',
        'provider_hints',
    ];

    protected $casts = [
        'provider_hints' => 'array',
        'sort_order'     => 'integer',
    ];

    public function identityAsset(): BelongsTo
    {
        return $this->belongsTo(IdentityAsset::class);
    }

    public function brandAsset(): BelongsTo
    {
        return $this->belongsTo(BrandAsset::class);
    }
}
