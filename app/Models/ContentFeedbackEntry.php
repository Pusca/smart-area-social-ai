<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContentFeedbackEntry extends Model
{
    public const SENTIMENT_LIKE = 'like';
    public const SENTIMENT_DISLIKE = 'dislike';

    public const ACTION_RECORD_ONLY = 'record_only';
    public const ACTION_REGENERATE = 'regenerate';

    public const SCOPE_VISUAL_FIRST = 'visual_first';
    public const SCOPE_COPY_FIRST = 'copy_first';
    public const SCOPE_FULL = 'full';

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_BLOCKING = 'blocking';

    /**
     * @var array<string, string>
     */
    public const STRUCTURED_CATEGORY_LABELS = [
        'too_generic' => 'Troppo generico',
        'too_salesy' => 'Troppo commerciale',
        'off_brand' => 'Fuori brand',
        'person_not_consistent' => 'Persona non coerente',
        'location_not_consistent' => 'Luogo non coerente',
        'product_deformed' => 'Prodotto deformato',
        'audio_unatural' => 'Audio poco naturale',
        'low_quality_visual' => 'Qualita visual bassa',
        'wrong_cta' => 'CTA sbagliata',
        'not_publishable' => 'Non pubblicabile',
        'other' => 'Altro',
    ];

    /**
     * @var array<string, string>
     */
    public const LEGACY_CATEGORY_LABELS = [
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

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'too_generic' => 'Troppo generico',
        'too_salesy' => 'Troppo commerciale',
        'off_brand' => 'Fuori brand',
        'person_not_consistent' => 'Persona non coerente',
        'location_not_consistent' => 'Luogo non coerente',
        'product_deformed' => 'Prodotto deformato',
        'audio_unatural' => 'Audio poco naturale',
        'low_quality_visual' => 'Qualita visual bassa',
        'wrong_cta' => 'CTA sbagliata',
        'not_publishable' => 'Non pubblicabile',
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

    /**
     * @var array<string, string>
     */
    public const SEVERITY_LABELS = [
        self::SEVERITY_LOW => 'Bassa',
        self::SEVERITY_MEDIUM => 'Media',
        self::SEVERITY_HIGH => 'Alta',
        self::SEVERITY_BLOCKING => 'Bloccante',
    ];

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'scores' => 'array',
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

    /**
     * @return array<string, string>
     */
    public static function selectableCategoryLabels(): array
    {
        return self::STRUCTURED_CATEGORY_LABELS;
    }

    /**
     * @return array<int, string>
     */
    public static function allowedCategoryValues(): array
    {
        return array_keys(self::CATEGORY_LABELS);
    }

    /**
     * @return array<int, string>
     */
    public static function allowedSeverityValues(): array
    {
        return array_keys(self::SEVERITY_LABELS);
    }

    public static function labelForCategory(?string $category): ?string
    {
        $category = Str::lower(trim((string) $category));

        if ($category === '') {
            return null;
        }

        return self::CATEGORY_LABELS[$category] ?? self::STRUCTURED_CATEGORY_LABELS[$category] ?? null;
    }

    public static function labelForSeverity(?string $severity): ?string
    {
        $severity = Str::lower(trim((string) $severity));

        if ($severity === '') {
            return null;
        }

        return self::SEVERITY_LABELS[$severity] ?? null;
    }

    public static function normalizeCategory(string $category = '', string $reason = '', string $scope = ''): string
    {
        $category = Str::lower(trim($category));
        $reason = Str::lower(trim($reason));
        $scope = Str::lower(trim($scope));

        if ($category !== '' && array_key_exists($category, self::STRUCTURED_CATEGORY_LABELS)) {
            return $category;
        }

        return match ($category) {
            'realism' => self::reasonContainsAny($reason, ['persona', 'persone', 'volto', 'viso', 'faccia', 'titolare', 'lei'])
                ? 'person_not_consistent'
                : 'low_quality_visual',
            'brand_alignment' => 'off_brand',
            'tone_of_voice' => self::reasonContainsAny($reason, ['audio', 'voce', 'voiceover', 'robotica', 'robotico', 'naturale'])
                ? 'audio_unatural'
                : (self::reasonContainsAny($reason, ['commercial', 'sales', 'pushy', 'markett'])
                    ? 'too_salesy'
                    : 'off_brand'),
            'caption_copy' => 'too_generic',
            'call_to_action' => 'wrong_cta',
            'visual_composition' => self::reasonContainsAny($reason, ['prodotto', 'deformat', 'packaging'])
                ? 'product_deformed'
                : 'low_quality_visual',
            'location_integrity' => 'location_not_consistent',
            'offer_focus' => 'too_salesy',
            'platform_fit' => 'not_publishable',
            'other', '' => self::inferCategoryFromReason($reason, $scope),
            default => self::inferCategoryFromReason($reason, $scope),
        };
    }

    public static function resolveSeverity(
        ?string $requestedSeverity,
        string $normalizedCategory,
        string $reason = '',
        string $action = '',
        string $sentiment = self::SENTIMENT_DISLIKE
    ): string {
        $requestedSeverity = Str::lower(trim((string) $requestedSeverity));
        if (in_array($requestedSeverity, self::allowedSeverityValues(), true)) {
            return $requestedSeverity;
        }

        if ($sentiment === self::SENTIMENT_LIKE) {
            return self::SEVERITY_LOW;
        }

        $reason = Str::lower(trim($reason));
        if ($normalizedCategory === 'not_publishable' || self::reasonContainsAny($reason, ['non pubblicabile', 'bloccante', 'grave', 'grave errore'])) {
            return self::SEVERITY_BLOCKING;
        }

        $base = match ($normalizedCategory) {
            'too_generic' => self::SEVERITY_LOW,
            'too_salesy', 'audio_unatural', 'low_quality_visual', 'wrong_cta', 'other' => self::SEVERITY_MEDIUM,
            'off_brand', 'person_not_consistent', 'location_not_consistent', 'product_deformed' => self::SEVERITY_HIGH,
            default => self::SEVERITY_MEDIUM,
        };

        $action = Str::lower(trim($action));
        if ($action === self::ACTION_REGENERATE && $base === self::SEVERITY_LOW) {
            return self::SEVERITY_MEDIUM;
        }

        return $base;
    }

    public function resolvedCategory(): string
    {
        $normalized = Str::lower(trim((string) ($this->normalized_category ?? '')));
        if ($normalized !== '' && array_key_exists($normalized, self::STRUCTURED_CATEGORY_LABELS)) {
            return $normalized;
        }

        return self::normalizeCategory(
            (string) ($this->category ?? ''),
            (string) ($this->reason ?? ''),
            (string) ($this->scope ?? '')
        );
    }

    public function resolvedSeverity(): string
    {
        $severity = Str::lower(trim((string) ($this->severity ?? '')));
        if (in_array($severity, self::allowedSeverityValues(), true)) {
            return $severity;
        }

        return self::resolveSeverity(
            null,
            $this->resolvedCategory(),
            (string) ($this->reason ?? ''),
            (string) ($this->action ?? ''),
            (string) ($this->sentiment ?? self::SENTIMENT_DISLIKE)
        );
    }

    public function resolvedCategoryLabel(): ?string
    {
        return self::labelForCategory($this->resolvedCategory());
    }

    public function resolvedSeverityLabel(): ?string
    {
        return self::labelForSeverity($this->resolvedSeverity());
    }

    /**
     * @return array<string, int|float>
     */
    public function scorePayload(): array
    {
        $payload = is_array($this->scores) ? $this->scores : [];

        return collect($payload)
            ->mapWithKeys(function ($value, $key): array {
                if (!is_numeric($value)) {
                    return [];
                }

                return [(string) $key => $value + 0];
            })
            ->all();
    }

    private static function inferCategoryFromReason(string $reason, string $scope): string
    {
        if ($reason === '') {
            return $scope === self::SCOPE_VISUAL_FIRST ? 'low_quality_visual' : 'other';
        }

        return match (true) {
            self::reasonContainsAny($reason, ['generic', 'generico', 'banale', 'vago']) => 'too_generic',
            self::reasonContainsAny($reason, ['sales', 'commercial', 'markett', 'pushy', 'vendita', 'promo']) => 'too_salesy',
            self::reasonContainsAny($reason, ['brand', 'tono', 'tone', 'fuori brand', 'non sembra il brand']) => 'off_brand',
            self::reasonContainsAny($reason, ['persona', 'volto', 'viso', 'faccia', 'lei', 'lui', 'titolare']) => 'person_not_consistent',
            self::reasonContainsAny($reason, ['locale', 'location', 'luogo', 'stanza', 'sala', 'ufficio', 'negozio']) => 'location_not_consistent',
            self::reasonContainsAny($reason, ['prodotto', 'packaging', 'bottiglia', 'menu', 'deformat']) => 'product_deformed',
            self::reasonContainsAny($reason, ['audio', 'voce', 'voiceover', 'robotica', 'robotico', 'naturale']) => 'audio_unatural',
            self::reasonContainsAny($reason, ['cta', 'call to action', 'invito', 'prenota', 'scrivici']) => 'wrong_cta',
            self::reasonContainsAny($reason, ['non pubblicabile', 'non pubblicherei', 'non pubblichiamo', 'imbarazzante']) => 'not_publishable',
            self::reasonContainsAny($reason, ['quality', 'qualita', 'finto', 'finte', 'scarsa', 'brutto', 'blur', 'sfocato']) => 'low_quality_visual',
            default => $scope === self::SCOPE_VISUAL_FIRST ? 'low_quality_visual' : 'other',
        };
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function reasonContainsAny(string $reason, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }
}


