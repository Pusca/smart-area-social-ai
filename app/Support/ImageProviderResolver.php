<?php

namespace App\Support;

class ImageProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        $providers = (array) config('generation.image_providers', ['nanobanana', 'openai']);
        $providers = array_values(array_filter(array_map(
            fn ($provider) => strtolower(trim((string) $provider)),
            $providers
        )));

        return array_values(array_unique($providers));
    }

    public static function default(): string
    {
        $default = strtolower(trim((string) config('generation.image_provider_default', 'nanobanana')));
        if (in_array($default, self::allowed(), true)) {
            return $default;
        }

        return 'nanobanana';
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
