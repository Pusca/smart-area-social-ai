<?php

namespace App\Services\AI;

class ProviderCapabilityRegistry
{
    /**
     * @return array<int, string>
     */
    public function allowedProviders(string $area): array
    {
        $area = $this->canonicalArea($area);
        $paths = (array) data_get(config('provider_capabilities.areas'), $area . '.providers_config', []);
        $providers = [];

        foreach ($paths as $path) {
            $providers = array_merge($providers, (array) config($path, []));
        }

        if ($providers === []) {
            $path = (string) data_get(config('provider_capabilities.areas'), $area . '.providers_config', '');
            $providers = (array) config($path, []);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($provider) => strtolower(trim((string) $provider)),
            $providers
        ), fn ($provider) => is_string($provider)
            && $provider !== ''
            && $this->supportsArea($provider, $area))));
    }

    public function defaultProvider(string $area): string
    {
        $area = $this->canonicalArea($area);
        $path = (string) data_get(config('provider_capabilities.areas'), $area . '.default_provider_config', '');
        $default = strtolower(trim((string) config($path, '')));

        if ($default !== '' && in_array($default, $this->allowedProviders($area), true)) {
            return $default;
        }

        return $this->allowedProviders($area)[0] ?? '';
    }

    public function normalizeProvider(string $area, string $provider): string
    {
        $area = $this->canonicalArea($area);
        $provider = strtolower(trim($provider));

        if ($provider !== '' && in_array($provider, $this->allowedProviders($area), true)) {
            return $provider;
        }

        return $this->defaultProvider($area);
    }

    public function resolveProvider(string $area, string $preferred = '', ?string $fallback = null): string
    {
        $candidate = trim($preferred) !== '' ? $preferred : (string) $fallback;

        return $this->normalizeProvider($area, $candidate);
    }

    public function resolveConfiguredProvider(string $area, string $preferred = '', ?string $fallback = null): string
    {
        $area = $this->canonicalArea($area);
        $candidates = array_values(array_unique(array_filter([
            trim($preferred) !== '' ? strtolower(trim($preferred)) : null,
            trim((string) $fallback) !== '' ? strtolower(trim((string) $fallback)) : null,
            $this->defaultProvider($area),
            ...$this->allowedProviders($area),
        ])));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->isConfigured($candidate, $area)) {
                return $candidate;
            }
        }

        return $this->defaultProvider($area);
    }

    public function supportsArea(string $provider, string $area): bool
    {
        return data_get($this->providerConfig($provider), 'areas.' . $this->canonicalArea($area)) !== null;
    }

    public function isConfigured(string $provider, string $area = ''): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return false;
        }

        if ($area !== '' && !$this->supportsArea($provider, $area)) {
            return false;
        }

        $credentialPaths = (array) data_get($this->providerConfig($provider), 'credential_paths', []);
        if ($credentialPaths === []) {
            return false;
        }

        foreach ($credentialPaths as $path) {
            if (trim((string) config((string) $path, '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function defaultModel(string $provider, string $area, array $context = []): string
    {
        $provider = strtolower(trim($provider));
        $area = $this->canonicalArea($area);
        $areaConfig = $this->areaCapability($provider, $area);

        if ($provider === 'kling' && $area === 'video') {
            $contextMode = strtolower(trim((string) ($context['mode'] ?? '')));
            if ($contextMode !== '') {
                $contextModel = (string) data_get($areaConfig, 'context_models.' . $contextMode, '');
                if ($contextModel !== '') {
                    return $contextModel;
                }
            }
        }

        $modelPath = (string) data_get($areaConfig, 'model_config', '');

        return trim((string) config($modelPath, ''));
    }

    public function normalizeModel(string $provider, string $area, string $model = '', array $context = []): string
    {
        $provider = strtolower(trim($provider));
        $area = $this->canonicalArea($area);
        $model = strtolower(trim($model));
        $areaConfig = $this->areaCapability($provider, $area);
        $aliases = (array) data_get($areaConfig, 'model_aliases', []);
        if ($model !== '' && isset($aliases[$model])) {
            $model = strtolower(trim((string) $aliases[$model]));
        }

        if ($provider === 'openai' && $area === 'video') {
            $configured = strtolower(trim($this->defaultModel('openai', 'video', $context)));
            $candidate = $model !== '' ? $model : $configured;

            return str_starts_with($candidate, 'sora-2') ? $candidate : 'sora-2';
        }

        if ($provider === 'runway' && $area === 'video') {
            $configured = strtolower(trim($this->defaultModel('runway', 'video', $context)));
            $candidate = $model !== '' ? $model : $configured;
            if ($candidate === '' || str_starts_with($candidate, 'sora-') || str_starts_with($candidate, 'kling-')) {
                return 'gen4.5';
            }

            return isset($aliases[$candidate]) ? strtolower(trim((string) $aliases[$candidate])) : $candidate;
        }

        if ($provider === 'kling' && $area === 'video') {
            if ($model !== '' && str_starts_with($model, 'kling-')) {
                return $model;
            }

            $configured = strtolower(trim($this->defaultModel('kling', 'video', $context)));
            if ($configured !== '' && str_starts_with($configured, 'kling-')) {
                return $configured;
            }

            $contextMode = strtolower(trim((string) ($context['mode'] ?? '')));
            if ($contextMode !== '') {
                return strtolower(trim((string) data_get($areaConfig, 'context_models.' . $contextMode, '')));
            }

            return '';
        }

        if ($model !== '') {
            return $model;
        }

        return strtolower(trim($this->defaultModel($provider, $area, $context)));
    }

    /**
     * @return array<int, int>
     */
    public function allowedVideoDurations(string $provider, string $model = '', array $context = []): array
    {
        $rule = $this->videoDurationRule($provider, $model, $context);
        if ($rule === []) {
            return [];
        }

        if (($rule['mode'] ?? null) === 'range') {
            $min = max(1, (int) ($rule['min'] ?? 1));
            $max = max($min, (int) ($rule['max'] ?? $min));

            return range($min, $max);
        }

        return array_values(array_unique(array_map(
            fn ($seconds) => (int) $seconds,
            (array) ($rule['values'] ?? [])
        )));
    }

    public function normalizeVideoDuration(string $provider, int $seconds, string $model = '', array $context = []): int
    {
        $provider = strtolower(trim($provider));
        $seconds = max(1, $seconds);
        $rule = $this->videoDurationRule($provider, $model, $context);
        if ($rule === []) {
            return $seconds;
        }

        if (($rule['mode'] ?? null) === 'range') {
            $min = max(1, (int) ($rule['min'] ?? 1));
            $max = max($min, (int) ($rule['max'] ?? $min));

            return max($min, min($max, $seconds));
        }

        $values = $this->allowedVideoDurations($provider, $model, $context);
        if ($values === []) {
            return $seconds;
        }

        sort($values);

        if ($provider === 'openai') {
            foreach ($values as $value) {
                if ($seconds <= $value) {
                    return $value;
                }
            }

            return max($values);
        }

        if ($provider === 'runway' && str_starts_with($this->normalizeModel($provider, 'video', $model, $context), 'veo3')) {
            if ($seconds < 6) {
                return 4;
            }

            if ($seconds < 8) {
                return 6;
            }

            return 8;
        }

        if ($provider === 'kling') {
            return $seconds >= 8 ? 10 : 5;
        }

        $closest = $values[0];
        foreach ($values as $value) {
            if (abs($seconds - $value) < abs($seconds - $closest)) {
                $closest = $value;
            }
        }

        return $closest;
    }

    public function maxVideoDuration(string $provider, string $model = '', array $context = []): int
    {
        $durations = $this->allowedVideoDurations($provider, $model, $context);

        return $durations !== [] ? max($durations) : 0;
    }

    /**
     * @return array<int, string>
     */
    public function allowedAspectRatios(string $provider, string $area = 'video', string $model = ''): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($ratio) => trim((string) $ratio),
            (array) data_get($this->areaCapability($provider, $area), 'aspect_ratios', [])
        ))));
    }

    /**
     * @return array<int, string>
     */
    public function allowedSizes(string $provider, string $area = 'video', string $model = ''): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($size) => trim((string) $size),
            (array) data_get($this->areaCapability($provider, $area), 'sizes', [])
        ))));
    }

    public function requestTimeout(string $provider, string $area): int
    {
        $path = (string) data_get($this->areaCapability($provider, $area), 'timeouts.request', '');

        return max(0, (int) config($path, 0));
    }

    public function pollTimeout(string $provider, string $area): int
    {
        $path = (string) data_get($this->areaCapability($provider, $area), 'timeouts.poll', '');

        return max(0, (int) config($path, 0));
    }

    public function supportsReferenceAware(string $provider, string $area): bool
    {
        return (bool) data_get($this->areaCapability($provider, $area), 'reference_aware', false);
    }

    public function supportsImageToVideo(string $provider): bool
    {
        return (bool) data_get($this->areaCapability($provider, 'video'), 'image_to_video', false);
    }

    public function supportsTextToVideo(string $provider): bool
    {
        return (bool) data_get($this->areaCapability($provider, 'video'), 'text_to_video', false);
    }

    public function supportsStrictAssetMode(string $provider, string $area): bool
    {
        return (bool) data_get($this->areaCapability($provider, $area), 'strict_asset_mode', false);
    }

    public function requiresMux(string $provider, string $area = 'video'): bool
    {
        return (bool) data_get($this->areaCapability($provider, $area), 'requires_mux', false);
    }

    public function supportsNativeAudio(string $provider, string $area = 'video'): bool
    {
        return (bool) data_get($this->areaCapability($provider, $area), 'native_audio', false);
    }

    /**
     * @return array<int, string>
     */
    public function fallbackProviders(string $provider, string $area, bool $locked = false): array
    {
        if ($locked) {
            return [];
        }

        return array_values(array_filter(
            (array) data_get($this->areaCapability($provider, $area), 'fallbacks', []),
            fn ($fallback) => is_string($fallback)
                && trim($fallback) !== ''
                && $this->supportsArea((string) $fallback, $area)
                && $this->isConfigured((string) $fallback, $area)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilityMismatch(string $provider, string $area, array $requirements = []): array
    {
        $issues = [];

        if (!$this->supportsArea($provider, $area)) {
            $issues[] = 'unsupported_area';
        }

        if (!$this->isConfigured($provider, $area)) {
            $issues[] = 'provider_not_configured';
        }

        if (($requirements['reference_aware'] ?? false) && !$this->supportsReferenceAware($provider, $area)) {
            $issues[] = 'reference_aware_not_supported';
        }

        if (($requirements['strict_asset_mode'] ?? false) && !$this->supportsStrictAssetMode($provider, $area)) {
            $issues[] = 'strict_asset_mode_not_supported';
        }

        if (($requirements['image_to_video'] ?? false) && !$this->supportsImageToVideo($provider)) {
            $issues[] = 'image_to_video_not_supported';
        }

        if (($requirements['text_to_video'] ?? false) && !$this->supportsTextToVideo($provider)) {
            $issues[] = 'text_to_video_not_supported';
        }

        $duration = (int) ($requirements['duration_seconds'] ?? 0);
        if ($area === 'video' && $duration > 0) {
            $normalized = $this->normalizeVideoDuration(
                $provider,
                $duration,
                (string) ($requirements['model'] ?? ''),
                $requirements
            );

            if ($normalized !== $duration) {
                $issues[] = 'duration_not_supported';
            }
        }

        return [
            'provider' => strtolower(trim($provider)),
            'area' => $this->canonicalArea($area),
            'ok' => $issues === [],
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $provider, string $area, array $context = []): array
    {
        $provider = strtolower(trim($provider));
        $area = $this->canonicalArea($area);
        $model = $this->normalizeModel($provider, $area, (string) ($context['model'] ?? ''), $context);

        return [
            'provider' => $provider,
            'configured' => $this->isConfigured($provider, $area),
            'supports_area' => $this->supportsArea($provider, $area),
            'default_model' => $this->defaultModel($provider, $area, $context),
            'effective_model' => $model,
            'reference_aware' => $this->supportsReferenceAware($provider, $area),
            'image_to_video' => $area === 'video' ? $this->supportsImageToVideo($provider) : false,
            'text_to_video' => $area === 'video' ? $this->supportsTextToVideo($provider) : false,
            'strict_asset_mode' => $this->supportsStrictAssetMode($provider, $area),
            'native_audio' => $this->supportsNativeAudio($provider, $area),
            'requires_mux' => $this->requiresMux($provider, $area),
            'allowed_durations' => $area === 'video' ? $this->allowedVideoDurations($provider, $model, $context) : [],
            'max_duration_seconds' => $area === 'video' ? $this->maxVideoDuration($provider, $model, $context) : 0,
            'aspect_ratios' => $this->allowedAspectRatios($provider, $area, $model),
            'sizes' => $this->allowedSizes($provider, $area, $model),
            'request_timeout' => $this->requestTimeout($provider, $area),
            'poll_timeout' => $this->pollTimeout($provider, $area),
            'fallbacks' => $this->fallbackProviders($provider, $area, false),
        ];
    }

    private function canonicalArea(string $area): string
    {
        $area = strtolower(trim($area));

        return (string) data_get(config('provider_capabilities.area_aliases', []), $area, $area);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(string $provider): array
    {
        return (array) data_get(config('provider_capabilities.providers', []), strtolower(trim($provider)), []);
    }

    /**
     * @return array<string, mixed>
     */
    private function areaCapability(string $provider, string $area): array
    {
        return (array) data_get($this->providerConfig($provider), 'areas.' . $this->canonicalArea($area), []);
    }

    /**
     * @return array<string, mixed>
     */
    private function videoDurationRule(string $provider, string $model = '', array $context = []): array
    {
        $provider = strtolower(trim($provider));
        $normalizedModel = $this->normalizeModel($provider, 'video', $model, $context);
        $areaConfig = $this->areaCapability($provider, 'video');

        if ($provider === 'runway') {
            $rules = (array) data_get($areaConfig, 'duration_rules', []);
            foreach ($rules as $prefix => $rule) {
                if ($prefix !== 'default' && str_starts_with($normalizedModel, strtolower(trim((string) $prefix)))) {
                    return (array) $rule;
                }
            }

            return (array) ($rules['default'] ?? []);
        }

        if ($provider === 'kling') {
            $rules = (array) data_get($areaConfig, 'duration_rules', []);
            foreach ($rules as $prefix => $rule) {
                if ($prefix !== 'default' && str_starts_with($normalizedModel, strtolower(trim((string) $prefix)))) {
                    return (array) $rule;
                }
            }

            if ($rules !== []) {
                return (array) ($rules['default'] ?? []);
            }
        }

        return (array) data_get($areaConfig, 'duration_rule', []);
    }
}
