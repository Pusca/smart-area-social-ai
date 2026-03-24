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
            $visualRules = data_get($itemBrain, 'image_direction', 'Visual coerente con il brand.');
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
                . "Percorso logo di riferimento (solo contesto stilistico): {$logoPath}. "
                . ($selectedBrandImage ? "Parti dai riferimenti brand forniti e trasformali in un visual editoriale strategico, non in una semplice copia della foto originale. " : "Crea la composizione da zero seguendo la strategia e mantenendo novita rispetto ai post precedenti. ")
                . "Non generare loghi finti, nome brand scritto, watermark o testo tipografico lungo direttamente nell'immagine: prepara piuttosto una composizione overlay-ready. "
                . "Stile professionale, coerente con il brand e totalmente in italiano.";

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
}

