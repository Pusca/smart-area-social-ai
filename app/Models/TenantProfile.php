<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'business_name',
        'industry',
        'website',
        'notes',
        'vision',
        'values',
        'business_hours',
        'seasonal_offers',
        'brand_palette',
        'overlay_preferences',
        'learning_preferences',
        'services',
        'target',
        'cta',
        'default_goal',
        'default_tone',
        'default_posts_per_week',
        'default_platforms',
        'default_formats',
        'completed_at',
        'onboarding_started_at',
        'onboarding_completed_at',
        'quickstart_generated_at',
        'quickstart_last_plan_id',
        'quickstart_dismissed_at',
    ];

    protected $casts = [
        'default_platforms' => 'array',
        'default_formats' => 'array',
        'overlay_preferences' => 'array',
        'learning_preferences' => 'array',
        'completed_at' => 'datetime',
        'onboarding_started_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'quickstart_generated_at' => 'datetime',
        'quickstart_dismissed_at' => 'datetime',
    ];
}
