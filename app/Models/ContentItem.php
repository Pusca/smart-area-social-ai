<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    protected $table = 'content_items';

    /**
     * Preferisco guarded vuoto così non ti blocchi con colonne nuove
     * (in un progetto in evoluzione come questo è più pratico).
     * Se vuoi fillable, te lo preparo dopo che fissiamo lo schema finale.
     */
    protected $guarded = [];

    protected $casts = [
        // Date
        'scheduled_at'    => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',

        // JSON / array
        'hashtags'        => 'array', // se in DB è TEXT va benissimo: Laravel salva JSON
        'ai_meta'         => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'content_plan_id');
    }

    /**
     * Normalizzazioni: se qualcuno setta hashtags come string,
     * le trasformiamo in array; se le setta come array, ok.
     * Questo evita "Array to string conversion" e mantiene coerenza.
     */
    public function setHashtagsAttribute($value): void
    {
        if (is_string($value)) {
            // accetta "#a #b" oppure "#a, #b"
            $parts = preg_split('/[\s,]+/', trim($value));
            $parts = array_values(array_filter($parts));
            $this->attributes['hashtags'] = json_encode($parts);
            return;
        }

        if (is_array($value)) {
            $this->attributes['hashtags'] = json_encode($value);
            return;
        }

        // null o altro
        $this->attributes['hashtags'] = null;
    }

    public function setAiMetaAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['ai_meta'] = json_encode($value);
            return;
        }

        if (is_string($value)) {
            // se ti arriva già json string, lo lasciamo
            $this->attributes['ai_meta'] = $value;
            return;
        }

        $this->attributes['ai_meta'] = null;
    }
}
