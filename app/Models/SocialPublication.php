<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublication extends Model
{
    protected $fillable = [
        'tenant_id',
        'content_item_id',
        'social_account_id',
        'provider',
        'platform',
        'status',
        'media_type',
        'caption',
        'media_url',
        'scheduled_for',
        'published_at',
        'remote_id',
        'remote_url',
        'attempts',
        'last_attempt_at',
        'error_message',
        'payload',
        'response_meta',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'published_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempts' => 'integer',
        'payload' => 'array',
        'response_meta' => 'array',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
