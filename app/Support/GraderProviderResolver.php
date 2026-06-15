<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class GraderProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('grader');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('grader');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('grader', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('grader', $preferred, $fallback);
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}