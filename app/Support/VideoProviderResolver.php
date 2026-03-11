<?php

namespace App\Support;

class VideoProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        $providers = (array) config('generation.video_providers', ['openai', 'runway']);
        $providers = array_values(array_filter(array_map(
            fn ($p) => strtolower(trim((string) $p)),
            $providers
        )));

        return array_values(array_unique($providers));
    }

    public static function default(): string
    {
        $default = strtolower(trim((string) config('generation.video_provider_default', 'openai')));
        if (in_array($default, self::allowed(), true)) {
            return $default;
        }

        return 'openai';
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, self::allowed(), true)) {
            return $value;
        }

        return self::default();
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        $candidate = trim($preferred) !== '' ? $preferred : (string) $fallback;

        return self::normalize($candidate);
    }

    public static function inRule(): string
    {
        return 'in:' . implode(',', self::allowed());
    }
}

