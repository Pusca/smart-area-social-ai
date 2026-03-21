<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationAttempt;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AI\Pipeline\BuildGenerationContextStep;
use App\Services\AI\Pipeline\GenerationPipelineState;
use App\Services\AI\Pipeline\PersistGenerationOutputsStep;
use App\Services\AI\Pipeline\ResolveProviderMatrixStep;
use App\Services\GenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GenerationPipelineStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_generation_context_and_resolves_provider_matrix(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-pipeline-context');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'title' => 'Pipeline reel',
            'caption' => 'Mostra il locale e la titolare',
            'ai_meta' => [
                'video_provider' => 'runway',
                'video_provider_lock' => true,
                'tenant_profile' => [
                    'business_name' => 'Do Mori',
                    'industry' => 'Ristorante',
                ],
                'item_brain' => [
                    'objective' => 'Awareness',
                ],
            ],
        ]);

        $job = new GenerateAiForContentItem((int) $item->id, 'pipeline-context-run');
        $state = GenerationPipelineState::fromItem($item->fresh(['plan']));

        $state = app(BuildGenerationContextStep::class)->handle($job, $state);
        $state = app(ResolveProviderMatrixStep::class)->handle($job, $state);

        $item->refresh();
        $state->run?->refresh();

        $this->assertNotNull($state->run);
        $this->assertSame('pending', $item->ai_status);
        $this->assertSame('running', data_get($item->ai_meta, 'generation_audit.latest_status'));
        $this->assertSame('runway', data_get($state->meta, 'provider_matrix.video.provider'));
        $this->assertTrue((bool) data_get($state->run?->requested_output, 'video_provider_lock'));
        $this->assertSame('runway', data_get($state->run?->resolved_provider_matrix, 'video.provider'));
        $this->assertSame('runway', data_get($state->run?->version_meta, 'provider_adapter_versions.video.provider'));
        $this->assertSame('runway_video_adapter_v1', data_get($item->ai_meta, 'generation_audit.version_map.provider_adapter_versions.video.adapter_version'));
        $this->assertSame('Mostra il locale e la titolare', $state->get('brief_seed'));
        $this->assertIsArray($state->get('tenant_profile'));
        $this->assertIsArray($state->get('asset_variables'));
    }

    public function test_it_marks_generation_as_done_when_visual_output_exists(): void
    {
        Notification::fake();

        [$tenant, $user] = $this->bootstrapTenant('tenant-pipeline-success');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_caption' => 'Scopri il locale e prenota la tua esperienza veneziana.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_path' => 'ai/2026/03/generated.png',
            'ai_meta' => [
                'tenant_profile' => [
                    'cta' => 'Prenota ora.',
                ],
                'manual_brief' => 'Mostra il locale in modo realistico.',
                'text_alignment_review' => [
                    'overall_score' => 0.81,
                    'heuristic' => [
                        'cta_score' => 0.91,
                        'hard_rule_violations' => [],
                    ],
                    'llm' => [
                        'brand_alignment_score' => 0.84,
                        'brief_alignment_score' => 0.79,
                        'issues' => [],
                    ],
                ],
                'image_generation' => [
                    'provider' => 'openai',
                    'source' => 'brand_image_edit',
                    'brand_source_paths' => ['brand-assets/demo/reference.jpg'],
                    'alignment_review' => [
                        'all_present' => true,
                        'confidence' => 0.82,
                        'missing_indexes' => [],
                    ],
                ],
            ],
        ]);

        $run = app(GenerationAuditService::class)->startRun($item, 'pipeline-success-run');
        GenerationAttempt::create([
            'generation_run_id' => $run->id,
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'sequence' => 1,
            'type' => 'image',
            'stage' => 'visual_asset',
            'step' => 'visual_asset',
            'status' => 'succeeded',
            'provider_requested' => 'openai',
            'provider_effective' => 'openai',
            'model_requested' => 'gpt-image-1',
            'model_effective' => 'gpt-image-1',
            'estimated_cost_usd' => 0.0420,
            'final_provider' => 'openai',
            'runtime_ms' => 1200,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);
        $state = GenerationPipelineState::fromItem($item->fresh(['plan']));
        $state->run = $run;

        app(PersistGenerationOutputsStep::class)->handle(new GenerateAiForContentItem((int) $item->id, 'pipeline-success-run'), $state);

        $item->refresh();
        $run?->refresh();

        $this->assertSame('done', $item->ai_status);
        $this->assertSame('succeeded', $run?->status);
        $this->assertEquals('0.0420', (string) $run?->estimated_cost_usd);
        $this->assertSame('openai', $run?->final_provider);
        $this->assertIsArray($run?->quality_scorecard);
        $this->assertSame('pass_with_warnings', data_get($run?->quality_scorecard, 'publish_readiness_status'));
        $this->assertSame('pass_with_warnings', data_get($item->ai_meta, 'quality_scorecard.publish_readiness_status'));
        $this->assertSame('succeeded', data_get($item->ai_meta, 'generation_audit.latest_status'));
    }

    public function test_it_marks_generation_as_failed_when_strict_mode_has_no_visual_output(): void
    {
        Notification::fake();

        [$tenant, $user] = $this->bootstrapTenant('tenant-pipeline-failure');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'ai_error' => 'Runway video generation failed',
        ]);

        $run = app(GenerationAuditService::class)->startRun($item, 'pipeline-failure-run');
        $state = GenerationPipelineState::fromItem($item->fresh(['plan']), true);
        $state->run = $run;

        app(PersistGenerationOutputsStep::class)->handle(new GenerateAiForContentItem((int) $item->id, 'pipeline-failure-run'), $state);

        $item->refresh();
        $run?->refresh();

        $this->assertSame('error', $item->ai_status);
        $this->assertStringContainsString('STRICT_MODE_NO_VISUAL_OUTPUT', (string) $item->ai_error);
        $this->assertSame('failed', $run?->status);
        $this->assertSame('blocked', data_get($run?->quality_scorecard, 'publish_readiness_status'));
        $this->assertSame('blocked', data_get($item->ai_meta, 'quality_scorecard.publish_readiness_status'));
        $this->assertSame('failed', data_get($item->ai_meta, 'generation_audit.latest_status'));
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Pipeline',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Do Mori',
            'industry' => 'Ristorante',
            'services' => 'Cucina veneziana',
            'target' => 'Turisti e residenti',
            'cta' => 'Prenota ora.',
            'default_tone' => 'caldo',
        ]);

        return [$tenant, $user];
    }

    private function createPlan(Tenant $tenant, User $user): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Pipeline Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }

    private function createContentItem(Tenant $tenant, User $user, ContentPlan $plan, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDay(),
            'status' => 'draft',
            'title' => 'Pipeline item',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [],
        ], $overrides));
    }
}
