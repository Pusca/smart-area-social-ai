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

class ContentOverlayQualityScorecardTest extends TestCase
{
    use RefreshDatabase;

    public function test_scorecard_exposes_overlay_readability_review_and_overlay_warnings(): void
    {
        $tenant = Tenant::create([
            'name' => 'Overlay Quality',
            'slug' => 'overlay-quality',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Overlay Quality',
            'cta' => 'Scrivici',
            'default_tone' => 'professionale',
        ]);

        $plan = ContentPlan::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Overlay Quality Plan',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);

        $item = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addDay(),
            'status' => 'draft',
            'title' => 'Overlay quality item',
            'ai_status' => 'done',
            'ai_caption' => 'Caption coerente e professionale.',
            'ai_cta' => 'Scrivici',
            'ai_image_path' => 'ai/2026/03/overlay-quality.png',
            'ai_meta' => [
                'text_alignment_review' => [
                    'overall_score' => 0.82,
                    'heuristic' => [
                        'cta_score' => 0.88,
                        'hard_rule_violations' => [],
                    ],
                    'llm' => [
                        'brand_alignment_score' => 0.84,
                        'brief_alignment_score' => 0.8,
                        'issues' => [],
                    ],
                ],
                'overlay_meta' => [
                    'mode' => 'auto',
                    'templates' => [
                        [
                            'role' => 'primary_hook',
                            'text' => 'Metodo prima del rumore',
                            'secondary_text' => 'Più leggibilità, meno caos',
                            'font_family' => 'bahnschrift',
                            'font_weight' => '700',
                            'font_size_mode' => 'medium',
                            'text_case' => 'sentence',
                            'alignment' => 'center',
                            'position' => 'center',
                            'safe_area' => 'center_safe',
                            'max_lines' => 3,
                            'color' => '#FFFFFF',
                            'stroke_color' => '#111827',
                            'shadow' => true,
                            'background_style' => 'none',
                            'animation_style' => 'fade',
                            'timing_start_ms' => 0,
                            'timing_end_ms' => 0,
                            'emphasis_words' => ['Metodo'],
                        ],
                    ],
                    'readability' => [
                        'contrast_score' => 0.55,
                        'safe_area_score' => 0.69,
                        'overlap_risk' => 0.52,
                        'mobile_readability' => 0.61,
                        'overall_score' => 0.58,
                        'warnings' => ['Contrasto overlay sotto soglia: serve box o colore piu leggibile.'],
                    ],
                    'rendering' => [
                        'status' => 'planned',
                        'applied' => false,
                    ],
                ],
            ],
        ]);

        $run = GenerationRun::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'run_key' => 'overlay-quality-run',
            'scope' => 'content_item',
            'trigger_source' => 'job',
            'status' => 'succeeded',
            'format' => 'post',
            'platform' => 'instagram',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'completed_at' => now(),
        ]);

        $scorecard = app(GenerationQualityScorecardService::class)->buildForContentItem($item, $run);

        $this->assertEquals(0.58, data_get($scorecard, 'overlay_readability_score'));
        $this->assertEquals(0.61, data_get($scorecard, 'mobile_legibility_score'));
        $this->assertTrue((bool) data_get($scorecard, 'overlay_review.enabled'));
        $this->assertSame(0.55, data_get($scorecard, 'overlay_review.contrast_score'));
        $this->assertStringContainsString('Overlay contrast score basso', implode(' ', (array) $scorecard['warnings']));
        $this->assertStringContainsString('Overlay overlap risk alto', implode(' ', (array) $scorecard['warnings']));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.overlay_review.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.overlay_readability_score.mode'));
        $this->assertSame('validated', data_get($scorecard, 'score_sources.mobile_legibility_score.mode'));
    }
}
