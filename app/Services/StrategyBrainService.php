<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StrategyBrainService
{
    public function buildStrategy(array $input): array
    {
        $profile = $input['profile'] ?? [];
        $assets = $input['assets'] ?? [];
        $memory = $input['memory'] ?? [];
        $preferences = $input['preferences'] ?? [];

        $tone = (string) ($preferences['tone'] ?? 'professionale');
        $goal = (string) ($preferences['goal'] ?? 'Notorieta e Lead');
        $postsTotal = max(1, (int) ($preferences['posts_total'] ?? 5));

        $pillars = $this->buildPillars($profile, $memory);
        $cadence = $this->buildCadence($preferences, $pillars, $postsTotal);

        return [
            'version' => '1.0',
            'generated_at' => now()->toIso8601String(),
            'goal' => $goal,
            'pillars' => $pillars,
            'messaging_map' => [
                'value_proposition' => $this->buildValueProposition($profile),
                'tone_rules' => $this->toneRules($tone),
                'do' => [
                    'Mantieni CTA chiare e specifiche per il target.',
                    'Collega i post tra loro con riferimenti a tema/campagna.',
                    'Usa esempi pratici, numeri e mini-case reali.',
                ],
                'dont' => [
                    'Non ripetere lo stesso hook in post consecutivi.',
                    'Non cambiare tone in modo incoerente.',
                    'Non usare hashtag generici in modo eccessivo.',
                ],
            ],
            'content_cadence' => $cadence,
            'campaigns' => $this->buildCampaigns($pillars, $preferences),
            'repetition_guard' => [
                'recent_topics' => $memory['themes'] ?? [],
                'recent_cta' => $memory['ctas'] ?? [],
                'avoid_terms' => $memory['avoid_repetition'] ?? [],
            ],
            'hashtag_strategy' => $this->buildHashtagStrategy($profile, $pillars, $memory),
            'brand_references' => $this->buildBrandReferences($profile, $assets),
            'memory_summary' => [
                'posts_count' => $memory['posts_count'] ?? 0,
                'last_post_date' => $memory['last_post_date'] ?? null,
                'main_themes' => $memory['themes'] ?? [],
                'offers_signals' => $memory['offers'] ?? [],
            ],
        ];
    }

    public function buildItemBlueprints(
        array $strategy,
        array $preferences,
        Carbon $start,
        Carbon $end,
        int $totalPosts
    ): array {
        $pillars = $strategy['pillars'] ?? [];
        $campaigns = $strategy['campaigns'] ?? [];
        $platforms = $preferences['platforms'] ?? ['instagram'];
        $formats = $preferences['formats'] ?? ['post'];
        $avoidList = $strategy['repetition_guard']['avoid_terms'] ?? [];

        $dates = $this->spreadDates($start, $end, $totalPosts);
        $campaignSteps = $this->flattenCampaignSteps($campaigns);
        $usedAngles = [];

        $out = [];
        for ($i = 0; $i < $totalPosts; $i++) {
            $pillar = $pillars[$i % max(1, count($pillars))] ?? [
                'name' => 'Valore',
                'objective' => 'Notorieta',
                'topics' => ['Benefici concreti'],
            ];
            $campaignStep = $campaignSteps[$i % max(1, count($campaignSteps))] ?? null;
            $topic = $pillar['topics'][$i % max(1, count($pillar['topics'] ?? []))] ?? $pillar['name'];
            $hook = $campaignStep['hook'] ?? ['Insight', 'Checklist', 'Case', 'Errore comune'][$i % 4];
            $platform = $platforms[$i % max(1, count($platforms))] ?? 'instagram';
            $format = $formats[$i % max(1, count($formats))] ?? 'post';

            $angleBase = $campaignStep['angle'] ?? "Focus su {$topic}";
            $angleCandidates = [
                $angleBase,
                "{$hook}: {$topic} per {$platform}",
                "{$topic} - approccio pratico in formato {$format}",
                "{$pillar['name']}: {$topic} con esempio concreto",
            ];

            $angle = null;
            foreach ($angleCandidates as $candidate) {
                $sig = Str::lower(trim((string) $candidate));
                if (!in_array($sig, $usedAngles, true)) {
                    $angle = $candidate;
                    $usedAngles[] = $sig;
                    break;
                }
            }
            if (!$angle) {
                $angle = "{$angleBase} - variante " . ($i + 1);
                $usedAngles[] = Str::lower($angle);
            }

            $seriesName = $campaignStep['campaign'] ?? ("Percorso " . $pillar['name']);
            $seriesStep = (int) ($campaignStep['step'] ?? (($i % 3) + 1));

            $out[] = [
                'pillar' => $pillar['name'],
                'angle' => $angle,
                'hook' => $hook,
                'objective' => $campaignStep['objective'] ?? $pillar['objective'],
                'key_points' => [
                    "Contesto completo: perché {$topic} conta per il target.",
                    "Soluzione concreta: metodo pratico legato a {$pillar['name']}.",
                    "Azione immediata: un passo misurabile da fare oggi.",
                ],
                'cta' => $campaignStep['cta'] ?? 'Scrivici per ricevere una proposta personalizzata.',
                'image_direction' => ($campaignStep['visual'] ?? "Visual coerente con il pillar {$pillar['name']}")
                    . '. Prevedi area pulita per inserimento logo brand.',
                'avoid_list' => $avoidList,
                'campaign' => $campaignStep['campaign'] ?? $seriesName,
                'campaign_step' => $campaignStep['step'] ?? $seriesStep,
                'series_name' => $seriesName,
                'series_step' => $seriesStep,
                'standalone_rule' => 'Il post deve essere autosufficiente: contesto, valore e CTA completi anche se letto da solo.',
                'connection_hint' => $i === 0
                    ? "Apre la serie {$seriesName}."
                    : "Collega il tema al post precedente della serie, senza dipendere da esso.",
                'uniqueness_key' => Str::slug($pillar['name'] . '-' . $topic . '-' . $platform . '-' . $format . '-' . ($i + 1)),
                'platform' => $platform,
                'format' => $format,
                'scheduled_at' => $dates[$i]->toDateTimeString(),
                'title_hint' => Str::limit($topic . ' - ' . $hook, 110, ''),
            ];
        }

        return $out;
    }

    private function buildPillars(array $profile, array $memory): array
    {
        $services = $this->explodeList((string) ($profile['services'] ?? ''));
        $industry = (string) ($profile['industry'] ?? 'settore');
        $base = array_values(array_unique(array_filter(array_merge(
            array_slice($services, 0, 4),
            ["Educazione {$industry}", "Prova sociale {$industry}", "Offerte {$industry}"]
        ))));

        if (count($base) < 3) {
            $base = array_merge($base, ['Educazione', 'Autorita', 'Conversione']);
        }

        $memoryThemes = $memory['themes'] ?? [];
        $pillars = [];
        $max = min(6, max(3, count($base)));

        for ($i = 0; $i < $max; $i++) {
            $name = Str::title((string) ($base[$i] ?? "Pillar {$i}"));
            $pillars[] = [
                'name' => $name,
                'objective' => $i % 3 === 0 ? 'Notorieta' : ($i % 3 === 1 ? 'Fiducia' : 'Lead'),
                'topics' => array_values(array_filter([
                    $name,
                    $memoryThemes[$i] ?? null,
                    $memoryThemes[$i + 2] ?? null,
                ])),
            ];
        }

        return $pillars;
    }

    private function buildCadence(array $preferences, array $pillars, int $postsTotal): array
    {
        $platforms = $preferences['platforms'] ?? ['instagram'];
        $formats = $preferences['formats'] ?? ['post'];
        $range = $preferences['date_range'] ?? [null, null];

        $mix = [];
        foreach ($formats as $format) {
            $mix[] = [
                'format' => $format,
                'ratio' => round(100 / max(1, count($formats))) . '%',
            ];
        }

        return [
            'date_range' => $range,
            'posts_total' => $postsTotal,
            'platforms' => array_values($platforms),
            'formats_mix' => $mix,
            'pillar_rotation' => array_map(fn ($p) => $p['name'], $pillars),
        ];
    }

    private function buildCampaigns(array $pillars, array $preferences): array
    {
        $goal = (string) ($preferences['goal'] ?? 'Lead');
        $campaigns = [];
        $chunks = array_slice($pillars, 0, 3);

        foreach ($chunks as $idx => $pillar) {
            $name = 'Campagna ' . ($idx + 1) . ' - ' . $pillar['name'];
            $campaigns[] = [
                'name' => $name,
                'focus_pillar' => $pillar['name'],
                'steps' => [
                    [
                        'step' => 1,
                        'hook' => 'Problema',
                        'angle' => "Problema ricorrente su {$pillar['name']}",
                        'objective' => 'Notorieta',
                        'cta' => 'Commenta con la tua situazione attuale.',
                        'visual' => 'Scenario iniziale, contesto reale',
                    ],
                    [
                        'step' => 2,
                        'hook' => 'Metodo',
                        'angle' => "Metodo pratico: {$pillar['name']}",
                        'objective' => 'Fiducia',
                        'cta' => 'Salva il post per usarlo come checklist.',
                        'visual' => 'Processo step-by-step, elementi brand',
                    ],
                    [
                        'step' => 3,
                        'hook' => 'Offerta',
                        'angle' => "Proposta concreta legata a {$goal}",
                        'objective' => 'Lead',
                        'cta' => 'Scrivici in DM per una consulenza.',
                        'visual' => 'Callout offerta e next action',
                    ],
                ],
            ];
        }

        return $campaigns;
    }

    private function buildHashtagStrategy(array $profile, array $pillars, array $memory): array
    {
        $industry = Str::slug((string) ($profile['industry'] ?? 'business'));
        $base = array_values(array_unique(array_filter([
            '#marketing',
            '#socialmedia',
            '#business',
            '#' . $industry,
            '#brandstrategy',
            ...array_slice($memory['hashtags'] ?? [], 0, 8),
        ])));

        $byPillar = [];
        foreach ($pillars as $pillar) {
            $slug = Str::slug($pillar['name']);
            $byPillar[$pillar['name']] = array_values(array_unique(array_filter([
                "#{$slug}",
                '#contenuti',
                '#comunicazione',
                '#leadgeneration',
                '#localbusiness',
            ])));
        }

        return [
            'base' => array_slice($base, 0, 12),
            'by_pillar' => $byPillar,
        ];
    }

    private function buildBrandReferences(array $profile, array $assets): array
    {
        $logo = null;
        $images = [];

        foreach ($assets as $asset) {
            if (($asset['kind'] ?? null) === 'logo' && $logo === null) {
                $logo = $asset['path'] ?? null;
            }
            if (($asset['kind'] ?? null) === 'image' && isset($asset['path'])) {
                $images[] = $asset['path'];
            }
        }

        return [
            'business_name' => $profile['business_name'] ?? null,
            'palette' => $profile['brand_palette'] ?? null,
            'logo_path' => $logo,
            'reference_images' => array_slice($images, 0, 12),
        ];
    }

    private function buildValueProposition(array $profile): string
    {
        $business = (string) ($profile['business_name'] ?? 'Il brand');
        $services = trim((string) ($profile['services'] ?? 'soluzioni concrete'));
        $target = trim((string) ($profile['target'] ?? 'clienti in target'));

        return "{$business} aiuta {$target} con {$services}.";
    }

    private function toneRules(string $tone): array
    {
        return match (Str::lower($tone)) {
            'tecnico' => [
                'Usa lessico preciso ma leggibile.',
                'Inserisci micro-dati o metriche quando possibile.',
                'Mantieni frasi corte e orientate all azione.',
            ],
            'amichevole' => [
                'Tono diretto e vicino al lettore.',
                'Alterna consigli pratici e rassicurazione operativa.',
                'CTA semplici senza pressione eccessiva.',
            ],
            'ironico' => [
                'Ironia leggera, mai sarcastica verso il cliente.',
                'Hook rapidi e punchline brevi.',
                'Chiudi sempre con un takeaway utile.',
            ],
            default => [
                'Tono professionale e chiaro.',
                'Bilancia autorevolezza e accessibilita.',
                'CTA esplicita con prossimo step concreto.',
            ],
        };
    }

    private function explodeList(string $value): array
    {
        $parts = preg_split('/[,;\n]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $p = trim($part);
            if ($p !== '') {
                $out[] = Str::limit($p, 50, '');
            }
        }
        return $out;
    }

    private function spreadDates(Carbon $start, Carbon $end, int $totalPosts): array
    {
        $totalPosts = max(1, $totalPosts);
        $days = max(1, $start->diffInDays($end) + 1);
        $step = max(1, (int) floor($days / $totalPosts));
        $dates = [];

        for ($i = 0; $i < $totalPosts; $i++) {
            $date = (clone $start)->addDays(min($days - 1, $i * $step));
            $hour = $i % 2 === 0 ? 10 : 17;
            $dates[] = $date->setTime($hour, 0);
        }

        return $dates;
    }

    private function flattenCampaignSteps(array $campaigns): array
    {
        $out = [];
        foreach ($campaigns as $campaign) {
            foreach (($campaign['steps'] ?? []) as $step) {
                $step['campaign'] = $campaign['name'] ?? null;
                $out[] = $step;
            }
        }
        return $out;
    }
}
