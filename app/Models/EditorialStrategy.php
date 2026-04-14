<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditorialStrategy extends Model
{
    protected $fillable = [
        'tenant_id',
        'brand_voice',
        'pillars',
        'rubrics',
        'cta_rules',
        'constraints',
        'analysis_framework',
        'visual_system',
        'publishing_system',
        'creative_direction',
        'trend_intelligence',
        'viral_framework',
        'strategy_notes',
        'is_locked',
        'manual_updated_at',
        'last_refreshed_at',
    ];

    protected $casts = [
        'brand_voice' => 'array',
        'pillars' => 'array',
        'rubrics' => 'array',
        'cta_rules' => 'array',
        'constraints' => 'array',
        'analysis_framework' => 'array',
        'visual_system' => 'array',
        'publishing_system' => 'array',
        'creative_direction' => 'array',
        'trend_intelligence' => 'array',
        'viral_framework' => 'array',
        'is_locked' => 'boolean',
        'manual_updated_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
    ];
}