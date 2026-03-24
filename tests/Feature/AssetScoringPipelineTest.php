<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AI\Pipeline\BuildGenerationContextStep;
use App\Services\AI\Pipeline\BuildVisualPromptStep;
use App\Services\AI\Pipeline\GenerationPipelineState;
use App\Services\AI\Pipeline\ResolveProviderMatrixStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetScoringPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_asset_scoring_on_run_and_uses_it_in_visual_prompt_selection(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'name' => 'Tenant Asset Scoring',
            'slug' => 'tenant-asset-scoring',
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Demo',
            'industry' => 'Media',
            'cta' => 'Scrivici per una demo.',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Asset Scoring Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $front = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/silvia-front.jpg',
            'original_name' => 'silvia-front.jpg',
            'mime' => 'image/jpeg',
            'meta' => [
                'quality_score' => 0.94,
                'source' => 'guided_persona_pack',
            ],
        ]);
        $threeQuarter = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/silvia-three-quarter.jpg',
            'original_name' => 'silvia-three-quarter.jpg',
            'mime' => 'image/jpeg',
            'meta' => [
                'quality_score' => 0.82,
                'source' => 'brand_center_variable_extension',
                'training_priority' => 'supporting',
            ],
        ]);

        Storage::disk('public')->put((string) $front->path, 'front');
        Storage::disk('public')->put((string) $threeQuarter->path, 'three-quarter');

        AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Silvia Bellot',
            'slug' => 'silvia-bellot',
            'kind' => 'person',
            'asset_role' => 'presenter',
            'description' => 'Founder e volto del brand',
            'asset_ids' => [$front->id, $threeQuarter->id],
            'canonical_asset_id' => $front->id,
            'identity_mode' => 'strict',
            'consistency_threshold' => 92,
            'profile' => [
                'descriptor' => ['summary' => 'Volto sorridente in studio'],
                'shot_summary' => [
                    [
                        'asset_id' => $front->id,
                        'path' => $front->path,
                        'slot' => 'front',
                        'label' => 'Front portrait',
                    ],
                    [
                        'asset_id' => $threeQuarter->id,
                        'path' => $threeQuarter->path,
                        'slot' => 'three_quarter',
                        'label' => 'Three quarter',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $item = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'scheduled_at' => now()->addDay(),
            'status' => 'draft',
            'title' => 'Silvia reel',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [
                'manual_brief' => 'Silvia Bellot in studio racconta il nuovo format.',
                'video_provider' => 'runway',
                'video_provider_lock' => true,
            ],
        ]);

        $job = new GenerateAiForContentItem((int) $item->id, 'asset-scoring-pipeline-run');
        $state = GenerationPipelineState::fromItem($item->fresh(['plan']));

        $state = app(BuildGenerationContextStep::class)->handle($job, $state);
        $state = app(ResolveProviderMatrixStep::class)->handle($job, $state);
        $state = app(BuildVisualPromptStep::class)->handle($job, $state);

        $item->refresh();
        $state->run?->refresh();

        $this->assertSame((string) $front->path, data_get($state->meta, 'asset_scoring.primary_asset.path'));
        $this->assertSame((string) $front->path, data_get($state->run, 'requested_output.asset_selection.primary_asset.path'));
        $this->assertSame((string) $front->path, data_get($state->get('visual.selected_brand_image_paths', []), '0'));
        $this->assertGreaterThan(0.70, (float) data_get($state->meta, 'asset_scoring.identity_confidence', 0));
    }
}


