<?php

namespace Tests\Unit;

use App\Services\AI\ContentAngleEngine;
use Tests\TestCase;

class ContentAngleEngineTest extends TestCase
{
    public function test_it_builds_an_authoritative_professional_hook_set_for_linkedin(): void
    {
        $pack = app(ContentAngleEngine::class)->build([
            'platform' => 'linkedin',
            'format' => 'carousel',
            'goal' => 'Lead',
            'audience' => 'Founder B2B e marketing manager',
            'industry' => 'SaaS',
            'topic' => 'pipeline commerciale e lead generation',
            'rubric' => 'Educativo',
            'editorial_mode' => 'authority-building',
            'item_brain' => [
                'objective' => 'Lead',
                'angle' => 'pipeline commerciale e lead generation',
            ],
        ]);

        $this->assertSame('authoritative', $pack['strategy_type']);
        $this->assertNotSame('', (string) data_get($pack, 'hook_meta.main_hook'));
        $this->assertNotSame('', (string) data_get($pack, 'hook_meta.alternative_hook'));
        $this->assertSame('consultative_soft', data_get($pack, 'hook_meta.cta_mode'));
        $this->assertStringContainsString('criterio operativo', strtolower((string) data_get($pack, 'hook_meta.platform_specific_opening_structure')));
        $this->assertCount(2, (array) $pack['authority_signals']);
        $this->assertCount(2, (array) $pack['trust_signals']);

        foreach ((array) config('content_strategy.guardrails.banned_hook_fragments', []) as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase(
                (string) $fragment,
                (string) data_get($pack, 'hook_meta.main_hook')
            );
        }
    }

    public function test_it_builds_trend_aware_video_segments_for_reels(): void
    {
        $pack = app(ContentAngleEngine::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'goal' => 'Coinvolgimento',
            'audience' => 'Brand owner e creator locali',
            'industry' => 'Retail',
            'topic' => 'trend showroom experience',
            'rubric' => 'Trend',
            'editorial_mode' => 'trend-aware',
            'trend_confidence' => 0.83,
            'trend_opportunity' => [
                'topic' => 'showroom trend format',
            ],
            'item_brain' => [
                'objective' => 'Coinvolgimento',
                'angle' => 'trend showroom experience',
            ],
        ]);

        $this->assertSame('trend-aware', $pack['strategy_type']);
        $this->assertSame('high', data_get($pack, 'viral_angle.trend_relevance'));
        $this->assertNotSame('', (string) data_get($pack, 'content_structure_meta.video_segments.hook_0_3'));
        $this->assertNotSame('', (string) data_get($pack, 'content_structure_meta.video_segments.development_3_8'));
        $this->assertNotSame('', (string) data_get($pack, 'content_structure_meta.video_segments.payoff_reveal'));
        $this->assertNotSame('', (string) data_get($pack, 'content_structure_meta.video_segments.cta_ending'));
        $this->assertStringContainsString('0-1.5s', (string) data_get($pack, 'hook_meta.platform_specific_opening_structure'));
    }

    public function test_it_applies_brand_controls_for_trend_appetite_and_hook_intensity(): void
    {
        $pack = app(ContentAngleEngine::class)->build([
            'platform' => 'instagram',
            'format' => 'reel',
            'goal' => 'Awareness',
            'audience' => 'Founder e creator locali',
            'industry' => 'Retail',
            'topic' => 'format trend showroom',
            'trend_confidence' => 0.93,
            'trend_opportunity' => [
                'topic' => 'format trend showroom',
            ],
            'tenant_profile' => [
                'overlay_preferences' => [
                    'preferred_hook_intensity' => 'low',
                    'trend_appetite' => 'low',
                    'professionalism_guardrail_level' => 'high',
                ],
            ],
        ]);

        $this->assertSame('low', data_get($pack, 'selection_context.trend_appetite'));
        $this->assertSame('low', data_get($pack, 'selection_context.preferred_hook_intensity'));
        $this->assertSame('high', data_get($pack, 'selection_context.professionalism_guardrail_level'));
        $this->assertSame('medium', data_get($pack, 'selection_context.trend_relevance'));
        $this->assertSame('low', data_get($pack, 'hook_meta.preferred_hook_intensity'));
        $this->assertSame('high', data_get($pack, 'hook_meta.professionalism_guardrail_level'));
    }
}
