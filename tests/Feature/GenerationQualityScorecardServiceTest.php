<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AI\GenerationQualityScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationQualityScorecardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_quality_scorecard_from_real_alignment_signals(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-pass');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'done',
            'ai_caption' => 'Una caption forte e coerente con il brand, pensata per far capire subito il valore del locale.',
            'ai_cta' => 'Prenota ora.',
            'ai_image_prompt' => 'Fotografia editoriale photorealistic del locale con luce naturale e atmosfera vera.',
            'ai_image_path' => 'ai/2026/03/quality-pass.png',
            'ai_meta' => [
                'tenant_profile' => [
                    'cta' => 'Prenota ora.',
                ],
                'manual_brief' => 'Mostra il ristorante in modo realistico e premium.',
                'text_alignment_review' => [
                    'overall_score' => 0.84,
                    'heuristic' => [
                        'cta_score' => 0.93,
                        'hard_rule_violations' => [],
                    ],
                    'llm' => [
                        'brand_alignment_score' => 0.88,
                        'brief_alignment_score' => 0.81,
                        'issues' => [],
                    ],
                ],
                'image_generation' => [
                    'provider' => 'openai',
                    'source' => 'brand_image_edit',
                    'brand_source_paths' => ['brand-assets/1/hero.jpg'],
                    'alignment_review' => [
                        'all_present' => true,
                        'confidence' => 0.86,
                        'missing_indexes' => [],
                        'summary' => 'Il riferimento principale e presente.',
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-pass-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'fallback_used' => false,
            'downgrade_used' => false,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('pass_with_warnings', $scorecard['publish_readiness_status']);
        $this->assertEquals(0.88, $scorecard['brand_voice_score']);
        $this->assertEquals(0.93, $scorecard['cta_compliance_score']);
        $this->assertEquals(0.86, $scorecard['reference_match_score']);
        $this->assertEquals(0.86, $scorecard['visual_identity_score']);
        $this->assertGreaterThan(0.70, (float) $scorecard['realism_score']);
        $this->assertEmpty($scorecard['blocking_reasons']);
        $this->assertNotEmpty($scorecard['warnings']);
        $this->assertStringContainsString('Realism score basato', (string) $scorecard['warnings'][0]);

        app(GenerationQualityScorecardService::class)->storeOnContentItem($item, $scorecard, $run);
        $item->save();
        $item->refresh();

        $this->assertSame('pass_with_warnings', data_get($item->ai_meta, 'quality_scorecard.publish_readiness_status'));
        $this->assertSame($run->id, data_get($item->ai_meta, 'generation_audit.quality_scorecard_run_id'));
    }

    public function test_it_blocks_publish_readiness_when_generation_failed_or_visual_output_is_missing(): void
    {
        [$tenant, $user] = $this->bootstrapTenant('tenant-quality-blocked');
        $plan = $this->createPlan($tenant, $user);
        $item = $this->createContentItem($tenant, $user, $plan, [
            'ai_status' => 'error',
            'ai_error' => 'STRICT_MODE_NO_VISUAL_OUTPUT',
            'ai_meta' => [
                'manual_brief' => 'Mostra il locale con la titolare.',
                'text_alignment_review' => [
                    'overall_score' => 0.52,
                    'heuristic' => [
                        'cta_score' => 0.35,
                        'hard_rule_violations' => [],
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'quality-blocked-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'failed',
            'format' => 'reel',
            'platform' => 'instagram',
            'fallback_used' => false,
            'downgrade_used' => false,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'failed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertSame('blocked', $scorecard['publish_readiness_status']);
        $this->assertContains('Manca un asset visuale finale utilizzabile.', $scorecard['blocking_reasons']);
        $this->assertContains('La generazione non e completata correttamente.', $scorecard['blocking_reasons']);
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Quality',
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
            'name' => 'Quality Plan',
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
            'title' => 'Quality item',
            'caption' => null,
            'hashtags' => [],
            'assets' => [],
            'ai_status' => 'queued',
            'ai_meta' => [],
        ], $overrides));
    }
}
