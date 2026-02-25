<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    protected $table = 'content_items';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'hashtags' => 'array',
        'assets' => 'array',
        'ai_meta' => 'array',
        'ai_hashtags' => 'array',
        'source_refs' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'content_plan_id');
    }

    public function setHashtagsAttribute($value): void
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value));
            $parts = array_values(array_filter($parts));
            $this->attributes['hashtags'] = json_encode($parts);
            return;
        }

        if (is_array($value)) {
            $this->attributes['hashtags'] = json_encode($value);
            return;
        }

        $this->attributes['hashtags'] = null;
    }

    public function setAiMetaAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['ai_meta'] = json_encode($value);
            return;
        }

        if (is_string($value)) {
            $this->attributes['ai_meta'] = $value;
            return;
        }

        $this->attributes['ai_meta'] = null;
    }

    public function setAssetsAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['assets'] = json_encode($value);
            return;
        }

        if (is_string($value)) {
            $this->attributes['assets'] = $value;
            return;
        }

        $this->attributes['assets'] = null;
    }
}
