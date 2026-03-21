<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class TextProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('text');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('text');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('text', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('text', $preferred, $fallback);
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}