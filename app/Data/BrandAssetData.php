<?php

namespace App\Data;

/**
 * Value object representing a brand asset with optional AI understanding.
 *
 * Constructed from the BrandAsset model (with AI fields) or from the legacy
 * ai_meta['brand_assets'] array. Downstream pipeline steps should read from
 * this VO instead of raw array keys.
 */
final class BrandAssetData
{
    /**
     * @param  array<int, string>  $aiTags
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $kind,
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly string $aiDescription = '',
        public readonly string $aiContext     = '',
        public readonly array  $aiTags        = [],
    ) {
    }

    public function isImage(): bool
    {
        return in_array($this->kind, ['image', 'logo'], true);
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    public function hasAiUnderstanding(): bool
    {
        return $this->aiDescription !== '';
    }

    /**
     * Compact text for AI prompts: "logo — blue sports-car brand (product) [auto, lusso, velocità]"
     */
    public function toPromptHint(): string
    {
        $parts = [];

        if ($this->aiDescription !== '') {
            $parts[] = $this->aiDescription;
        }

        if ($this->aiContext !== '' && $this->aiContext !== 'generic') {
            $parts[] = "({$this->aiContext})";
        }

        if (!empty($this->aiTags)) {
            $parts[] = '[' . implode(', ', array_slice($this->aiTags, 0, 5)) . ']';
        }

        $hint = implode(' ', $parts);

        return $hint !== '' ? "[{$this->kind}] {$hint}" : "[{$this->kind}] {$this->originalName}";
    }

    /** @return array<string, mixed> */
    public function toLegacyArray(): array
    {
        return [
            'id'            => $this->id,
            'kind'          => $this->kind,
            'path'          => $this->path,
            'original_name' => $this->originalName,
            'mime'          => $this->mime,
        ];
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id:            (int) ($row['id'] ?? 0),
            kind:          (string) ($row['kind'] ?? ''),
            path:          (string) ($row['path'] ?? ''),
            originalName:  (string) ($row['original_name'] ?? ''),
            mime:          (string) ($row['mime'] ?? ''),
            aiDescription: (string) ($row['ai_description'] ?? ''),
            aiContext:     (string) ($row['ai_context'] ?? ''),
            aiTags:        array_values(array_filter(array_map('strval', (array) ($row['ai_tags'] ?? [])))),
        );
    }

    public static function fromModel(\App\Models\BrandAsset $asset): self
    {
        return new self(
            id:            (int) $asset->id,
            kind:          (string) $asset->kind,
            path:          (string) $asset->path,
            originalName:  (string) ($asset->original_name ?? ''),
            mime:          (string) ($asset->mime ?? ''),
            aiDescription: (string) ($asset->ai_description ?? ''),
            aiContext:     (string) ($asset->ai_context ?? ''),
            aiTags:        is_array($asset->ai_tags) ? array_values(array_filter(array_map('strval', $asset->ai_tags))) : [],
        );
    }
}
