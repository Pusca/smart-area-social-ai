<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\ContentFeedbackEntry;
use App\Models\SocialPublication;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\TrendBrief;
use App\Models\TrendIngestionRun;
use App\Models\TrendSignal;
use App\Models\TrendSnapshot;
use App\Models\User;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use App\Services\Editorial\TrendBriefService;
use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrendIntelligenceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_trend_snapshot_and_strategy_intelligence(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-strategy');

        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);

        $this->assertIsArray($strategy->trend_intelligence);
        $this->assertGreaterThan(0, (int) data_get($strategy->trend_intelligence, 'summary.opportunities_count', 0));
        $this->assertSame(1, TrendSnapshot::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, TrendIngestionRun::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0, TrendSignal::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0.40, (float) data_get($strategy->trend_intelligence, 'opportunities.0.brand_fit_score', 0.0));
        $this->assertEmpty(array_intersect((array) data_get($strategy->trend_intelligence, 'opportunities.0.risk_flags', []), (array) config('trends.risk_block_flags', [])));
        $signal = TrendSignal::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($signal);
        $this->assertNotSame('', (string) $signal?->source_ref);
        $this->assertNotSame('', (string) $signal?->title);
        $this->assertNotSame('', (string) $signal?->summary);
        $this->assertIsArray($signal?->niche_tags);
    }

    public function test_it_builds_trend_safe_plan_items_with_structured_opportunities(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-plan');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);

        $rows = app(EditorialPlanBuilder::class)->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: $strategy->toArray(),
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(13)->toDateString(),
                'total_posts' => 8,
            ],
            options: [
                'platforms' => ['instagram', 'facebook'],
                'formats' => ['reel', 'carousel', 'post'],
            ]
        );

        $trendRow = collect($rows)->first(fn (array $row) => (string) ($row['rubric'] ?? '') === 'Trend');

        $this->assertIsArray($trendRow);
        $this->assertNotEmpty((array) ($trendRow['trend_opportunity'] ?? []));
        $this->assertNotEmpty((array) ($trendRow['trend_scores'] ?? []));
        $this->assertNotEmpty((array) ($trendRow['trend_hook_patterns'] ?? []));
        $this->assertNotSame('', (string) ($trendRow['trend_bridge'] ?? ''));
        $this->assertContains((string) data_get($trendRow, 'editorial_mode'), ['trend-aware', 'reactive']);
        $this->assertContains((string) data_get($trendRow, 'trend_usage_mode'), ['trend_safe_adaptation', 'format_acceleration', 'reactive_commentary']);
        $this->assertIsNumeric(data_get($trendRow, 'trend_confidence'));
        $this->assertNotEmpty((array) data_get($trendRow, 'professionality_guardrails'));
        $this->assertNotSame('', (string) data_get($trendRow, 'reason_why_now'));
        $this->assertNotSame('', (string) data_get($trendRow, 'reason_why_brand_fit'));
        $this->assertNotSame('', (string) data_get($trendRow, 'expected_engagement_goal'));
        $this->assertSame('trend', (string) data_get($trendRow, 'source_refs.0.type'));
        $this->assertEmpty(array_intersect(
            (array) data_get($trendRow, 'trend_opportunity.risk_flags', []),
            (array) config('trends.risk_block_flags', [])
        ));
    }

    public function test_it_persists_trend_opportunity_inside_content_item_brain(): void
    {
        [$tenant, $user, $profile] = $this->bootstrapTenant('tenant-trend-content');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $rows = app(EditorialPlanBuilder::class)->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: $strategy->toArray(),
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(13)->toDateString(),
                'total_posts' => 8,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['reel', 'post'],
            ]
        );

        $trendRow = collect($rows)->first(fn (array $row) => (string) ($row['rubric'] ?? '') === 'Trend');
        $this->assertIsArray($trendRow);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Trend Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $created = app(ContentGenerator::class)->generateForPlan($plan, [$trendRow], [
            'user_id' => $user->id,
            'profile_data' => [],
            'strategy' => $strategy->toArray(),
            'memory' => [],
            'assets' => [],
        ]);

        $this->assertCount(1, $created);
        $meta = is_array($created[0]->ai_meta) ? $created[0]->ai_meta : [];
        $this->assertNotEmpty((array) data_get($meta, 'item_brain.trend_opportunity', []));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.trend_platform_hint', ''));
        $this->assertContains((string) data_get($meta, 'item_brain.editorial_mode'), ['trend-aware', 'reactive']);
        $this->assertContains((string) data_get($meta, 'item_brain.trend_usage_mode'), ['trend_safe_adaptation', 'format_acceleration', 'reactive_commentary']);
        $this->assertIsArray(data_get($meta, 'item_brain.trend_basis'));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.reason_why_now', ''));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.reason_why_brand_fit', ''));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.expected_engagement_goal', ''));
        $this->assertNotEmpty((array) data_get($meta, 'item_brain.professionality_guardrails', []));
        $this->assertGreaterThan(0, (int) data_get($meta, 'strategy.trend_intelligence.summary.opportunities_count', 0));
    }

    public function test_it_persists_a_reusable_trend_brief_with_freshness_and_confidence(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-brief');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);

        $brief = app(TrendBriefService::class)->getBriefForTenant(
            (int) $tenant->id,
            $profile,
            $strategy->toArray(),
            [
                'strategy' => $strategy->toArray(),
                'platforms' => ['instagram'],
                'formats' => ['reel', 'post'],
            ]
        );

        $this->assertSame(1, TrendBrief::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0.0, (float) ($brief['freshness_score'] ?? 0));
        $this->assertGreaterThan(0.0, (float) ($brief['confidence_score'] ?? 0));
        $this->assertNotEmpty((array) ($brief['current_relevant_themes'] ?? []));
        $this->assertNotEmpty((array) ($brief['recommended_hook_patterns'] ?? []));
        $this->assertIsArray((array) ($brief['signals_freshness'] ?? []));
        $this->assertNotNull(TrendBrief::query()->where('tenant_id', $tenant->id)->value('source_snapshot_id'));
    }

    public function test_internal_performance_signals_rank_above_weaker_curated_entries(): void
    {
        config()->set('trends.adapters', [
            ['driver' => 'internal_performance'],
            ['driver' => 'curated_manual'],
        ]);
        config()->set('trends.curated_manual_signals', [[
            'platform' => 'instagram',
            'topic' => 'weak curated angle',
            'title' => 'Weak curated angle',
            'summary' => 'Curated but less relevant.',
            'format_type' => 'post',
            'hook_patterns' => ['Hook generico.'],
            'style_notes' => ['Nota generica.'],
            'freshness_score' => 0.42,
            'confidence_score' => 0.44,
            'saturation_score' => 0.70,
            'industries' => ['generic'],
            'audiences' => ['broad'],
            'goals' => ['awareness'],
            'tags' => ['generic'],
        ]]);

        [$tenant, $user, $profile] = $this->bootstrapTenant('tenant-trend-ranking');
        $plan = $this->createPlan($tenant, $user, 'Trend Ranking');

        $item = \App\Models\ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'reel',
            'status' => 'published',
            'title' => 'Lead generation founder proof',
            'ai_status' => 'done',
            'published_at' => now()->subDay(),
            'ai_meta' => [
                'item_brain' => [
                    'trend_opportunity' => ['topic' => 'lead generation founder proof'],
                    'trend_basis' => ['topic' => 'lead generation founder proof'],
                ],
                'hook_meta' => [
                    'main_hook' => 'Mostra subito il dettaglio reale che ha cambiato la pipeline.',
                    'alternative_hook' => 'Founder POV: quello che conta davvero nelle lead.',
                ],
            ],
        ]);

        ContentFeedbackEntry::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'user_id' => $user->id,
            'sentiment' => ContentFeedbackEntry::SENTIMENT_LIKE,
            'action' => ContentFeedbackEntry::ACTION_RECORD_ONLY,
            'scope' => ContentFeedbackEntry::SCOPE_FULL,
            'normalized_category' => 'other',
            'severity' => ContentFeedbackEntry::SEVERITY_LOW,
            'reason' => 'Pattern molto forte.',
            'scores' => ['overall' => 0.92],
        ]);

        SocialPublication::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'provider' => 'meta',
            'platform' => 'instagram',
            'status' => 'published',
            'media_type' => 'video',
            'caption' => 'Founder proof.',
            'media_url' => 'https://example.test/reel.mp4',
            'scheduled_for' => now()->subDay(),
            'published_at' => now()->subDay(),
            'response_meta' => [
                'impressions' => 4200,
                'likes' => 180,
                'comments' => 24,
                'saves' => 33,
                'shares' => 15,
            ],
        ]);

        $trend = app(TrendOpportunitySynthesisService::class)->buildForTenant((int) $tenant->id, $profile, [
            'platforms' => ['instagram'],
            'formats' => ['reel'],
            'strategy' => ['analysis_framework' => ['primary_goal' => 'engagement']],
        ]);

        $this->assertNotEmpty((array) ($trend['opportunities'] ?? []));
        $this->assertSame('internal_performance_signal', (string) data_get($trend, 'opportunities.0.source_type'));
    }

    public function test_expired_signals_do_not_survive_opportunity_ranking(): void
    {
        config()->set('trends.adapters', [
            ['driver' => 'curated_manual'],
        ]);
        config()->set('trends.curated_manual_signals', [
            [
                'platform' => 'instagram',
                'topic' => 'expired but attractive trend',
                'title' => 'Expired trend',
                'summary' => 'Should not appear.',
                'format_type' => 'reel',
                'hook_patterns' => ['Hook scaduto.'],
                'style_notes' => ['Old trend.'],
                'freshness_score' => 0.95,
                'confidence_score' => 0.90,
                'saturation_score' => 0.10,
                'expires_at' => now()->subHour()->toDateTimeString(),
                'industries' => ['saas'],
                'audiences' => ['b2b'],
                'goals' => ['engagement'],
                'tags' => ['expired'],
            ],
            [
                'platform' => 'instagram',
                'topic' => 'fresh explainable trend',
                'title' => 'Fresh trend',
                'summary' => 'Should appear.',
                'format_type' => 'reel',
                'hook_patterns' => ['Hook fresco.'],
                'style_notes' => ['Fresh trend.'],
                'freshness_score' => 0.71,
                'confidence_score' => 0.73,
                'saturation_score' => 0.36,
                'expires_at' => now()->addDay()->toDateTimeString(),
                'industries' => ['saas'],
                'audiences' => ['b2b'],
                'goals' => ['engagement'],
                'tags' => ['fresh'],
            ],
        ]);

        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-expiry');

        $trend = app(TrendOpportunitySynthesisService::class)->buildForTenant((int) $tenant->id, $profile, [
            'platforms' => ['instagram'],
            'formats' => ['reel'],
            'strategy' => ['analysis_framework' => ['primary_goal' => 'engagement']],
        ]);

        $topics = collect((array) ($trend['opportunities'] ?? []))->pluck('topic')->all();

        $this->assertContains('fresh explainable trend', $topics);
        $this->assertNotContains('expired but attractive trend', $topics);
    }

    public function test_trend_brief_compilation_includes_active_signals_and_source_breakdown(): void
    {
        config()->set('trends.adapters', [
            ['driver' => 'curated_manual'],
            ['driver' => 'creator_best_practice'],
        ]);

        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-brief-sources');

        $brief = app(TrendBriefService::class)->getBriefForTenant(
            (int) $tenant->id,
            $profile,
            [],
            [
                'platforms' => ['instagram'],
                'formats' => ['reel', 'carousel'],
                'force_refresh' => true,
            ]
        );

        $this->assertNotEmpty((array) ($brief['active_signals'] ?? []));
        $this->assertNotEmpty((array) data_get($brief, 'summary.source_breakdown', []));
        $this->assertContains(
            'creator_best_practice_signal',
            array_keys((array) data_get($brief, 'summary.source_breakdown', []))
        );
        $this->assertContains(
            'curated_manual_signal',
            array_keys((array) data_get($brief, 'summary.source_breakdown', []))
        );
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Trend',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        $profile = TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Social AI Trend',
            'industry' => 'SaaS',
            'services' => 'Lead Generation, Social Strategy, Growth Content',
            'target' => 'PMI digitali e founder B2B',
            'cta' => 'Scrivici in DM.',
            'default_tone' => 'professionale',
        ]);

        return [$tenant, $user, $profile];
    }

    private function createPlan(Tenant $tenant, User $user, string $name = 'Trend Plan'): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => $name,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }
}
