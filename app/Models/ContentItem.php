<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ContentItem extends Model
{
    protected $table = 'content_items';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'ai_generated_at' => 'datetime',
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

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(GenerationRun::class);
    }

    public function canvaDesigns(): HasMany
    {
        return $this->hasMany(CanvaDesign::class);
    }

    public function feedbackEntries(): HasMany
    {
        return $this->hasMany(ContentFeedbackEntry::class);
    }

    public function latestFeedbackEntry(): HasOne
    {
        return $this->hasOne(ContentFeedbackEntry::class, 'content_item_id')->latestOfMany('id');
    }

    /**
     * @return array<int, string>
     */
    public function platforms(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => Str::lower(trim((string) $value)),
            preg_split('/[\s,;|]+/', (string) $this->platform) ?: []
        ))));
    }

    public function setHashtagsAttribute($value): void
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value));
            $parts = array_values(array_filter($parts));
            $this->attributes['hashtags'] = json_encode($parts, JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        if (is_array($value)) {
            $this->attributes['hashtags'] = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        $this->attributes['hashtags'] = null;
    }

    public function setAiMetaAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['ai_meta'] = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
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
            $this->attributes['assets'] = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        if (is_string($value)) {
            $this->attributes['assets'] = $value;
            return;
        }

        $this->attributes['assets'] = null;
    }
}


