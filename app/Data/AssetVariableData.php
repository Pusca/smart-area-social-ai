<?php

namespace App\Data;

/**
 * Value object representing a brand variable (person, product, location, custom).
 *
 * Variables are the named entities injected into generation — presenters,
 * products on-screen, shooting locations. Each carries media assets
 * (images/video/audio) and an optional description used in prompts.
 */
final class AssetVariableData
{
    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $slug,
        public readonly string $description,
        public readonly array  $assets       = [],
    ) {
    }

    public function isPerson(): bool   { return $this->kind === 'person'; }
    public function isProduct(): bool  { return $this->kind === 'product'; }
    public function isLocation(): bool { return $this->kind === 'location'; }

    /**
     * Short mention for AI prompts: "@ferrari — Prodotto: supercar rossa GT."
     */
    public function toPromptMention(): string
    {
        $prefix = '@' . ($this->slug ?: strtolower(str_replace(' ', '_', $this->name)));
        $kindMap = ['person' => 'Persona', 'product' => 'Prodotto', 'location' => 'Luogo'];
        $kindLabel = $kindMap[$this->kind] ?? ucfirst($this->kind);

        $parts = ["{$prefix} ({$kindLabel}: {$this->name})"];
        if ($this->description !== '') {
            $parts[] = $this->description;
        }

        return implode(' — ', $parts);
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id:          (int) ($row['id'] ?? 0),
            name:        (string) ($row['name'] ?? ''),
            kind:        (string) ($row['kind'] ?? 'custom'),
            slug:        (string) ($row['slug'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            assets:      is_array($row['assets'] ?? null) ? (array) $row['assets'] : [],
        );
    }
}
