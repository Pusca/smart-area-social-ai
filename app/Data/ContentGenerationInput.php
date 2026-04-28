<?php

namespace App\Data;

use App\Models\BrandAsset;
use App\Models\ContentItem;

/**
 * Unified, typed input contract for the AI generation pipeline.
 *
 * Built once at the start of BuildGenerationContextStep and stored on
 * GenerationPipelineState::$input. All pipeline steps should read from
 * this DTO rather than reaching into raw ai_meta arrays.
 *
 * Fields deliberately map 1-to-1 to what the user chose at creation time
 * (brief, platforms, providers, etc.) plus what the system resolved from
 * the tenant database (brand assets with AI understanding, variables).
 */
final class ContentGenerationInput
{
    /**
     * @param  array<int, string>          $platforms
     * @param  array<int, BrandAssetData>  $brandAssets
     * @param  array<int, AssetVariableData> $assetVariables
     * @param  array<int, int>             $referenceAssetIds
     * @param  array<string, mixed>        $strategy
     * @param  array<string, mixed>        $overlayPreferences
     * @param  array<string, mixed>        $tenantProfile
     */
    public function __construct(
        // ── Identity ────────────────────────────────────────────────────────
        public readonly int     $tenantId,
        public readonly int     $contentItemId,
        public readonly ?int    $contentPlanId,

        // ── Content intent ───────────────────────────────────────────────────
        public readonly string  $format,
        public readonly array   $platforms,
        public readonly string  $brief,
        public readonly string  $goalHint,

        // ── Brand assets (with AI understanding) ─────────────────────────────
        public readonly array   $brandAssets,

        // ── Variables (personas, products, locations) ─────────────────────────
        public readonly array   $assetVariables,
        public readonly array   $referenceAssetIds,

        // ── AI providers ─────────────────────────────────────────────────────
        public readonly string  $imageProvider,
        public readonly string  $videoProvider,

        // ── Alter ego ────────────────────────────────────────────────────────
        public readonly ?int    $alterEgoId,

        // ── Strategy / brand profile ─────────────────────────────────────────
        public readonly array   $strategy,
        public readonly array   $tenantProfile,
        public readonly array   $overlayPreferences,

        // ── Pipeline flags ────────────────────────────────────────────────────
        public readonly bool    $strictAssetMode,
        public readonly int     $videoDurationSeconds,
    ) {
    }

    // ── Convenience accessors ───────────────────────────────────────────────

    /** @return array<int, BrandAssetData> */
    public function imageAssets(): array
    {
        return array_values(array_filter($this->brandAssets, fn (BrandAssetData $a) => $a->isImage()));
    }

    /** @return array<int, BrandAssetData> */
    public function videoAssets(): array
    {
        return array_values(array_filter($this->brandAssets, fn (BrandAssetData $a) => $a->isVideo()));
    }

    /** @return array<int, BrandAssetData> */
    public function assetsWithAiUnderstanding(): array
    {
        return array_values(array_filter($this->brandAssets, fn (BrandAssetData $a) => $a->hasAiUnderstanding()));
    }

    public function primaryPlatform(): string
    {
        return strtolower($this->platforms[0] ?? '');
    }

    public function isReel(): bool
    {
        return $this->format === 'reel';
    }

    public function isVideo(): bool
    {
        return in_array($this->format, ['reel', 'story'], true);
    }

    /**
     * Compact AI-ready summary of all assets with understanding.
     * Used to enrich generation prompts without full asset dumps.
     */
    public function assetPromptHints(): string
    {
        $hints = array_map(
            fn (BrandAssetData $a) => $a->toPromptHint(),
            $this->assetsWithAiUnderstanding()
        );

        return implode("\n", $hints);
    }

    /**
     * Variable mentions for the brief injection layer.
     * Returns "@persona_name (Persona: Marco Rossi) — descrizione"
     */
    public function variablePromptMentions(): string
    {
        $mentions = array_map(
            fn (AssetVariableData $v) => $v->toPromptMention(),
            $this->assetVariables
        );

        return implode("\n", $mentions);
    }

    // ── Factory ─────────────────────────────────────────────────────────────

    /**
     * Build from a ContentItem and its resolved ai_meta array.
     * Called at the start of BuildGenerationContextStep after meta is enriched
     * with live brand assets from the database.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromItemAndMeta(ContentItem $item, array $meta, bool $strictAssetMode = true): self
    {
        // ── Platforms ──────────────────────────────────────────────────────
        $platforms = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($item->platform ?? ''))
        )));
        if (empty($platforms)) {
            $platforms = ['instagram'];
        }

        // ── Brief ──────────────────────────────────────────────────────────
        $brief = trim((string) ($item->caption ?: data_get($meta, 'manual_brief', $item->title ?: '')));

        // ── Brand assets (with AI fields from live DB load) ────────────────
        $rawAssets   = (array) data_get($meta, 'brand_assets', []);
        $brandAssets = array_values(array_filter(array_map(
            function (mixed $row): ?BrandAssetData {
                if (!is_array($row) || empty($row['path'])) {
                    return null;
                }
                return BrandAssetData::fromArray($row);
            },
            $rawAssets
        )));

        // ── Asset variables ────────────────────────────────────────────────
        $rawCatalog     = (array) data_get($meta, 'asset_variables_catalog', []);
        $assetVariables = array_values(array_filter(array_map(
            function (mixed $row): ?AssetVariableData {
                if (!is_array($row) || empty($row['name'])) {
                    return null;
                }
                return AssetVariableData::fromArray($row);
            },
            $rawCatalog
        )));

        // ── Reference asset IDs ────────────────────────────────────────────
        $referenceAssetIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => (int) $id,
            (array) data_get($meta, 'reference_asset_ids', [])
        ))));

        // ── Providers ──────────────────────────────────────────────────────
        $imageProvider = (string) data_get($meta, 'image_provider', '');
        $videoProvider = (string) data_get($meta, 'video_provider', '');

        // ── Strategy + profile ─────────────────────────────────────────────
        $strategy    = (array) data_get($meta, 'strategy', $item->plan?->strategy ?? []);
        $tenantProfile = (array) data_get($meta, 'tenant_profile', data_get($meta, 'brand', []));
        $overlayPrefs  = (array) data_get($meta, 'overlay_preferences', []);

        // ── Duration ───────────────────────────────────────────────────────
        $duration = (int) data_get($meta, 'video_duration_seconds_requested', 0);

        return new self(
            tenantId:             (int) $item->tenant_id,
            contentItemId:        (int) $item->id,
            contentPlanId:        $item->content_plan_id ? (int) $item->content_plan_id : null,
            format:               (string) $item->format,
            platforms:            $platforms,
            brief:                $brief,
            goalHint:             (string) data_get($meta, 'goal_hint', ''),
            brandAssets:          $brandAssets,
            assetVariables:       $assetVariables,
            referenceAssetIds:    $referenceAssetIds,
            imageProvider:        $imageProvider,
            videoProvider:        $videoProvider,
            alterEgoId:           $item->alter_ego_id ? (int) $item->alter_ego_id : null,
            strategy:             $strategy,
            tenantProfile:        $tenantProfile,
            overlayPreferences:   $overlayPrefs,
            strictAssetMode:      $strictAssetMode,
            videoDurationSeconds: $duration,
        );
    }
}
