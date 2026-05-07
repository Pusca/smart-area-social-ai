<?php

namespace App\Support;

use App\Services\AI\ProviderCapabilityRegistry;

class ImageProviderResolver
{
    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return self::registry()->allowedProviders('image');
    }

    public static function default(): string
    {
        return self::registry()->defaultProvider('image');
    }

    public static function normalize(string $value): string
    {
        return self::registry()->normalizeProvider('image', $value);
    }

    public static function resolve(string $preferred, ?string $fallback = null): string
    {
        return self::registry()->resolveProvider('image', $preferred, $fallback);
    }

    public static function inRule(): string
    {
        return 'in:' . implode(',', self::allowed());
    }

    /**
     * @return array<int, string>  Solo i provider con API key configurata.
     */
    public static function configured(): array
    {
        return array_values(array_filter(
            self::allowed(),
            fn ($p) => self::registry()->isConfigured($p, 'image')
        ));
    }

    /**
     * @return array<string, string>  ['provider_key' => 'Label'] per i soli provider configurati.
     */
    public static function configuredWithLabels(): array
    {
        $labels = [
            'nanobanana' => 'Gemini 2.5 Flash (Google)',
            'luma'       => 'Luma Photon',
            'runway'     => 'Runway Gen-4 Image',
        ];

        $result = [];
        foreach (self::configured() as $provider) {
            $result[$provider] = $labels[$provider] ?? ucfirst($provider);
        }

        if (empty($result)) {
            $result[self::default()] = $labels[self::default()] ?? ucfirst(self::default());
        }

        return $result;
    }

    private static function registry(): ProviderCapabilityRegistry
    {
        return app(ProviderCapabilityRegistry::class);
    }
}