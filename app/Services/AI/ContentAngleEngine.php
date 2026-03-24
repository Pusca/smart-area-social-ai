<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

class ContentAngleEngine
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $platform = $this->normalizePlatform((string) ($context['platform'] ?? data_get($context, 'item.platform', 'instagram')));
        $format = $this->normalizeFormat((string) ($context['format'] ?? data_get($context, 'item.format', 'post')));
        $goal = $this->normalizeGoal((string) ($context['goal'] ?? data_get($context, 'item_brain.objective', data_get($context, 'strategy.analysis_framework.primary_goal', 'awareness'))));
        $rubric = trim((string) ($context['rubric'] ?? data_get($context, 'item_brain.rubric', '')));
        $editorialMode = trim((string) ($context['editorial_mode'] ?? data_get($context, 'item_brain.editorial_mode', '')));
        $hookIntensity = $this->resolveHookIntensity($context);
        $trendAppetite = $this->resolveTrendAppetite($context);
        $professionalismGuardrail = $this->resolveProfessionalismGuardrail($context);
        $audience = $this->resolveAudienceLabel($context);
        $topic = $this->resolveTopic($context);
        $trendRelevance = $this->resolveTrendRelevance($context, $editorialMode, $trendAppetite);
        $strategyType = $this->resolveStrategyType($context, $platform, $format, $goal, $rubric, $editorialMode, $trendRelevance);
        $trigger = $this->buildAudienceTrigger($strategyType, $goal, $topic, $audience, $trendRelevance);
        $lexicon = $this->buildLexicon($context, $strategyType, $goal, $topic, $audience, $trigger, $trendRelevance);
        $pattern = $this->selectPattern($strategyType, $platform, $format, $goal, $trendRelevance, $hookIntensity, $professionalismGuardrail);
        $ctaMode = $this->resolveCtaMode($strategyType, $goal, $platform);
        $platformOpening = $this->resolvePlatformOpeningStructure($platform, $format);

        $mainHook = $this->applyProfessionalismGuardrail(
            $this->interpolate((string) ($pattern['primary_template'] ?? ''), $lexicon),
            $professionalismGuardrail
        );
        $alternativeHook = $this->applyProfessionalismGuardrail(
            $this->interpolate((string) ($pattern['alternative_template'] ?? ''), $lexicon),
            $professionalismGuardrail
        );
        $narrativeAngle = $this->interpolate((string) ($pattern['narrative_template'] ?? ''), $lexicon);
        $authoritySignals = $this->buildSignals('authority_signal_templates', $strategyType, $lexicon);
        $trustSignals = $this->buildSignals('trust_signal_templates', $strategyType, $lexicon);
        $authorityCue = (string) data_get($authoritySignals, '0.cue', '');
        $trustCue = (string) data_get($trustSignals, '0.cue', '');
        $viralAngle = $this->buildViralAngle($pattern, $strategyType, $trendRelevance, $platform, $format, $ctaMode);
        $contentStructureMeta = $this->buildContentStructureMeta(
            strategyType: $strategyType,
            platform: $platform,
            format: $format,
            topic: $topic,
            platformOpening: $platformOpening,
            shareabilityDriver: (string) ($viralAngle['shareability_driver'] ?? ''),
            ctaMode: $ctaMode,
            isVideo: $this->isVideoFormat($format)
        );

        return [
            'version' => (string) config('content_strategy.version', 'content_angle_engine_v1'),
            'strategy_type' => $strategyType,
            'selection_context' => [
                'platform' => $platform,
                'format' => $format,
                'goal' => $goal,
                'audience' => $audience,
                'topic' => $topic,
                'editorial_mode' => $editorialMode,
                'rubric' => $rubric,
                'trend_relevance' => $trendRelevance,
                'trend_appetite' => $trendAppetite,
                'preferred_hook_intensity' => $hookIntensity,
                'professionalism_guardrail_level' => $professionalismGuardrail,
                'pattern_key' => (string) ($pattern['key'] ?? ''),
                'pattern_family' => (string) ($pattern['family'] ?? ''),
            ],
            'narrative_angle' => $narrativeAngle,
            'hook_meta' => [
                'main_hook' => $mainHook,
                'alternative_hook' => $alternativeHook,
                'narrative_angle' => $narrativeAngle,
                'audience_trigger' => $trigger,
                'authority_cue' => $authorityCue,
                'proof_or_trust_cue' => $trustCue,
                'cta_mode' => $ctaMode,
                'platform_specific_opening_structure' => $platformOpening,
                'preferred_hook_intensity' => $hookIntensity,
                'professionalism_guardrail_level' => $professionalismGuardrail,
                'pattern_key' => (string) ($pattern['key'] ?? ''),
                'pattern_family' => (string) ($pattern['family'] ?? ''),
            ],
            'authority_signals' => $authoritySignals,
            'trust_signals' => $trustSignals,
            'viral_angle' => $viralAngle,
            'content_structure_meta' => $contentStructureMeta,
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array<string, mixed>
     */
    public function toPlanPayloadFragment(array $pack): array
    {
        return [
            'content_strategy_type' => (string) ($pack['strategy_type'] ?? ''),
            'narrative_angle' => (string) ($pack['narrative_angle'] ?? ''),
            'hook_meta' => (array) ($pack['hook_meta'] ?? []),
            'authority_signals' => (array) ($pack['authority_signals'] ?? []),
            'trust_signals' => (array) ($pack['trust_signals'] ?? []),
            'viral_angle' => (string) data_get($pack, 'viral_angle.primary', ''),
            'viral_angle_meta' => (array) ($pack['viral_angle'] ?? []),
            'content_structure_meta' => (array) ($pack['content_structure_meta'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array<string, mixed>
     */
    public function toMetaFragment(array $pack): array
    {
        $itemBrain = $this->toPlanPayloadFragment($pack);
        $itemBrain['viral_angle'] = (string) data_get($pack, 'viral_angle.primary', '');

        return [
            'content_strategy' => [
                'version' => (string) ($pack['version'] ?? config('content_strategy.version', 'content_angle_engine_v1')),
                'strategy_type' => (string) ($pack['strategy_type'] ?? ''),
                'selection_context' => (array) ($pack['selection_context'] ?? []),
            ],
            'hook_meta' => (array) ($pack['hook_meta'] ?? []),
            'authority_signals' => (array) ($pack['authority_signals'] ?? []),
            'trust_signals' => (array) ($pack['trust_signals'] ?? []),
            'viral_angle' => (array) ($pack['viral_angle'] ?? []),
            'content_structure_meta' => (array) ($pack['content_structure_meta'] ?? []),
            'item_brain' => $itemBrain,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $trigger
     * @return array<string, string>
     */
    private function buildLexicon(
        array $context,
        string $strategyType,
        string $goal,
        string $topic,
        string $audience,
        array $trigger,
        string $trendRelevance
    ): array {
        $industry = trim((string) ($context['industry'] ?? data_get($context, 'tenant_profile.industry', data_get($context, 'strategy.brand_voice.industry', 'brand'))));
        $industry = $industry !== '' ? $industry : 'brand';
        $proofFocus = $this->resolveProofFocus($strategyType, $topic);
        $decisionFilter = $this->resolveDecisionFilter($strategyType, $topic);

        return [
            'audience_label' => $audience,
            'topic' => $topic,
            'industry' => $industry,
            'pain_statement' => $this->resolvePainStatement($strategyType, $topic, $goal),
            'desire_outcome' => $this->resolveDesireOutcome($goal, $topic),
            'desire_statement' => $this->resolveDesireStatement($goal, $topic),
            'curiosity_statement' => $this->resolveCuriosityStatement($strategyType, $topic, $trendRelevance),
            'proof_focus' => $proofFocus,
            'authority_anchor' => $this->resolveAuthorityAnchor($industry, $strategyType),
            'authority_reframe' => $this->resolveAuthorityReframe($strategyType, $topic, $proofFocus),
            'decision_filter' => $decisionFilter,
            'false_shortcut' => $this->resolveFalseShortcut($strategyType, $topic),
            'trust_proof' => $this->resolveTrustProof($strategyType, $topic, $audience, $trigger),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildAudienceTrigger(string $strategyType, string $goal, string $topic, string $audience, string $trendRelevance): array
    {
        $focus = match (true) {
            $strategyType === 'conversion' => 'pain',
            $strategyType === 'trend-aware' || $trendRelevance === 'high' => 'curiosity',
            $strategyType === 'social-proof' => 'desire',
            $goal === 'lead' || $goal === 'conversion' => 'pain',
            default => 'desire',
        };

        $label = match ($focus) {
            'pain' => 'rischio evitabile',
            'curiosity' => 'angolo inatteso',
            default => 'risultato desiderato',
        };

        $statement = match ($focus) {
            'pain' => "Se {$topic} viene gestito in superficie, il risultato perde forza e fiducia.",
            'curiosity' => "La parte davvero interessante di {$topic} non e quella che si nota per prima.",
            default => "{$audience} vuole capire subito il valore reale di {$topic}, senza rumore.",
        };

        return [
            'focus' => $focus,
            'label' => $label,
            'statement' => $this->cleanSentence($statement, 180),
        ];
    }

    /**
     * @param  array<string, mixed>  $pattern
     * @return array<string, mixed>
     */
    private function buildViralAngle(array $pattern, string $strategyType, string $trendRelevance, string $platform, string $format, string $ctaMode): array
    {
        $primary = match ($strategyType) {
            'authoritative' => 'Insight che ribalta una lettura superficiale',
            'educational' => 'Takeaway utile che vale il salvataggio',
            'trend-aware' => 'Format o trend tradotto in punto di vista proprietario',
            'emotional-relatable' => 'Dettaglio umano che fa riconoscere il brand',
            'conversion' => 'Filtro decisionale che rende la CTA naturale',
            'social-proof' => 'Segnale reale che sostituisce il claim',
            default => 'Valore concreto con apertura fermascroll',
        };

        return [
            'primary' => $primary,
            'mechanic' => (string) ($pattern['family'] ?? 'professional_shareability'),
            'shareability_driver' => (string) ($pattern['shareability_driver'] ?? ''),
            'trend_relevance' => $trendRelevance,
            'platform_fit' => trim($platform . ' ' . $format),
            'cta_mode' => $ctaMode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContentStructureMeta(
        string $strategyType,
        string $platform,
        string $format,
        string $topic,
        string $platformOpening,
        string $shareabilityDriver,
        string $ctaMode,
        bool $isVideo
    ): array {
        $bodyFlow = match ($strategyType) {
            'authoritative' => 'Hook -> reframe operativo -> criterio concreto -> CTA sobria.',
            'educational' => 'Hook -> spiegazione semplice -> takeaway salvabile -> CTA utile.',
            'trend-aware' => 'Hook nativo -> traduzione brand-safe -> payoff proprietario -> comment trigger.',
            'emotional-relatable' => 'Hook umano -> scena o tensione -> significato -> CTA relazionale.',
            'conversion' => 'Hook su rischio o bisogno -> prova o criterio -> next step chiaro -> CTA soft.',
            'social-proof' => 'Hook su segnale reale -> contesto -> perche conta -> CTA di fiducia.',
            default => 'Hook -> sviluppo -> payoff -> CTA.',
        };

        $ctaEnding = match ($ctaMode) {
            'save_or_share' => 'Chiudi con invito a salvare o condividere se il contenuto puo tornare utile.',
            'comment_with_point_of_view' => 'Chiudi con domanda o punto di vista che apra commenti credibili.',
            'dm_or_click_soft' => 'Chiudi con next step morbido verso DM, link o richiesta info.',
            'reply_with_story' => 'Chiudi invitando a raccontare una esperienza simile o una esigenza reale.',
            default => 'Chiudi con invito naturale ad approfondire senza pressione.',
        };

        $meta = [
            'opening_structure' => $platformOpening,
            'body_flow' => $bodyFlow,
            'closing_structure' => $ctaEnding,
            'shareability_driver' => $shareabilityDriver,
            'platform' => $platform,
            'format' => $format,
            'topic' => $topic,
        ];

        if ($isVideo) {
            $meta['video_segments'] = $this->buildVideoSegments($strategyType, $topic);
        }

        return $meta;
    }

    /**
     * @return array<string, string>
     */
    private function buildVideoSegments(string $strategyType, string $topic): array
    {
        $base = (array) config('content_strategy.video_sequences.' . $strategyType, []);

        return [
            'hook_0_3' => $this->interpolate((string) ($base['hook_0_3'] ?? ''), ['topic' => $topic]),
            'development_3_8' => $this->interpolate((string) ($base['development_3_8'] ?? ''), ['topic' => $topic]),
            'payoff_reveal' => $this->interpolate((string) ($base['payoff_reveal'] ?? ''), ['topic' => $topic]),
            'cta_ending' => $this->interpolate((string) ($base['cta_ending'] ?? ''), ['topic' => $topic]),
        ];
    }

    /**
     * @param  array<string, string>  $lexicon
     * @return array<int, array<string, string>>
     */
    private function buildSignals(string $configKey, string $strategyType, array $lexicon): array
    {
        $templates = (array) config('content_strategy.' . $configKey, []);
        $selected = $configKey === 'authority_signal_templates'
            ? match ($strategyType) {
                'authoritative' => ['operational_detail', 'decision_criterion'],
                'educational' => ['decision_criterion', 'professional_standard'],
                'trend-aware' => ['field_experience', 'operational_detail'],
                'emotional-relatable' => ['field_experience', 'professional_standard'],
                'conversion' => ['professional_standard', 'decision_criterion'],
                'social-proof' => ['field_experience', 'operational_detail'],
                default => ['operational_detail', 'decision_criterion'],
            }
            : match ($strategyType) {
                'authoritative' => ['observable_proof', 'specific_constraint'],
                'educational' => ['specific_constraint', 'observable_proof'],
                'trend-aware' => ['observable_proof', 'social_validation'],
                'emotional-relatable' => ['human_cue', 'specific_constraint'],
                'conversion' => ['observable_proof', 'specific_constraint'],
                'social-proof' => ['observable_proof', 'social_validation'],
                default => ['observable_proof', 'specific_constraint'],
            };

        $signals = [];
        foreach ($selected as $type) {
            $template = (string) ($templates[$type] ?? '');
            if ($template === '') {
                continue;
            }

            $signals[] = [
                'type' => $type,
                'cue' => $this->interpolate($template, $lexicon),
            ];
        }

        return array_values($signals);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectPattern(
        string $strategyType,
        string $platform,
        string $format,
        string $goal,
        string $trendRelevance,
        string $hookIntensity,
        string $professionalismGuardrail
    ): array
    {
        $patterns = (array) config('content_strategy.patterns.' . $strategyType, []);
        $best = [];
        $bestScore = -1.0;

        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }

            $score = (float) ($pattern['priority'] ?? 0.5);
            $score += in_array($platform, (array) ($pattern['platforms'] ?? []), true) ? 0.28 : -0.18;
            $score += in_array($format, (array) ($pattern['formats'] ?? []), true) ? 0.24 : -0.14;
            $score += in_array($goal, (array) ($pattern['goals'] ?? []), true) ? 0.2 : -0.08;
            $score += in_array($trendRelevance, (array) ($pattern['trend_relevance'] ?? []), true) ? 0.18 : -0.12;
            $score += $this->hookIntensityPatternDelta($pattern, $hookIntensity);
            $score += $this->professionalismPatternDelta($pattern, $professionalismGuardrail);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $pattern;
            }
        }

        return is_array($best) ? $best : [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveStrategyType(
        array $context,
        string $platform,
        string $format,
        string $goal,
        string $rubric,
        string $editorialMode,
        string $trendRelevance
    ): string {
        $rubric = Str::lower($rubric);
        $industry = Str::lower(trim((string) data_get($context, 'tenant_profile.industry', data_get($context, 'strategy.brand_voice.industry', ''))));

        if (str_contains($rubric, 'prova sociale') || str_contains($rubric, 'social proof')) {
            return 'social-proof';
        }

        if (str_contains($rubric, 'offerta') || str_contains($rubric, 'offer') || $editorialMode === 'conversion-oriented') {
            return 'conversion';
        }

        if (in_array($editorialMode, ['trend-aware', 'reactive'], true) || $trendRelevance === 'high') {
            return 'trend-aware';
        }

        if (str_contains($rubric, 'storia') || str_contains($rubric, 'brand') || str_contains($rubric, 'community')) {
            return 'emotional-relatable';
        }

        if (
            str_contains($rubric, 'educativo')
            || str_contains($rubric, 'educational')
            || $editorialMode === 'authority-building'
        ) {
            if (
                $platform === 'linkedin'
                || $format === 'carousel'
                || in_array($goal, ['trust', 'lead'], true)
                || str_contains($industry, 'saas')
                || str_contains($industry, 'consult')
                || str_contains($industry, 'software')
            ) {
                return 'authoritative';
            }

            return 'educational';
        }

        if (in_array($goal, ['lead', 'conversion'], true)) {
            return 'conversion';
        }

        return in_array($platform, ['instagram', 'facebook', 'tiktok'], true)
            ? 'emotional-relatable'
            : 'authoritative';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTrendRelevance(array $context, string $editorialMode, string $trendAppetite): string
    {
        $confidence = data_get($context, 'trend_confidence', data_get($context, 'item_brain.trend_confidence'));
        $hasTrend = !empty((array) data_get($context, 'trend_opportunity', data_get($context, 'item_brain.trend_opportunity', [])))
            || trim((string) data_get($context, 'item_brain.trend_bridge', '')) !== ''
            || in_array($editorialMode, ['trend-aware', 'reactive'], true);

        if (!$hasTrend) {
            return 'low';
        }

        if ($trendAppetite === 'low') {
            return is_numeric($confidence) && (float) $confidence >= 0.9 ? 'medium' : 'low';
        }

        if (is_numeric($confidence) && (float) $confidence >= 0.76) {
            return 'high';
        }

        if ($trendAppetite === 'high') {
            return 'medium';
        }

        return 'medium';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveHookIntensity(array $context): string
    {
        $value = Str::lower(trim((string) (
            data_get($context, 'preferred_hook_intensity')
            ?: data_get($context, 'tenant_profile.overlay_preferences.preferred_hook_intensity')
            ?: data_get($context, 'strategy.analysis_framework.preferred_hook_intensity')
            ?: data_get($context, 'strategy.creative_direction.content_strategy.hook_policy.preferred_intensity')
            ?: 'medium'
        )));

        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTrendAppetite(array $context): string
    {
        $value = Str::lower(trim((string) (
            data_get($context, 'trend_appetite')
            ?: data_get($context, 'tenant_profile.overlay_preferences.trend_appetite')
            ?: data_get($context, 'strategy.analysis_framework.trend_appetite')
            ?: 'medium'
        )));

        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveProfessionalismGuardrail(array $context): string
    {
        $value = Str::lower(trim((string) (
            data_get($context, 'professionalism_guardrail_level')
            ?: data_get($context, 'tenant_profile.overlay_preferences.professionalism_guardrail_level')
            ?: data_get($context, 'strategy.analysis_framework.professionalism_guardrail_level')
            ?: data_get($context, 'strategy.creative_direction.content_strategy.professionalism_guardrail_level')
            ?: 'high'
        )));

        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'high';
    }

    /**
     * @param  array<string, mixed>  $pattern
     */
    private function hookIntensityPatternDelta(array $pattern, string $hookIntensity): float
    {
        $family = (string) ($pattern['family'] ?? '');

        return match ($hookIntensity) {
            'low' => in_array($family, ['decision_criteria', 'clarity_framework', 'social_validation', 'proof_from_scene'], true)
                ? 0.12
                : (in_array($family, ['reactive_commentary', 'trend_translation', 'relatable_scene'], true) ? -0.1 : 0.0),
            'high' => in_array($family, ['authority_problem_reframe', 'trend_translation', 'reactive_commentary', 'conversion_filter', 'relatable_scene'], true)
                ? 0.08
                : 0.0,
            default => 0.0,
        };
    }

    /**
     * @param  array<string, mixed>  $pattern
     */
    private function professionalismPatternDelta(array $pattern, string $professionalismGuardrail): float
    {
        if ($professionalismGuardrail !== 'high') {
            return 0.0;
        }

        $family = (string) ($pattern['family'] ?? '');

        return in_array($family, ['reactive_commentary', 'relatable_scene'], true) ? -0.08 : 0.03;
    }

    private function applyProfessionalismGuardrail(string $text, string $professionalismGuardrail): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }

        if ($professionalismGuardrail === 'high') {
            $text = str_replace('!', '.', $text);
            $text = (string) preg_replace('/[.]{2,}/u', '.', $text);
        } elseif ($professionalismGuardrail === 'medium') {
            $text = (string) preg_replace('/!{2,}/u', '!', $text);
        }

        return trim((string) preg_replace('/\s+([,.!?;:])/u', '$1', $text));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveAudienceLabel(array $context): string
    {
        $target = trim((string) ($context['audience'] ?? data_get($context, 'tenant_profile.target', data_get($context, 'strategy.brand_voice.target', data_get($context, 'strategy.analysis_framework.audience_focus', '')))));

        if ($target !== '') {
            return Str::limit($target, 80, '');
        }

        $industry = trim((string) data_get($context, 'tenant_profile.industry', data_get($context, 'strategy.brand_voice.industry', 'chi ti segue')));
        return $industry !== '' ? "chi sceglie {$industry}" : 'chi ti segue';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTopic(array $context): string
    {
        $candidates = [
            (string) ($context['topic'] ?? ''),
            (string) ($context['content_angle'] ?? ''),
            (string) data_get($context, 'item_brain.narrative_angle', ''),
            (string) data_get($context, 'item_brain.angle', ''),
            (string) data_get($context, 'item_brain.pillar', ''),
            (string) ($context['pillar'] ?? ''),
            (string) data_get($context, 'item.title', ''),
            (string) data_get($context, 'manual_brief', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return Str::limit($candidate, 90, '');
            }
        }

        return 'questo contenuto';
    }

    private function resolvePainStatement(string $strategyType, string $topic, string $goal): string
    {
        return match ($strategyType) {
            'conversion' => "Se scegli {$topic} guardando solo la superficie, il rischio di sbagliare direzione cresce subito.",
            'social-proof' => "Senza segnali reali, {$topic} resta un claim difficile da credere.",
            'trend-aware' => "Seguire un format senza tradurlo bene rende {$topic} dimenticabile in pochi scroll.",
            'emotional-relatable' => "Quando tutto sembra uguale, e difficile sentire davvero il valore di {$topic}.",
            default => in_array($goal, ['lead', 'conversion'], true)
                ? "Se {$topic} viene valutato senza criterio, si perdono tempo, margine e fiducia."
                : "Quando {$topic} viene letto in modo superficiale, il valore reale si disperde.",
        };
    }

    private function resolveDesireOutcome(string $goal, string $topic): string
    {
        return match ($goal) {
            'lead', 'conversion' => "capire se {$topic} e la scelta giusta",
            'trust' => "fidarti di piu di {$topic}",
            'engagement' => "fermarti davvero su {$topic}",
            default => "capire subito il valore di {$topic}",
        };
    }

    private function resolveDesireStatement(string $goal, string $topic): string
    {
        return Str::ucfirst($this->resolveDesireOutcome($goal, $topic));
    }

    private function resolveCuriosityStatement(string $strategyType, string $topic, string $trendRelevance): string
    {
        if ($strategyType === 'trend-aware' || $trendRelevance === 'high') {
            return "La parte piu utile di {$topic} non coincide quasi mai con quella piu copiata.";
        }

        return "C e un dettaglio in {$topic} che cambia molto piu di quanto sembri.";
    }

    private function resolveProofFocus(string $strategyType, string $topic): string
    {
        return match ($strategyType) {
            'authoritative' => "un criterio concreto su {$topic}",
            'educational' => "un passaggio chiaro su {$topic}",
            'trend-aware' => "la traduzione giusta di {$topic} nel linguaggio del brand",
            'emotional-relatable' => "un dettaglio vero di {$topic}",
            'conversion' => "il filtro che chiarisce quando {$topic} fa davvero per te",
            'social-proof' => "una scena reale che rende {$topic} credibile",
            default => "un segnale concreto su {$topic}",
        };
    }

    private function resolveAuthorityAnchor(string $industry, string $strategyType): string
    {
        return match ($strategyType) {
            'trend-aware' => "Anche quando un format accelera, nel {$industry} una cosa resta vera",
            'conversion' => "Quando la scelta conta davvero, una cosa fa la differenza",
            default => "Sul campo si vede subito una cosa",
        };
    }

    private function resolveAuthorityReframe(string $strategyType, string $topic, string $proofFocus): string
    {
        return match ($strategyType) {
            'trend-aware' => "il punto non e inseguire il trend, ma far emergere {$proofFocus}",
            'conversion' => "il vero salto e usare {$proofFocus} prima di arrivare alla CTA",
            default => "il vero salto e leggere {$topic} partendo da {$proofFocus}",
        };
    }

    private function resolveDecisionFilter(string $strategyType, string $topic): string
    {
        return match ($strategyType) {
            'conversion' => "il criterio che ti evita una scelta superficiale su {$topic}",
            'authoritative' => "il criterio con cui leggi {$topic}",
            'social-proof' => "la prova concreta che rende affidabile {$topic}",
            default => "il dettaglio che rende piu chiaro {$topic}",
        };
    }

    private function resolveFalseShortcut(string $strategyType, string $topic): string
    {
        return match ($strategyType) {
            'trend-aware' => "copiare il format",
            'conversion' => "farti guidare solo dalla parte piu visibile di {$topic}",
            'social-proof' => "ripetere un claim senza prova",
            default => "guardare {$topic} solo in superficie",
        };
    }

    /**
     * @param  array<string, mixed>  $trigger
     */
    private function resolveTrustProof(string $strategyType, string $topic, string $audience, array $trigger): string
    {
        $base = match ($strategyType) {
            'social-proof' => "una scena reale, un feedback leggibile o un gesto osservabile legato a {$topic}",
            'emotional-relatable' => "un dettaglio umano che {$audience} riconosce al volo",
            'conversion' => "una spiegazione chiara, concreta e verificabile di {$topic}",
            default => "un elemento concreto e riconoscibile legato a {$topic}",
        };

        if (($trigger['focus'] ?? '') === 'pain') {
            return $base . ' senza drammi finti o urgenza artificiale';
        }

        return $base;
    }

    private function resolveCtaMode(string $strategyType, string $goal, string $platform): string
    {
        return match ($strategyType) {
            'authoritative' => in_array($goal, ['lead', 'conversion'], true) ? 'consultative_soft' : 'save_or_share',
            'educational' => 'save_or_share',
            'trend-aware' => $platform === 'linkedin' ? 'comment_with_point_of_view' : 'comment_with_point_of_view',
            'emotional-relatable' => 'reply_with_story',
            'conversion' => 'dm_or_click_soft',
            'social-proof' => in_array($goal, ['lead', 'conversion'], true) ? 'consultative_soft' : 'comment_with_point_of_view',
            default => 'consultative_soft',
        };
    }

    private function resolvePlatformOpeningStructure(string $platform, string $format): string
    {
        $specific = trim((string) config('content_strategy.platform_openings.' . $platform . '.' . $format, ''));
        if ($specific !== '') {
            return $specific;
        }

        return 'Apertura breve, concreta e leggibile gia dal primo blocco di contenuto.';
    }

    private function normalizeGoal(string $goal): string
    {
        $goal = Str::lower(trim($goal));

        return match (true) {
            str_contains($goal, 'lead') => 'lead',
            str_contains($goal, 'convert') || str_contains($goal, 'vend') || str_contains($goal, 'dm') => 'conversion',
            str_contains($goal, 'fiducia') || str_contains($goal, 'trust') => 'trust',
            str_contains($goal, 'engagement') || str_contains($goal, 'coinvolg') || str_contains($goal, 'comment') => 'engagement',
            default => 'awareness',
        };
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = Str::lower(trim($platform));

        return in_array($platform, ['instagram', 'facebook', 'linkedin', 'tiktok'], true)
            ? $platform
            : 'instagram';
    }

    private function normalizeFormat(string $format): string
    {
        $format = Str::lower(trim($format));

        return in_array($format, ['post', 'carousel', 'reel', 'story', 'video'], true)
            ? $format
            : 'post';
    }

    private function isVideoFormat(string $format): bool
    {
        return in_array($format, ['reel', 'story', 'video'], true);
    }

    /**
     * @param  array<string, string>  $lexicon
     */
    private function interpolate(string $template, array $lexicon): string
    {
        if ($template === '') {
            return '';
        }

        $replaced = $template;
        foreach ($lexicon as $key => $value) {
            $replaced = str_replace('{' . $key . '}', $value, $replaced);
        }

        return $this->cleanSentence($replaced, 220);
    }

    private function cleanSentence(string $value, int $limit = 220): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = str_replace(['..', ' .', ' ,'], ['.', '.', ','], $value);
        $value = trim($value, " \t\n\r\0\x0B");

        return Str::limit($value, $limit, '');
    }
}
