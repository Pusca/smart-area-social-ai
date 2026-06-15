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
        'ai_description',
        'ai_context',
        'ai_tags',
        'ai_analyzed_at',
    ];

    protected $casts = [
        'meta'            => 'array',
        'ai_tags'         => 'array',
        'ai_analyzed_at'  => 'datetime',
    ];

    public function isImage(): bool
    {
        return in_array($this->kind, ['image', 'logo'], true);
    }

    public function hasAiUnderstanding(): bool
    {
        return $this->ai_analyzed_at !== null;
    }

    public function finalDescription(): string
    {
        return trim((string) ($this->ai_description ?? ''));
    }

    public function canvaMappings(): HasMany
    {
        return $this->hasMany(CanvaAssetMapping::class);
    }
}
