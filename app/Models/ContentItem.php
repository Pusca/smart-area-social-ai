<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'content_plan_id',
        'title',
        'format',
        'platform',
        'scheduled_for',
        'status',
        'meta',

        'ai_status',
        'ai_caption',
        'ai_hashtags',
        'ai_cta',
        'ai_image_prompt',
        'ai_image_path',
        'ai_error',
        'ai_generated_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'scheduled_for' => 'date',
        'ai_hashtags' => 'array',
        'ai_generated_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(ContentPlan::class, 'content_plan_id');
    }
}
