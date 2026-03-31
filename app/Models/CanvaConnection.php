<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanvaConnection extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'canva_user_id',
        'canva_team_id',
        'canva_display_name',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'scopes',
        'capabilities',
        'status',
        'last_synced_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'access_token_encrypted' => 'encrypted',
        'refresh_token_encrypted' => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'capabilities' => 'array',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function templateMappings(): HasMany
    {
        return $this->hasMany(CanvaTemplateMapping::class, 'tenant_id', 'tenant_id');
    }

    public function designs(): HasMany
    {
        return $this->hasMany(CanvaDesign::class, 'tenant_id', 'tenant_id');
    }
}
