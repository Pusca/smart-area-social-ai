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
        'services',
        'target',
        'cta',
        'default_goal',
        'default_tone',
        'default_posts_per_week',
        'default_platforms',
        'default_formats',
        'completed_at',
    ];

    protected $casts = [
        'default_platforms' => 'array',
        'default_formats' => 'array',
        'completed_at' => 'datetime',
    ];
}
