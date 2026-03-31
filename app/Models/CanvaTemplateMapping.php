<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanvaTemplateMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'channel_format',
        'canva_template_id',
        'canva_template_name',
        'dataset_schema_json',
        'mapping_rules_json',
        'status',
        'canva_view_url',
        'canva_create_url',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'dataset_schema_json' => 'array',
        'mapping_rules_json' => 'array',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function designs(): HasMany
    {
        return $this->hasMany(CanvaDesign::class);
    }
}
