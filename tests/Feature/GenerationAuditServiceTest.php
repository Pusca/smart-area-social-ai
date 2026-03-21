<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\GenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class GenerationAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_generation_run_and_demo_attempt_for_demo_mode_job(): void
    {
        config()->set('app.demo_mode', true);
        Notification::fake();

        [$tenant, $user] = $this->bootstrapTenant('tenant-audit-demo');
        $plan = $this->createPlan($tenant, $user);
        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/demo/reference.jpg',
            'original_name' => 'reference.jpg',
            'mime' => 'image/jpeg',
        ]);

        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'post',
            'title' => 'Demo content',
            'ai_meta' => [
                'tenant_profile' => ['business_name' => 'Hostup'],
                'item_brain' => ['angle' => 'Automazione property management'],
            ],
        ]);

        GenerateAiForContentItem::dispatchSync((int) $item->id);

        $item->refresh();
        $run = GenerationRun::query()
            ->where('content_item_id', $item->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('content_item', $run->scope);
        $this->assertSame('job', $run->trigger_source);
        $this->assertSame('post', $run->format);
        $this->assertSame(1, $run->attempt_count);
        $this->assertSame('legacy_inline_prompts_v1', data_get($run->version_meta, 'prompt_template_version'));
        $this->assertSame('editorial_strategy_compose_v1', data_get($run->version_meta, 'strategy_composer_version'));
        $this->assertSame('openai_text_adapter_v1', data_get($run->version_meta, 'provider_adapter_versions.text.adapter_version'));
        $this->assertSame('done', $item->ai_status);
        $this->assertSame($run->id, data_get($item->ai_meta, 'generation_audit.latest_run_id'));
        $this->assertSame('succeeded', data_get($item->ai_meta, 'generation_audit.latest_status'));
        $this->assertSame('legacy_inline_prompts_v1', data_get($item->ai_meta, 'generation_audit.version_map.prompt_template_version'));

        $attempt = $run->attempts()->first();
        $this->assertNotNull($attempt);
        $this->assertSame('demo_preset', $attempt->step);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('local_demo', $attempt->provider_effective);
    }

    public function test_it_marks_a_generation_run_as_failed_from_the_job_failed_hook(): void
    {
        Notification::fake();

        [$tenant, $user] = $this->bootstrapTenant('tenant-audit-fail');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan);

        $runKey = 'run-failure-test-key';
        $service = app(GenerationAuditService::class);
        $run = $service->startRun($item, $runKey, [
            'requested_output' => ['format' => 'post'],
            'version_meta' => ['pipeline_version' => 'test'],
        ]);

        $job = new GenerateAiForContentItem((int) $item->id, $runKey);
        $job->failed(new RuntimeException('Synthetic failure'));

        $item->refresh();
        $run->refresh();

        $this->assertSame('error', $item->ai_status);
        $this->assertSame('JOB: Synthetic failure', $item->ai_error);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Synthetic failure', (string) $run->last_error);
        $this->assertSame($run->id, data_get($item->ai_meta, 'generation_audit.latest_run_id'));
        $this->assertSame('failed', data_get($item->ai_meta, 'generation_audit.latest_status'));
    }

    public function test_it_sequences_attempts_within_a_generation_run(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-audit-seq');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan);

        $service = app(GenerationAuditService::class);
        $run = $service->startRun($item, 'run-sequence-key');
        $first = $service->startAttempt($run, 'text_blueprint');
        $second = $service->startAttempt($run->fresh(), 'visual_asset');

        $run->refresh();

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(2, $run->attempt_count);
    }

    public function test_it_records_failed_attempts_and_exposes_run_timeline(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-audit-timeline');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'format' => 'reel',
            'ai_meta' => [
                'video_provider' => 'runway',
                'video_provider_lock' => true,
            ],
        ]);

        $service = app(GenerationAuditService::class);
        $run = $service->startRun($item, 'run-timeline-key', [
            'requested_output' => [
                'format' => 'reel',
                'requested_video_seconds' => 20,
            ],
        ]);
        $attempt = $service->startAttempt($run, 'visual_asset', [
            'type' => 'video',
            'provider_requested' => 'runway',
            'provider_locked' => true,
            'model_requested' => 'gen4.5',
            'requested_duration_seconds' => 20,
            'normalized_duration_seconds' => 10,
            'input_summary' => [
                'kind' => 'video',
                'format' => 'reel',
            ],
        ]);

        $service->failAttempt($attempt, new RuntimeException('Synthetic visual failure'), [
            'provider_effective' => 'runway',
            'model_effective' => 'gen4.5',
            'external_request_id' => 'task_123',
            'output_references' => [
                'reference_paths' => ['brand-assets/reel/reference.jpg'],
            ],
        ]);
        $service->failRun($run, 'Synthetic visual failure');

        $timeline = $service->timelineForContentItem($item, 'run-timeline-key');

        $this->assertNotNull($timeline);
        $this->assertSame('failed', data_get($timeline, 'run.status'));
        $this->assertSame(1, count((array) data_get($timeline, 'attempts', [])));
        $this->assertSame('visual_asset', data_get($timeline, 'attempts.0.stage'));
        $this->assertSame('video', data_get($timeline, 'attempts.0.type'));
        $this->assertTrue((bool) data_get($timeline, 'attempts.0.provider_locked'));
        $this->assertSame(20, data_get($timeline, 'attempts.0.requested_duration_seconds'));
        $this->assertSame(10, data_get($timeline, 'attempts.0.normalized_duration_seconds'));
        $this->assertSame('task_123', data_get($timeline, 'attempts.0.external_request_id'));
        $this->assertSame('Synthetic visual failure', data_get($timeline, 'attempts.0.error_message'));
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Audit',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Hostup',
            'industry' => 'SaaS',
            'services' => 'Lead Generation, Social Strategy',
            'target' => 'PMI digitali',
            'cta' => 'Scrivici in DM.',
            'default_tone' => 'professionale',
        ]);

        return [$tenant, $user];
    }

    private function createPlan(Tenant $tenant, User $user): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Audit Plan',
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
            'title' => 'Audit item',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [],
        ], $overrides));
    }
}
