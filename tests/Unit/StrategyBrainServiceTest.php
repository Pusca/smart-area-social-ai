<?php

namespace Tests\Unit;

use App\Services\StrategyBrainService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StrategyBrainServiceTest extends TestCase
{
    public function test_it_builds_a_structured_strategy(): void
    {
        $service = new StrategyBrainService();

        $strategy = $service->buildStrategy([
            'profile' => [
                'business_name' => 'Smart Area',
                'industry' => 'marketing',
                'services' => 'Consulenza social, Content production',
                'target' => 'PMI locali',
                'brand_palette' => '#111111,#22AAFF',
            ],
            'assets' => [
                ['kind' => 'logo', 'path' => 'brand-assets/1/logo/logo.png'],
                ['kind' => 'image', 'path' => 'brand-assets/1/images/ref-1.png'],
            ],
            'memory' => [
                'themes' => ['lead', 'awareness', 'case study'],
                'hashtags' => ['#socialmedia', '#marketing'],
                'ctas' => ['scrivici in dm'],
            ],
            'preferences' => [
                'goal' => 'Lead',
                'tone' => 'professionale',
                'posts_total' => 6,
                'platforms' => ['instagram', 'linkedin'],
                'formats' => ['post', 'reel'],
                'date_range' => ['2026-02-17', '2026-02-24'],
            ],
        ]);

        $this->assertIsArray($strategy['pillars'] ?? null);
        $this->assertNotEmpty($strategy['pillars'] ?? []);
        $this->assertIsArray($strategy['campaigns'] ?? null);
        $this->assertArrayHasKey('messaging_map', $strategy);
        $this->assertArrayHasKey('hashtag_strategy', $strategy);
    }

    public function test_it_builds_blueprints_for_requested_posts(): void
    {
        $service = new StrategyBrainService();

        $strategy = $service->buildStrategy([
            'profile' => ['business_name' => 'Smart Area'],
            'assets' => [],
            'memory' => [],
            'preferences' => [
                'goal' => 'Awareness',
                'tone' => 'amichevole',
                'posts_total' => 4,
                'platforms' => ['instagram'],
                'formats' => ['post'],
                'date_range' => ['2026-02-17', '2026-02-23'],
            ],
        ]);

        $blueprints = $service->buildItemBlueprints(
            $strategy,
            [
                'platforms' => ['instagram'],
                'formats' => ['post'],
            ],
            Carbon::parse('2026-02-17'),
            Carbon::parse('2026-02-23'),
            4
        );

        $this->assertCount(4, $blueprints);
        $this->assertArrayHasKey('pillar', $blueprints[0]);
        $this->assertArrayHasKey('key_points', $blueprints[0]);
        $this->assertArrayHasKey('avoid_list', $blueprints[0]);
    }
}
