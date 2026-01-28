<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'content_plan_id',
        'created_by',
        'platform',
        'format',
        'scheduled_at',
        'status',
        'title',
        'caption',
        'hashtags',
        'assets',
        'ai_meta',
        'error',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'hashtags' => 'array',
        'assets' => 'array',
        'ai_meta' => 'array',
    ];
}
