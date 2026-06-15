<?php

namespace App\Services\Overlays;

final class ContentOverlayFontRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function fonts(): array
    {
        return (array) config('overlays.fonts', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveFontConfig(?string $family): array
    {
        $fonts = $this->fonts();
        $key = trim((string) $family);

        if ($key !== '' && isset($fonts[$key])) {
            return $fonts[$key];
        }

        return $fonts['arial'] ?? reset($fonts) ?: [];
    }

    public function resolveFallbackFamily(?string $family, ?string $explicitFallback = null): string
    {
        $fonts = $this->fonts();
        $explicitFallback = trim((string) $explicitFallback);
        if ($explicitFallback !== '' && isset($fonts[$explicitFallback])) {
            return $explicitFallback;
        }

        $resolved = $this->resolveFontConfig($family);
        $fallback = trim((string) ($resolved['fallback_family'] ?? ''));

        if ($fallback !== '' && isset($fonts[$fallback])) {
            return $fallback;
        }

        return 'arial';
    }

    public function resolveFontPath(?string $family, string|int|null $weight = null, ?string $fallbackFamily = null): ?string
    {
        $resolvedFamily = trim((string) $family);
        $isBold = (int) $weight >= 600;

        foreach ([$resolvedFamily, $this->resolveFallbackFamily($resolvedFamily, $fallbackFamily), 'arial'] as $candidateFamily) {
            $config = $this->resolveFontConfig($candidateFamily);
            $paths = $isBold
                ? (array) ($config['bold_paths'] ?? [])
                : (array) ($config['regular_paths'] ?? []);

            foreach ($paths as $path) {
                $path = trim((string) $path);
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
