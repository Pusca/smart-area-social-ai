<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class SpeechProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('speech');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('speech');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('speech', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('speech', $preferred, $fallback);
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}