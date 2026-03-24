<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\Editorial\ContentGenerator;
use App\Services\Editorial\EditorialPlanBuilder;
use App\Services\Editorial\EditorialStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreativeDirectionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_creative_direction_on_editorial_strategy(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-creative-direction');

        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);

        $this->assertIsArray($strategy->creative_direction);
        $this->assertSame('creative_direction_v1', data_get($strategy->creative_direction, 'version'));
        $this->assertSame('rendered_overlay_system', data_get($strategy->creative_direction, 'typography_system.overlay_mode'));
        $this->assertNotEmpty((array) data_get($strategy->creative_direction, 'trend_policy.allowed_mechanics', []));
        $this->assertSame('content_angle_engine_v1', data_get($strategy->creative_direction, 'content_strategy.version'));
        $this->assertNotEmpty((array) data_get($strategy->creative_direction, 'content_strategy.hook_policy.disallowed_fragments', []));
    }

    public function test_it_builds_plan_items_with_overlay_trend_and_continuity_briefs(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-creative-plan');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $builder = app(EditorialPlanBuilder::class);

        $rows = $builder->buildPlan(
            tenantId: (int) $tenant->id,
            strategy: [
                'pillars' => $strategy->pillars,
                'rubrics' => $strategy->rubrics,
                'cta_rules' => $strategy->cta_rules,
                'constraints' => $strategy->constraints,
                'creative_direction' => $strategy->creative_direction,
                'brand_references' => [
                    'asset_variables' => [[
                        'id' => 11,
                        'name' => 'Showroom',
                        'slug' => 'showroom',
                        'kind' => 'location',
                        'asset_paths' => ['brand-assets/7/showroom.jpg'],
                    ]],
                ],
            ],
            history: ['promo_recent_ratio' => 0.0, 'last_pillars' => []],
            period: [
                'start' => now()->toDateString(),
                'end' => now()->addDays(6)->toDateString(),
                'total_posts' => 3,
            ],
            options: [
                'platforms' => ['instagram'],
                'formats' => ['reel'],
            ]
        );

        $this->assertCount(3, $rows);
        $this->assertNotSame('', (string) data_get($rows[0], 'overlay_brief'));
        $this->assertNotSame('', (string) data_get($rows[0], 'viral_hook_style'));
        $this->assertStringContainsString('safe area', (string) data_get($rows[0], 'overlay_brief'));
        $this->assertStringContainsString('Showroom', (string) data_get($rows[0], 'continuity_brief'));
        $this->assertStringContainsString('overlay-ready', (string) data_get($rows[0], 'image_direction'));
    }

    public function test_it_persists_creative_direction_fields_inside_item_brain(): void
    {
        [$tenant, $user, $profile] = $this->bootstrapTenant('tenant-creative-generator');
        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile);
        $generator = app(ContentGenerator::class);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Creative Direction Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $created = $generator->generateForPlan($plan, [[
            'platform' => 'instagram',
            'format' => 'reel',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'rubric' => 'Trend',
            'series_key' => null,
            'episode_number' => null,
            'pillar' => 'Showroom Experience',
            'content_angle' => 'Trend applicato allo showroom',
            'primary_cta' => 'Scrivici in DM.',
            'title_hint' => 'Trend: Showroom Experience',
            'source_refs' => [],
            'objective' => 'Awareness',
            'key_points' => [],
            'image_direction' => 'Visual pulito overlay-ready.',
            'keywords' => 'trend showroom',
            'professional_brief' => 'Taglio editoriale premium.',
            'viral_hook_style' => 'Hook forte nel primo secondo.',
            'shareability_driver' => 'Takeaway salvabile.',
            'trend_bridge' => 'Usa il trend come struttura, non come meme.',
            'trend_guardrails' => ['Niente meme scollegati'],
            'overlay_brief' => 'safe area upper third, max 5 parole.',
            'continuity_brief' => 'Ancore principali: Showroom.',
        ]], [
            'user_id' => $user->id,
            'profile_data' => [],
            'strategy' => $strategy->toArray(),
            'memory' => [],
            'assets' => [],
        ]);

        $this->assertCount(1, $created);
        $meta = is_array($created[0]->ai_meta) ? $created[0]->ai_meta : [];
        $this->assertSame('Hook forte nel primo secondo.', data_get($meta, 'item_brain.viral_hook_style'));
        $this->assertStringContainsString('safe area', (string) data_get($meta, 'item_brain.overlay_brief'));
        $this->assertSame('Usa il trend come struttura, non come meme.', data_get($meta, 'item_brain.trend_bridge'));
        $this->assertIsArray(data_get($meta, 'strategy.creative_direction'));
        $this->assertIsArray(data_get($meta, 'overlay_meta'));
        $this->assertNotSame('', (string) data_get($meta, 'hook_meta.main_hook'));
        $this->assertIsArray(data_get($meta, 'authority_signals'));
        $this->assertIsArray(data_get($meta, 'trust_signals'));
        $this->assertIsArray(data_get($meta, 'viral_angle'));
        $this->assertIsArray(data_get($meta, 'content_structure_meta'));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.content_strategy_type'));
        $this->assertNotSame('', (string) data_get($meta, 'item_brain.narrative_angle'));
        $this->assertIsArray(data_get($meta, 'item_brain.content_structure_meta.video_segments'));
    }

    public function test_it_persists_brand_overlay_and_trend_controls_inside_creative_direction(): void
    {
        [$tenant, , $profile] = $this->bootstrapTenant('tenant-creative-controls');
        $profile->overlay_preferences = [
            'font_preset' => 'editorial',
            'font_family' => 'georgia',
            'preset' => 'editorial_quote_card',
            'preferred_hook_intensity' => 'medium',
            'trend_appetite' => 'low',
            'professionalism_guardrail_level' => 'high',
            'auto_enabled' => true,
            'safe_area' => 'upper_third',
        ];
        $profile->save();

        $strategy = app(EditorialStrategyService::class)->refreshForTenant((int) $tenant->id, $profile, force: true);

        $this->assertSame('editorial', data_get($strategy->creative_direction, 'typography_system.font_preset'));
        $this->assertSame('medium', data_get($strategy->creative_direction, 'content_strategy.hook_policy.preferred_intensity'));
        $this->assertSame('low', data_get($strategy->creative_direction, 'trend_policy.appetite'));
        $this->assertSame('high', data_get($strategy->creative_direction, 'content_strategy.professionalism_guardrail_level'));
    }

    private function bootstrapTenant(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Creative',
            'slug' => $slug,
        ]);

        $user = User::factory()->create();
        $user->tenant_id = $tenant->id;
        $user->save();

        $profile = TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Social AI Studio',
            'industry' => 'Hospitality',
            'services' => 'Social strategy, reel production, showroom events',
            'target' => 'Brand owner e marketing manager',
            'cta' => 'Scrivici in DM.',
            'default_tone' => 'professionale',
            'default_posts_per_week' => 5,
        ]);

        return [$tenant, $user, $profile];
    }
}
