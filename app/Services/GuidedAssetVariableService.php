<?php

namespace App\Services;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\User;
use App\Support\SpeechProviderResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuidedAssetVariableService
{
    public function __construct(
        private readonly AssetVariableService $assetVariableService,
        private readonly AssetIdentityService $assetIdentityService
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{variable: AssetVariable, assets: array<int, BrandAsset>}
     */
    public function createPersonaPack(User $user, array $data): array
    {
        $tenantId = (int) $user->tenant_id;
        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->assetVariableService->buildUniqueSlugForTenant($tenantId, $name);
        $baseDir = 'brand-assets/' . $tenantId . '/persona-packs/' . $slug;

        $shots = [
            'front' => ['label' => 'Frontale', 'file' => $data['shot_front'] ?? null],
            'three_quarter_left' => ['label' => 'Tre quarti sinistra', 'file' => $data['shot_three_quarter_left'] ?? null],
            'three_quarter_right' => ['label' => 'Tre quarti destra', 'file' => $data['shot_three_quarter_right'] ?? null],
            'profile' => ['label' => 'Profilo', 'file' => $data['shot_profile'] ?? null],
            'half_body' => ['label' => 'Mezzo busto', 'file' => $data['shot_half_body'] ?? null],
        ];

        return DB::transaction(function () use ($tenantId, $data, $name, $slug, $baseDir, $shots): array {
            $assets = [];
            $assetIds = [];
            $shotSummary = [];
            $primaryStillAssetId = null;
            $referenceVideoAssetId = null;
            $referenceVideoPath = null;
            $voiceAssetId = null;
            $voiceAssetPath = null;
            $voiceAssetName = null;

            foreach ($shots as $slot => $payload) {
                $file = $payload['file'] ?? null;
                if (!$file instanceof UploadedFile) {
                    continue;
                }

                $path = $file->store($baseDir . '/images', 'public');
                $asset = BrandAsset::query()->create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => null,
                    'kind' => 'image',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'meta' => [
                        'source' => 'guided_persona_pack',
                        'slot' => $slot,
                        'slot_label' => (string) ($payload['label'] ?? $slot),
                        'variable_kind' => 'person',
                        'variable_name' => $name,
                        'training_priority' => $slot === 'front' ? 'primary' : 'supporting',
                    ],
                ]);

                $assets[] = $asset;
                $assetIds[] = (int) $asset->id;
                $shotSummary[] = [
                    'slot' => $slot,
                    'label' => (string) ($payload['label'] ?? $slot),
                    'asset_id' => (int) $asset->id,
                    'path' => (string) $asset->path,
                ];

                if ($slot === 'front' || $primaryStillAssetId === null) {
                    $primaryStillAssetId = (int) $asset->id;
                }
            }

            $referenceVideo = $data['reference_video'] ?? null;
            if ($referenceVideo instanceof UploadedFile) {
                $videoPath = $referenceVideo->store($baseDir . '/video', 'public');
                $videoAsset = BrandAsset::query()->create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => null,
                    'kind' => 'video',
                    'path' => $videoPath,
                    'original_name' => $referenceVideo->getClientOriginalName(),
                    'size' => $referenceVideo->getSize(),
                    'mime' => $referenceVideo->getMimeType(),
                    'meta' => [
                        'source' => 'guided_persona_pack',
                        'slot' => 'reference_video',
                        'slot_label' => 'Video riferimento',
                        'variable_kind' => 'person',
                        'variable_name' => $name,
                    ],
                ]);

                $assets[] = $videoAsset;
                $assetIds[] = (int) $videoAsset->id;
                $referenceVideoAssetId = (int) $videoAsset->id;
                $referenceVideoPath = (string) $videoAsset->path;
            }

            $voiceSample = $data['voice_sample'] ?? null;
            if ($voiceSample instanceof UploadedFile) {
                $voicePath = $voiceSample->store($baseDir . '/voice', 'public');
                $voiceAsset = BrandAsset::query()->create([
                    'tenant_id' => $tenantId,
                    'content_plan_id' => null,
                    'kind' => 'audio',
                    'path' => $voicePath,
                    'original_name' => $voiceSample->getClientOriginalName(),
                    'size' => $voiceSample->getSize(),
                    'mime' => $voiceSample->getMimeType(),
                    'meta' => [
                        'source' => 'guided_persona_pack',
                        'slot' => 'voice_sample',
                        'slot_label' => 'Campione voce',
                        'variable_kind' => 'person',
                        'variable_name' => $name,
                    ],
                ]);

                $assets[] = $voiceAsset;
                $voiceAssetId = (int) $voiceAsset->id;
                $voiceAssetPath = (string) $voiceAsset->path;
                $voiceAssetName = (string) ($voiceAsset->original_name ?? '');
            }

            $voiceProvider = $voiceAssetId ? $this->defaultVoiceCloneProvider() : null;
            $voiceStatus = $voiceAssetId ? 'sample_ready' : null;

            $profile = [
                'source_mode' => 'guided_persona_pack',
                'role' => trim((string) ($data['persona_role'] ?? '')),
                'identity_summary' => trim((string) ($data['description'] ?? '')),
                'immutable_traits' => trim((string) ($data['immutable_traits'] ?? '')),
                'descriptor' => [
                    'summary' => trim((string) ($data['description'] ?? '')),
                    'stable_traits' => trim((string) ($data['immutable_traits'] ?? '')),
                ],
                'look_notes' => trim((string) ($data['look_notes'] ?? '')),
                'styling_notes' => trim((string) ($data['styling_notes'] ?? '')),
                'prompt_lock' => [
                    'immutable_elements' => trim((string) ($data['immutable_traits'] ?? '')),
                    'lock_copy' => trim((string) ($data['immutable_traits'] ?? '')),
                ],
                'allowed_transforms' => $this->assetIdentityService->parseAllowedTransforms([
                    'pose variation',
                    'camera angle variation',
                    'lighting adaptation',
                    'seasonal props',
                    'brand styling changes',
                ]),
                'prompt_notes' => trim((string) ($data['prompt_notes'] ?? '')),
                'usage_notes' => trim((string) ($data['usage_notes'] ?? '')),
                'shot_count' => count($shotSummary),
                'shot_summary' => $shotSummary,
                'recommended_prompt' => $this->buildRecommendedPrompt($name, $data, $shotSummary, $referenceVideoPath, $voiceAssetPath),
                'preferred_still_asset_id' => $primaryStillAssetId,
                'canonical_asset_id' => $primaryStillAssetId,
                'reference_video_asset_id' => $referenceVideoAssetId,
                'reference_video_path' => $referenceVideoPath,
                'reference_voice_asset_id' => $voiceAssetId,
                'reference_voice_path' => $voiceAssetPath,
                'voice_reference' => [
                    'label' => 'Campione voce reale',
                    'sample_asset_id' => $voiceAssetId,
                    'sample_path' => $voiceAssetPath,
                    'sample_name' => $voiceAssetName,
                    'provider' => $voiceProvider,
                    'provider_voice_id' => null,
                    'status' => $voiceStatus,
                ],
                'created_from_brand_center' => true,
            ];

            $variable = AssetVariable::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'kind' => 'person',
                'asset_role' => 'presenter',
                'description' => trim((string) ($data['description'] ?? '')),
                'asset_ids' => array_values(array_unique(array_filter(array_map(
                    fn ($id) => (int) $id,
                    $assetIds
                ), fn ($id) => $id > 0))),
                'canonical_asset_id' => $primaryStillAssetId,
                'voice_asset_id' => $voiceAssetId,
                'voice_provider' => $voiceProvider,
                'voice_provider_voice_id' => null,
                'voice_status' => $voiceStatus,
                'identity_mode' => 'strict',
                'consistency_threshold' => 92,
                'profile' => $profile,
                'identity_pack' => [],
                'is_active' => true,
            ]);

            $variable->identity_pack = $this->assetIdentityService->synthesizeIdentityPackForVariable($variable, collect($assets));
            $variable->save();

            $this->assetIdentityService->syncAssetMetaForVariable($variable, $assetIds, $voiceAssetId ? [$voiceAssetId] : []);

            return [
                'variable' => $variable,
                'assets' => $assets,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $shotSummary
     */
    private function buildRecommendedPrompt(
        string $name,
        array $data,
        array $shotSummary,
        ?string $referenceVideoPath,
        ?string $referenceVoicePath
    ): string {
        $parts = [];
        $parts[] = "Usa {$name} come persona di riferimento costante.";

        $role = trim((string) ($data['persona_role'] ?? ''));
        if ($role !== '') {
            $parts[] = "Ruolo o contesto: {$role}.";
        }

        $immutableTraits = trim((string) ($data['immutable_traits'] ?? ''));
        if ($immutableTraits !== '') {
            $parts[] = "Tratti da non cambiare mai: {$immutableTraits}.";
        }

        $lookNotes = trim((string) ($data['look_notes'] ?? ''));
        if ($lookNotes !== '') {
            $parts[] = "Aspetto e presenza: {$lookNotes}.";
        }

        $stylingNotes = trim((string) ($data['styling_notes'] ?? ''));
        if ($stylingNotes !== '') {
            $parts[] = "Stile, outfit e presenza scenica: {$stylingNotes}.";
        }

        $promptNotes = trim((string) ($data['prompt_notes'] ?? ''));
        if ($promptNotes !== '') {
            $parts[] = "Indicazioni dirette per il generatore: {$promptNotes}.";
        }

        if (!empty($shotSummary)) {
            $labels = array_values(array_filter(array_map(
                fn ($shot) => trim((string) ($shot['label'] ?? '')),
                $shotSummary
            )));
            if (!empty($labels)) {
                $parts[] = 'Pack fotografico disponibile: ' . implode(', ', $labels) . '.';
            }
        }

        if (is_string($referenceVideoPath) && $referenceVideoPath !== '') {
            $parts[] = 'E presente anche un video reale di riferimento per movimenti, postura e mimica.';
        }

        if (is_string($referenceVoicePath) && $referenceVoicePath !== '') {
            $parts[] = 'E disponibile anche un campione voce reale da usare come riferimento per voiceover e narrazione video.';
        }

        $parts[] = 'Mantieni identita, lineamenti, presenza e, quando disponibile, anche la voce della persona coerenti tra immagini e video, variando solo posa, scena, luce e styling quando utile.';

        return Str::limit(implode(' ', $parts), 1200, '');
    }

    private function defaultVoiceCloneProvider(): ?string
    {
        $provider = strtolower(trim((string) config('generation.voice_clone_provider_default', 'elevenlabs')));
        if ($provider === '') {
            return null;
        }

        $provider = SpeechProviderResolver::normalize($provider);

        return $provider === 'elevenlabs' ? $provider : null;
    }
}
