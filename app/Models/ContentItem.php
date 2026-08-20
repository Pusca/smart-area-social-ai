<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'ai_generated_at' => 'datetime',
        'hashtags' => 'array',
        'ai_hashtags' => 'array',
        'assets' => 'array',
        'ai_meta' => 'array',
        'ai_status' => AiStatus::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'content_plan_id');
    }

    /**
     * Accetta hashtag sia come array che come stringa ("#a #b" o "#a, #b").
     */
    public function setHashtagsAttribute($value): void
    {
        $this->attributes['hashtags'] = $this->normalizeTagList($value);
    }

    public function setAiHashtagsAttribute($value): void
    {
        $this->attributes['ai_hashtags'] = $this->normalizeTagList($value);
    }

    private function normalizeTagList($value): ?string
    {
        if (is_string($value)) {
            $value = array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: []));
        }

        return is_array($value) ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE) : null;
    }
}
