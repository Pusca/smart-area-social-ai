<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentFeedbackEntry extends Model
{
    public const SENTIMENT_LIKE = 'like';
    public const SENTIMENT_DISLIKE = 'dislike';

    public const ACTION_RECORD_ONLY = 'record_only';
    public const ACTION_REGENERATE = 'regenerate';

    public const SCOPE_VISUAL_FIRST = 'visual_first';
    public const SCOPE_COPY_FIRST = 'copy_first';
    public const SCOPE_FULL = 'full';

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'realism' => 'Realismo immagine',
        'brand_alignment' => 'Coerenza con il brand',
        'tone_of_voice' => 'Tono di voce',
        'caption_copy' => 'Testo e caption',
        'call_to_action' => 'Invito all azione',
        'visual_composition' => 'Composizione visual',
        'location_integrity' => 'Fedelta del luogo reale',
        'offer_focus' => 'Offerta o messaggio',
        'platform_fit' => 'Adatto al social scelto',
        'other' => 'Altro',
    ];

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
