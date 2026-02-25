<?php

namespace App\Services\Editorial;

use App\Models\EditorialStrategy;
use App\Models\TenantProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EditorialStrategyService
{
    public function refreshForTenant(int $tenantId, ?TenantProfile $profile = null, array $overrides = []): EditorialStrategy
    {
        $profile ??= TenantProfile::query()->where('tenant_id', $tenantId)->first();

        $voice = [
            'tone' => (string) ($profile?->default_tone ?? 'professionale'),
            'values' => $this->explode((string) ($profile?->values ?? '')),
            'target' => (string) ($profile?->target ?? ''),
            'industry' => (string) ($profile?->industry ?? ''),
        ];

        $pillars = $this->buildPillars($profile);
        $rubrics = $this->buildRubrics($pillars);
        $ctaRules = $this->buildCtaRules($profile);
        $constraints = [
            'no_repeat_days' => config('editorial.duplicate_window_days', 180),
            'soft_similarity_threshold' => config('editorial.soft_similarity_threshold', 0.78),
            'max_regeneration_attempts' => config('editorial.max_regeneration_attempts', 2),
            'pillar_repeat_limit' => 2,
            'cta_repeat_limit' => 1,
        ];

        $payload = [
            'brand_voice' => array_merge($voice, Arr::get($overrides, 'brand_voice', [])),
            'pillars' => Arr::get($overrides, 'pillars', $pillars),
            'rubrics' => Arr::get($overrides, 'rubrics', $rubrics),
            'cta_rules' => Arr::get($overrides, 'cta_rules', $ctaRules),
            'constraints' => array_merge($constraints, Arr::get($overrides, 'constraints', [])),
            'last_refreshed_at' => Carbon::now(),
        ];

        return EditorialStrategy::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            $payload
        );
    }

    public function forTenant(int $tenantId): ?EditorialStrategy
    {
        return EditorialStrategy::query()->where('tenant_id', $tenantId)->first();
    }

    private function buildPillars(?TenantProfile $profile): array
    {
        $services = $this->explode((string) ($profile?->services ?? ''));
        $industry = Str::title((string) ($profile?->industry ?? 'Attivita'));

        $fallback = [
            "Guide {$industry}",
            "Case Study {$industry}",
            "Dietro le Quinte {$industry}",
            "Offerta {$industry}",
        ];

        $base = array_values(array_unique(array_filter(array_merge(
            array_slice($services, 0, 4),
            $fallback
        ))));

        return array_map(fn ($p) => Str::limit(Str::title($p), 80, ''), array_slice($base, 0, 8));
    }

    private function buildRubrics(array $pillars): array
    {
        $defaults = config('editorial.rubrics', []);
        $mainPillar = $pillars[0] ?? 'Educativo';
        $secondary = $pillars[1] ?? $mainPillar;

        $byName = [
            'Educativo' => [$mainPillar, $secondary],
            'Educational' => [$mainPillar, $secondary],
            'Prova Sociale' => [$secondary],
            'Social Proof' => [$secondary],
            'Storia Brand' => [$mainPillar],
            'Brand Story' => [$mainPillar],
            'Offerta' => [$secondary, $mainPillar],
            'Offer' => [$secondary, $mainPillar],
            'Community' => [$mainPillar],
            'Trend' => [$secondary],
        ];

        $out = [];
        foreach ($defaults as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'weight' => (float) ($row['weight'] ?? 0),
                'pillars' => $byName[$name] ?? [$mainPillar],
            ];
        }

        if (empty($out)) {
            $out = [
                ['name' => 'Educativo', 'weight' => 0.4, 'pillars' => [$mainPillar]],
                ['name' => 'Prova Sociale', 'weight' => 0.2, 'pillars' => [$secondary]],
                ['name' => 'Storia Brand', 'weight' => 0.2, 'pillars' => [$mainPillar]],
                ['name' => 'Offerta', 'weight' => 0.2, 'pillars' => [$secondary]],
            ];
        }

        return $out;
    }

    private function buildCtaRules(?TenantProfile $profile): array
    {
        $profileCta = trim((string) ($profile?->cta ?? ''));

        $pool = [
            'Commenta la tua esperienza.',
            'Salva il post per usarlo come checklist.',
            'Scrivici in DM per una consulenza.',
            'Prenota una call di approfondimento.',
            'Visita il sito per maggiori dettagli.',
        ];

        if ($profileCta !== '') {
            array_unshift($pool, $profileCta);
        }

        return [
            'primary_pool' => array_values(array_unique($pool)),
            'avoid_consecutive' => true,
        ];
    }

    private function explode(string $value): array
    {
        $parts = preg_split('/[,;\n]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $trim = trim($part);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }
        return $out;
    }
}
