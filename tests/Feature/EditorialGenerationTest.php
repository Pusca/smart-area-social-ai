<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\DuplicateContentGuard;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EditorialGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_create_duplicate_fingerprints(): void
    {
        [$tenant, $user, $profile] = $this->bootstrapTenant();

        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $guard = app(DuplicateContentGuard::class);
        $generator = app(ContentGenerator::class);

        $existingFingerprint = $guard->fingerprint((int) $tenant->id, [
            'platform' => 'instagram',
            'format' => 'post',
            'rubric' => 'Educativo',
            'pillar' => 'Lead Generation',
            'content_angle' => 'Checklist operativa lead generation',
            'keywords' => 'lead generation checklist',
        ]);

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => 1,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Educativo: Lead Generation',
            'caption' => null,
            'rubric' => 'Educativo',
            'pillar' => 'Lead Generation',
            'content_angle' => 'Checklist operativa lead generation',
            'fingerprint' => $existingFingerprint,
            'ai_status' => 'done',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Test Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $created = $generator->generateForPlan($plan, [[
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'rubric' => 'Educativo',
            'series_key' => null,
            'episode_number' => null,
            'pillar' => 'Lead Generation',
            'content_angle' => 'Checklist operativa lead generation',
            'primary_cta' => 'Commenta la tua esperienza.',
            'title_hint' => 'Educativo: Lead Generation',
            'source_refs' => [],
            'objective' => 'Awareness',
            'key_points' => [],
            'image_direction' => 'Visual pulito',
            'keywords' => 'lead generation checklist',
        ]], [
            'user_id' => $user->id,
            'profile_data' => [],
            'strategy' => $strategy->toArray(),
            'memory' => [],
            'assets' => [],
        ]);

        $this->assertCount(1, $created);
        $this->assertNotSame($existingFingerprint, $created[0]->fingerprint);
        $this->assertSame(1, ContentItem::query()->where('fingerprint', $existingFingerprint)->count());
    }

    public function test_it_changes_angle_when_similarity_is_high(): void
    {
        [$tenant, $user, $profile] = $this->bootstrapTenant('tenant-soft');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $strategyPayload = $strategy->toArray();
        $strategyPayload['constraints']['soft_similarity_threshold'] = 0.30;
        $generator = app(ContentGenerator::class);

        ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => 1,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Ridurre i costi marketing',
            'caption' => 'Checklist operativa per ridurre i costi marketing in 3 passi concreti.',
            'rubric' => 'Educativo',
            'pillar' => 'Lead Generation',
            'content_angle' => 'Checklist operativa per ridurre i costi marketing',
            'fingerprint' => 'old-soft-fingerprint',
            'ai_status' => 'done',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Soft Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $candidateAngle = 'Checklist operativa per ridurre i costi marketing';
        $created = $generator->generateForPlan($plan, [[
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'rubric' => 'Educativo',
            'series_key' => null,
            'episode_number' => null,
            'pillar' => 'Lead Generation',
            'content_angle' => $candidateAngle,
            'primary_cta' => 'Commenta la tua esperienza.',
            'title_hint' => 'Ridurre i costi marketing',
            'source_refs' => [],
            'objective' => 'Awareness',
            'key_points' => [],
            'image_direction' => 'Visual pulito',
            'keywords' => 'ridurre costi marketing',
        ]], [
            'user_id' => $user->id,
            'profile_data' => [],
            'strategy' => $strategyPayload,
            'memory' => [],
            'assets' => [],
        ]);

        $this->assertCount(1, $created);
        $this->assertNotSame($candidateAngle, $created[0]->content_angle);
        $this->assertNotNull($created[0]->similarity_group);
    }

    public function test_it_respects_rubric_mix_on_14_day_plan(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-mix');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: [
                'pillars' => $strategy->pillars,
                'rubrics' => $strategy->rubrics,
                'cta_rules' => $strategy->cta_rules,
                'constraints' => $strategy->constraints,
            ],
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(13)->toDateString(),
                'total_posts' => 14,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['post', 'carousel', 'reel'],
            ]
        );

        $this->assertCount(14, $rows);
        $counts = [];
        foreach ($rows as $row) {
            $name = (string) ($row['rubric'] ?? '');
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $this->assertGreaterThanOrEqual(5, (int) ($counts['Educativo'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($counts['Prova Sociale'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($counts['Storia Brand'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($counts['Offerta'] ?? 0));
    }

    public function test_it_balances_editorial_modes_without_turning_everything_into_trend_content(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-mode-mix');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: $strategy->toArray(),
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(13)->toDateString(),
                'total_posts' => 14,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['post', 'carousel', 'reel'],
            ]
        );

        $this->assertCount(14, $rows);
        $modeCounts = [];
        foreach ($rows as $row) {
            $mode = (string) ($row['editorial_mode'] ?? '');
            $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
        }

        $this->assertGreaterThanOrEqual(2, (int) ($modeCounts['evergreen'] ?? 0));
        $this->assertGreaterThanOrEqual(4, (int) ($modeCounts['authority-building'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($modeCounts['conversion-oriented'] ?? 0));
        $this->assertGreaterThanOrEqual(1, ((int) ($modeCounts['trend-aware'] ?? 0)) + ((int) ($modeCounts['reactive'] ?? 0)));
        $this->assertLessThanOrEqual(2, ((int) ($modeCounts['trend-aware'] ?? 0)) + ((int) ($modeCounts['reactive'] ?? 0)));
    }

    public function test_it_filters_out_incoherent_trends_before_using_them_in_plan(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-trend-filter');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile)->toArray();
        $strategy['trend_intelligence'] = [
            'enabled' => true,
            'summary' => ['opportunities_count' => 2],
            'opportunities' => [
                [
                    'signal_id' => 991,
                    'snapshot_id' => 88,
                    'headline' => 'Trend audio imitation meme',
                    'topic' => 'trend audio imitation meme',
                    'platform' => 'tiktok',
                    'format_type' => 'reel',
                    'hook_patterns' => ['Replica audio con battuta memetica.'],
                    'style_notes' => ['Ritmo memetico.'],
                    'brand_safe_hook' => 'Apri con un hook virale.',
                    'recommended_angle' => 'Copia il meme del momento.',
                    'why_it_fits' => 'Non dovrebbe passare.',
                    'risk_flags' => ['brand_conflict'],
                    'freshness_score' => 0.93,
                    'estimated_relevance_score' => 0.78,
                    'brand_fit_score' => 0.31,
                    'novelty_score' => 0.91,
                    'execution_feasibility_score' => 0.82,
                    'viral_potential_score' => 0.88,
                    'source_type' => 'manual_test',
                ],
                [
                    'signal_id' => 992,
                    'snapshot_id' => 89,
                    'headline' => 'Operator insight with practical lesson',
                    'topic' => 'operator insight with practical lesson',
                    'platform' => 'linkedin',
                    'format_type' => 'post',
                    'hook_patterns' => ['Apri con un insight concreto vissuto sul campo.'],
                    'style_notes' => ['Tono competente e utile.'],
                    'brand_safe_hook' => 'Apri con un insight controintuitivo ma concreto.',
                    'recommended_angle' => 'Traduci il trend in un insight operativo per founder B2B.',
                    'why_it_fits' => 'Funziona per un tenant SaaS professionale e orientato alla lead generation.',
                    'risk_flags' => [],
                    'freshness_score' => 0.74,
                    'estimated_relevance_score' => 0.79,
                    'brand_fit_score' => 0.83,
                    'novelty_score' => 0.69,
                    'execution_feasibility_score' => 0.77,
                    'viral_potential_score' => 0.63,
                    'source_type' => 'manual_test',
                ],
            ],
        ];

        $rows = app(EditorialPlanBuilder::class)->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: $strategy,
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(13)->toDateString(),
                'total_posts' => 8,
            ],
            options: [
                'platforms' => ['instagram', 'linkedin'],
                'formats' => ['post', 'reel'],
            ]
        );

        $trendRows = array_values(array_filter($rows, fn (array $row) => (string) ($row['rubric'] ?? '') === 'Trend'));

        $this->assertCount(1, $trendRows);
        $this->assertSame('operator insight with practical lesson', (string) data_get($trendRows[0], 'trend_basis.topic'));
        $this->assertSame('trend_signal', (string) data_get($trendRows[0], 'trend_basis.source'));
        $this->assertSame([], array_values(array_filter($rows, function (array $row): bool {
            return (string) data_get($row, 'trend_basis.topic') === 'trend audio imitation meme';
        })));
    }

    public function test_it_clamps_period_when_end_date_precedes_start_date(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-clamp');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $start = Carbon::parse('2026-03-17');
        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: [
                'pillars' => $strategy->pillars,
                'rubrics' => $strategy->rubrics,
                'cta_rules' => $strategy->cta_rules,
                'constraints' => $strategy->constraints,
            ],
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => $start->toDateString(),
                'end' => '2026-03-01',
                'total_posts' => 3,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['post'],
            ]
        );

        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertSame($start->toDateString(), Carbon::parse((string) $row['scheduled_at'])->toDateString());
        }
    }

    public function test_it_biases_wizard_plan_with_feedback_preferences_and_guidance(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-feedback-plan');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: [
                'pillars' => $strategy->pillars,
                'rubrics' => $strategy->rubrics,
                'cta_rules' => $strategy->cta_rules,
                'constraints' => $strategy->constraints,
            ],
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(6)->toDateString(),
                'total_posts' => 3,
            ],
            options: [
                'platforms' => ['facebook', 'instagram'],
                'formats' => ['reel', 'post'],
                'memory' => [
                    'feedback_summary' => [
                        'preferred_platforms' => ['instagram'],
                        'preferred_formats' => ['post'],
                        'positive_signals' => ['Visual realistici e post molto social'],
                        'hard_avoid_rules' => ['Non stravolgere il luogo reale'],
                    ],
                ],
            ]
        );

        $this->assertCount(3, $rows);
        $this->assertSame('instagram', $rows[0]['platform']);
        $this->assertSame('post', $rows[0]['format']);
        $this->assertSame(
            ['Visual realistici e post molto social'],
            data_get($rows[0], 'feedback_guidance.positive_signals')
        );
        $this->assertSame(
            ['Non stravolgere il luogo reale'],
            data_get($rows[0], 'feedback_guidance.hard_avoid_rules')
        );
    }

    public function test_it_avoids_consulting_style_key_points_in_editorial_plan(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-social-copy');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: [
                'pillars' => $strategy->pillars,
                'rubrics' => $strategy->rubrics,
                'cta_rules' => $strategy->cta_rules,
                'constraints' => $strategy->constraints,
            ],
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(6)->toDateString(),
                'total_posts' => 3,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['post', 'reel'],
            ]
        );

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $angle = (string) ($row['content_angle'] ?? '');
            $points = implode(' ', (array) ($row['key_points'] ?? []));

            $this->assertStringNotContainsString('prima/dopo misurabile', $angle);
            $this->assertStringNotContainsString('Framework rapido', $angle);
            $this->assertStringNotContainsString('Contesto:', $points);
            $this->assertStringNotContainsString('Azione:', $points);
        }
    }

    private function bootstrapTenant(string $slug = 'tenant-hard'): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Test',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        $profile = TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Hostup',
            'industry' => 'SaaS',
            'services' => 'Lead Generation, Social Strategy, Funnel',
            'target' => 'PMI digitali',
            'cta' => 'Scrivici in DM.',
            'default_tone' => 'professionale',
        ]);

        return [$tenant, $user, $profile];
    }
}
