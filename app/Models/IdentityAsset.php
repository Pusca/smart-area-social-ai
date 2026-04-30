<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentityAsset extends Model
{
    public const TYPE_PERSON       = 'person';
    public const TYPE_PRODUCT      = 'product';
    public const TYPE_LOCATION     = 'location';
    public const TYPE_BRAND_OBJECT = 'brand_object';

    public const MEDIA_ROLE_CANONICAL  = 'canonical';
    public const MEDIA_ROLE_REFERENCE  = 'reference';
    public const MEDIA_ROLE_NEGATIVE   = 'negative';

    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'description',
        'locked_visual_identity',
        'identity_prompt',
        'identity_prompt_version',
        'visual_features_json',
        'usage_rules_json',
        'consistency_rules_json',
        'is_active',
    ];

    protected $casts = [
        'locked_visual_identity'  => 'boolean',
        'is_active'               => 'boolean',
        'identity_prompt_version' => 'integer',
        'visual_features_json'    => 'array',
        'usage_rules_json'        => 'array',
        'consistency_rules_json'  => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relazioni
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Tutti i media associati (pivot con role e sort_order). */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(BrandAsset::class, 'identity_asset_media')
            ->withPivot(['role', 'sort_order', 'provider_hints'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** Solo il/i media canonici (massimo 1 previsto, ma flessibile). */
    public function canonicalMedia(): BelongsToMany
    {
        return $this->media()->wherePivot('role', self::MEDIA_ROLE_CANONICAL);
    }

    /** Media di riferimento (usati per scoring e prompt building). */
    public function referenceMedia(): BelongsToMany
    {
        return $this->media()->wherePivot('role', self::MEDIA_ROLE_REFERENCE);
    }

    /** Esempi negativi (da escludere attivamente). */
    public function negativeMedia(): BelongsToMany
    {
        return $this->media()->wherePivot('role', self::MEDIA_ROLE_NEGATIVE);
    }

    /** AssetVariable che puntano a questo IdentityAsset. */
    public function assetVariables(): HasMany
    {
        return $this->hasMany(AssetVariable::class);
    }

    /** AlterEgo che usano questo IdentityAsset come persona reale. */
    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('locked_visual_identity', true);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /** Verifica se il prompt cache è da rigenerare. */
    public function needsRecompile(): bool
    {
        return empty($this->identity_prompt);
    }

    /** Restituisce il path assoluto del media canonico (null se assente). */
    public function canonicalPath(): ?string
    {
        $asset = $this->canonicalMedia()->first();
        if (! $asset) {
            return null;
        }
        $path = storage_path('app/public/' . ltrim($asset->path, '/'));
        return file_exists($path) ? $path : null;
    }

    /** Restituisce i path assoluti dei media di riferimento filtrati per provider. */
    public function referencePathsForProvider(string $provider): array
    {
        return $this->referenceMedia()
            ->get()
            ->filter(function (BrandAsset $asset) use ($provider): bool {
                $hints = $asset->pivot->provider_hints
                    ? (is_array($asset->pivot->provider_hints)
                        ? $asset->pivot->provider_hints
                        : json_decode($asset->pivot->provider_hints, true))
                    : null;
                // Se nessun hint specifico per il provider, includi sempre
                return $hints === null || ($hints[$provider] ?? true);
            })
            ->map(function (BrandAsset $asset): ?string {
                $path = storage_path('app/public/' . ltrim($asset->path, '/'));
                return file_exists($path) ? $path : null;
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /** Soglia di confidenza per l'IdentityGuard (default per tipo). */
    public function guardConfidenceThreshold(): float
    {
        $rules = $this->consistency_rules_json ?? [];
        if (isset($rules['min_confidence'])) {
            return (float) $rules['min_confidence'];
        }
        return match ($this->type) {
            self::TYPE_PERSON       => 0.78,
            self::TYPE_PRODUCT      => 0.76,
            self::TYPE_LOCATION     => 0.74,
            self::TYPE_BRAND_OBJECT => 0.72,
            default                 => 0.72,
        };
    }

    /** True se l'IdentityGuard deve bloccare (non solo avvisare) i fallimenti. */
    public function guardIsBlocking(): bool
    {
        if (! $this->locked_visual_identity) {
            return false;
        }
        $rules = $this->consistency_rules_json ?? [];
        return $rules['blocking'] ?? true;
    }
}
