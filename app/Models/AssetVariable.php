<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetVariable extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'kind',
        'description',
        'asset_ids',
        'profile',
        'is_active',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'profile' => 'array',
        'is_active' => 'boolean',
    ];
}
