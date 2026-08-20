<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'business_name',
        'industry',
        'website',
        'social_links',
        'notes',
        'services',
        'target',
        'cta',
        'brand_voice',
        'example_posts',
        'visual_style',
        'default_goal',
        'default_tone',
        'default_posts_per_week',
        'default_platforms',
        'default_formats',
        'completed_at',
    ];

    protected $casts = [
        'social_links' => 'array',
        'default_platforms' => 'array',
        'default_formats' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Contesto brand per la generazione AI: unica fonte di verità,
     * letta live al momento della generazione (mai snapshot nei piani).
     */
    public function toAiContext(): array
    {
        return array_filter([
            'business_name' => $this->business_name,
            'industry' => $this->industry,
            'website' => $this->website,
            'services' => $this->services,
            'target' => $this->target,
            'cta' => $this->cta,
            'brand_voice' => $this->brand_voice,
            'example_posts' => $this->example_posts,
            'visual_style' => $this->visual_style,
            'notes' => $this->notes,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');
    }
}
