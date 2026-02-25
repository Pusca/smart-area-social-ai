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
