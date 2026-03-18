<?php

namespace App\Support;

class SpeechProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        $providers = (array) config('generation.speech_providers', ['openai']);
        $providers = array_values(array_filter(array_map(
            fn ($provider) => strtolower(trim((string) $provider)),
            $providers
        )));

        return array_values(array_unique($providers));
    }

    public static function default(): string
    {
        $default = strtolower(trim((string) config('generation.speech_provider_default', 'openai')));
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
}