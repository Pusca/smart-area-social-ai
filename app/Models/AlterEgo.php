<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlterEgo extends Model
{
    protected $fillable = [
        'tenant_id',
        'identity_asset_id',
        'name',
        'archetype',
        'tone',
        'sentence_style',
        'vocabulary_level',
        'signature_phrases',
        'topics_owned',
        'topics_avoided',
        'content_pillars',
        'unique_perspective',
        'audience_role',
        'cta_style',
        'training_samples',
        'platform_adaptations',
        'visual_references',
        'audio_references',
        'video_references',
        'persona_prompt_cache',
        'persona_prompt_version',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'signature_phrases'    => 'array',
        'topics_owned'         => 'array',
        'topics_avoided'       => 'array',
        'content_pillars'      => 'array',
        'training_samples'     => 'array',
        'platform_adaptations' => 'array',
        'visual_references'    => 'array',
        'audio_references'     => 'array',
        'video_references'     => 'array',
        'is_active'            => 'boolean',
        'is_default'           => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function identityAsset(): BelongsTo
    {
        return $this->belongsTo(IdentityAsset::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }
}
