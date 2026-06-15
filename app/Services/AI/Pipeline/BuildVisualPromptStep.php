<?php

namespace App\Services\AI\Pipeline;

use App\Jobs\GenerateAiForContentItem;
use App\Services\OpenAiService;

class BuildVisualPromptStep
{
    public function __construct(
        private readonly OpenAiService $openAi
    ) {
    }

    public function handle(GenerateAiForContentItem $job, GenerationPipelineState $state): GenerationPipelineState
    {
        $item = $state->item;
        $meta = $state->meta;
        $strategy = (array) $state->get('strategy', []);
        $tenantProfile = (array) $state->get('tenant_profile', []);
        $itemBrain = (array) $state->get('item_brain', []);
        $activeFeedbackRequest = (array) $state->get('active_feedback_request', []);
        $assetVariables = (array) $state->get('asset_variables', []);
        $assetIdentity = (array) $state->get('asset_identity', []);
        $assetScoring = (array) $state->get('asset_scoring', (array) data_get($meta, 'asset_scoring', []));
        $visualModePreset = (array) $state->get('visual_mode_preset', (array) data_get($meta, 'visual_mode_preset', []));
        $alterEgo = (array) $state->get('alter_ego', []);
        $alterEgoVisualRefs = array_values(array_filter(array_map(
            'strval',
            (array) data_get($alterEgo, 'visual_references', [])
        )));

        $identityAssets = (array) $state->get('identity_assets', []);

        $prompt = trim((string) ($item->ai_image_prompt ?? ''));
        $brandImageSources = $job->resolveBrandImageSources($strategy, $meta, (int) $item->tenant_id);
        $brandDecision = $job->decideBrandImageUsage($item, $brandImageSources, $this->openAi);
        $scoredReferencePaths = array_values(array_filter(array_map(
            'strval',
            (array) data_get($assetScoring, 'reference_paths', [])
        )));
        if ($state->strictAssetMode && !((bool) ($brandDecision['use_brand'] ?? false)) && empty($scoredReferencePaths)) {
            throw new \RuntimeException('Strict mode: nessuna immagine brand valida trovata per avviare la generazione.');
        }

        $selectedBrandImage = $brandDecision['path'] ?? null;
        $selectedBrandImagePaths = array_values(array_filter(array_map(
            'strval',
            (array) ($brandDecision['paths'] ?? ($selectedBrandImage ? [$selectedBrandImage] : []))
        )));
        if (is_string($selectedBrandImage) && $selectedBrandImage !== '' && !in_array($selectedBrandImage, $selectedBrandImagePaths, true)) {
            array_unshift($selectedBrandImagePaths, $selectedBrandImage);
        }

        // Quando l'alter ego ha foto di riferimento, le aggiungiamo in testa alla
        // lista dei reference path così diventano la fonte visiva prioritaria per
        // la generazione e garantiscono coerenza con l'aspetto della persona reale.
        if (!empty($alterEgoVisualRefs)) {
            $selectedBrandImagePaths = array_values(array_unique(
                array_merge($alterEgoVisualRefs, $selectedBrandImagePaths)
            ));
        }

        // ── IdentityAsset: inietta i canonical/reference path in testa ────────
        // I path canonici degli IdentityAsset (soggetti con identità lockata) hanno
        // priorità massima come reference visivi — vengono inseriti prima di tutto.
        $identityCanonicalPaths = [];
        $identityReferencePaths = [];
        foreach ((array) data_get($identityAssets, 'slots', []) as $slot) {
            if (is_string($slot['canonical_path'] ?? null) && $slot['canonical_path'] !== '') {
                $identityCanonicalPaths[] = $slot['canonical_path'];
            }
            foreach ((array) ($slot['reference_paths'] ?? []) as $refPath) {
                if (is_string($refPath) && $refPath !== '') {
                    $identityReferencePaths[] = $refPath;
                }
            }
        }
        if (!empty($identityCanonicalPaths) || !empty($identityReferencePaths)) {
            $selectedBrandImagePaths = array_values(array_unique(array_merge(
                $identityCanonicalPaths,
                $identityReferencePaths,
                $selectedBrandImagePaths,
            )));
        }

        $selectedBrandImagePaths = $job->filterReferenceImagePaths(array_values(array_unique($selectedBrandImagePaths)));
        $selectedBrandImagePaths = $job->stabilizeReferencePathsForFeedback(
            $selectedBrandImagePaths,
            $activeFeedbackRequest,
            $assetVariables
        );
        $selectedBrandImagePaths = $job->applyIdentityPackReferenceSelection(
            $selectedBrandImagePaths,
            $assetVariables,
            $assetIdentity,
            $state->strictAssetMode,
            $assetScoring
        );
        $selectedBrandImage = $selectedBrandImagePaths[0] ?? $selectedBrandImage;

        if ($prompt === '') {
            $brandName = data_get($tenantProfile, 'business_name', 'Brand');
            $industry = data_get($tenantProfile, 'industry', '');
            $palette = data_get($strategy, 'brand_references.palette', '');
            $logoPath = data_get($strategy, 'brand_references.logo_path', '');
            // Priorità: image_direction esplicito → narrative_angle + main_hook → fallback generico
            $narrativeAngle = trim((string) data_get($itemBrain, 'narrative_angle', ''));
            $mainHook       = trim((string) data_get($itemBrain, 'hook_meta.main_hook', ''));
            $visualRulesDefault = match (true) {
                $narrativeAngle !== '' && $mainHook !== '' => "Tema: {$narrativeAngle}. Angolo narrativo: {$mainHook}.",
                $narrativeAngle !== ''                     => "Tema: {$narrativeAngle}.",
                $mainHook !== ''                           => "Angolo narrativo: {$mainHook}.",
                default                                    => 'Visual coerente con il brand.',
            };
            $visualRules = trim((string) data_get($itemBrain, 'image_direction', '')) ?: $visualRulesDefault;
            $visualStyle = (string) data_get($strategy, 'visual_system.style', '');
            $visualMood = (string) data_get($strategy, 'visual_system.mood', '');
            $visualDo = (string) data_get($strategy, 'visual_system.visual_do', '');
            $visualDont = (string) data_get($strategy, 'visual_system.visual_dont', '');
            $logoRule = (string) data_get($strategy, 'visual_system.logo_rule', '');
            $analysisGoal = (string) data_get($strategy, 'analysis_framework.primary_goal', '');
            $publishingCadence = (string) data_get($strategy, 'publishing_system.cadence_rule', '');
            $strategyNotes = (string) data_get($strategy, 'strategy_notes', '');
            $creativeDirectionHint = $job->creativeDirectionPromptInstruction($strategy, $itemBrain);
            $assetVariableHint = $job->buildAssetVariablePromptHint($assetVariables);
            $assetIdentityHint = $job->buildAssetIdentityPromptHint($assetIdentity);
            $locationEnvelopeHint = $job->locationEnvelopePreservationInstruction($assetVariables, $selectedBrandImagePaths);
            $feedbackVisualHint = $job->feedbackDrivenImageInstruction(
                $activeFeedbackRequest,
                $selectedBrandImagePaths,
                $assetVariables
            );
            $socialPublicationHint = $job->socialGraphicSystemInstruction($item, $itemBrain);

            $alterEgoName = (string) data_get($alterEgo, 'name', '');
            $alterEgoPersonaHint = '';
            if ($alterEgoName !== '' && !empty($alterEgoVisualRefs)) {
                $alterEgoPersonaHint = "PERSONA REALE DI RIFERIMENTO: le foto fornite ritraggono {$alterEgoName}, la persona reale che rappresenta questo brand. "
                    . "Il contenuto visivo DEVE presentare questa persona in modo riconoscibile e coerente: stessa fisionomia, stesso stile, stessa energia. "
                    . "Usa le foto di riferimento per replicare il look della persona, non inventarne uno nuovo. ";
            } elseif ($alterEgoName !== '') {
                $alterEgoPersonaHint = "La voce narrativa è quella di {$alterEgoName}: mantieni coerenza stilistica e tono visivo con questa persona. ";
            }

            $prompt = "Crea un'immagine social premium pronta per Instagram feed per {$brandName}. "
                . "Settore: {$industry}. "
                . "Direzione visiva: {$visualRules}. "
                . "Palette colore suggerita: {$palette}. "
                . ($analysisGoal !== '' ? "Obiettivo strategico principale: {$analysisGoal}. " : '')
                . ($visualStyle !== '' ? "Stile visual: {$visualStyle}. " : '')
                . ($visualMood !== '' ? "Mood visual: {$visualMood}. " : '')
                . ($visualDo !== '' ? "Regola visual da fare: {$visualDo}. " : '')
                . ($visualDont !== '' ? "Regola visual da evitare: {$visualDont}. " : '')
                . ($logoRule !== '' ? "Regola logo: {$logoRule}. " : '')
                . ($publishingCadence !== '' ? "Coerenza publishing: {$publishingCadence}. " : '')
                . ($strategyNotes !== '' ? "Note strategiche: {$strategyNotes}. " : '')
                . ($creativeDirectionHint !== '' ? "Direzione creativa operativa: {$creativeDirectionHint}. " : '')
                . ($assetVariableHint !== '' ? "Variabili asset obbligatorie: {$assetVariableHint}. " : '')
                . ($assetIdentityHint !== '' ? "Regole identitarie del contenuto: {$assetIdentityHint}. " : '')
                . ($locationEnvelopeHint !== '' ? $locationEnvelopeHint . ' ' : '')
                . ($feedbackVisualHint !== '' ? $feedbackVisualHint . ' ' : '')
                . ($socialPublicationHint !== '' ? $socialPublicationHint . ' ' : '')
                . ($alterEgoPersonaHint !== '' ? $alterEgoPersonaHint : '')
                . $this->buildIdentityAssetsPromptBlock($identityAssets)
                . "Percorso logo di riferimento (solo contesto stilistico): {$logoPath}. "
                . ($selectedBrandImage ? "Usa i riferimenti brand come guida di stile, palette e identita visiva, ma crea una composizione fotografica COMPLETAMENTE NUOVA e ORIGINALE: nuova inquadratura, nuova luce, nuova scena. NON copiare ne riprodurre l'immagine di riferimento. " : "Crea la composizione da zero seguendo la strategia e mantenendo novita rispetto ai post precedenti. ")
                . "REGOLA ASSOLUTA E INVIOLABILE: NON includere nell'immagine nessun testo, lettera, numero, parola, scritta, titolo, slogan, caption, watermark, logo finto o qualsiasi segno tipografico. L'immagine deve contenere SOLO elementi visivi puri: persone, oggetti, scene, sfondi, luci, colori, forme. Zero testo. "
                . "Stile professionale, fotografia editoriale premium, coerente con il brand.";

            // ── Visual mode modifiers ────────────────────────────────────────
            $positiveModifiers = trim((string) data_get($visualModePreset, 'positive_modifiers', ''));
            $negativeModifiers = trim((string) data_get($visualModePreset, 'negative_modifiers', ''));
            if ($positiveModifiers !== '') {
                $prompt .= ' ' . $positiveModifiers;
            }
            if ($negativeModifiers !== '') {
                $prompt .= ' ' . $negativeModifiers;
            }

            $item->ai_image_prompt = $prompt;
            $item->save();
        }

        $prompt = $job->augmentPromptForInstagramImageExecution(
            $item,
            $prompt,
            $selectedBrandImagePaths,
            $assetVariables,
            $activeFeedbackRequest
        );
        if ($prompt !== (string) ($item->ai_image_prompt ?? '')) {
            $item->ai_image_prompt = $prompt;
            $item->save();
        }

        $selectedBrandImageAbsList = [];
        $publicDisk = \Storage::disk('public');
        foreach ($selectedBrandImagePaths as $brandPath) {
            if (!is_string($brandPath) || $brandPath === '' || !$publicDisk->exists($brandPath)) {
                continue;
            }
            $selectedBrandImageAbsList[] = $publicDisk->path($brandPath);
        }

        $selectedBrandImageAbs = $selectedBrandImageAbsList[0] ?? null;
        $logoRuntime = $job->resolveLogoRuntime($item, $strategy, $meta, $selectedBrandImageAbs);
        $logoSceneAbs = isset($logoRuntime['abs']) && is_string($logoRuntime['abs']) ? $logoRuntime['abs'] : null;
        $logoScenePath = isset($logoRuntime['path']) && is_string($logoRuntime['path']) ? $logoRuntime['path'] : null;
        $logoRequested = (bool) ($logoRuntime['requested'] ?? false);
        $logoMode = (string) ($logoRuntime['mode'] ?? 'scene');
        $embedLogoInScene = $logoRequested
            ? (bool) $logoSceneAbs
            : $job->shouldEmbedLogoInScene($item, $selectedBrandImageAbs, $logoSceneAbs);

        $state->put('visual.prompt', $prompt)
            ->put('visual.brand_decision', $brandDecision)
            ->put('visual.selected_brand_image', $selectedBrandImage)
            ->put('visual.selected_brand_image_paths', $selectedBrandImagePaths)
            ->put('visual.selected_brand_image_abs', $selectedBrandImageAbs)
            ->put('visual.selected_brand_image_abs_list', $selectedBrandImageAbsList)
            ->put('visual.logo_runtime', $logoRuntime)
            ->put('visual.logo_scene_abs', $logoSceneAbs)
            ->put('visual.logo_scene_path', $logoScenePath)
            ->put('visual.logo_requested', $logoRequested)
            ->put('visual.logo_mode', $logoMode)
            ->put('visual.embed_logo_in_scene', $embedLogoInScene);

        return $state;
    }

    /**
     * Costruisce il blocco di istruzioni visive per tutti gli IdentityAsset del context.
     *
     * Se non ci sono slot IdentityAsset, ritorna stringa vuota → zero impatto sul prompt.
     *
     * @param  array<string, mixed>  $identityAssets
     */
    private function buildIdentityAssetsPromptBlock(array $identityAssets): string
    {
        $slots = (array) data_get($identityAssets, 'slots', []);
        if (empty($slots)) {
            return '';
        }

        $parts = [];
        foreach ($slots as $slot) {
            $prompt = trim((string) ($slot['identity_prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $parts[] = $prompt;
        }

        if (empty($parts)) {
            return '';
        }

        return implode("\n\n", $parts) . ' ';
    }
}

