<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class VideoProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('video');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('video');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('video', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('video', $preferred, $fallback);
    }

    public static function inRule(): string
    {
        return 'in:' . implode(',', self::allowed());
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}