<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\TrendSignal;
use App\Models\TrendSnapshot;
use App\Models\User;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
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
        $this->assertGreaterThan(0, TrendSignal::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThan(0.40, (float) data_get($strategy->trend_intelligence, 'opportunities.0.brand_fit_score', 0.0));
        $this->assertEmpty(array_intersect((array) data_get($strategy->trend_intelligence, 'opportunities.0.risk_flags', []), (array) config('trends.risk_block_flags', [])));
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
}