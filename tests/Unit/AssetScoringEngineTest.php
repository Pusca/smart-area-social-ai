<?php

namespace Tests\Unit;

use App\Services\Assets\AssetScoringEngine;
use Tests\TestCase;

class AssetScoringEngineTest extends TestCase
{
    public function test_it_prioritizes_primary_person_canonical_asset(): void
    {
        $result = $this->score([
            $this->personRow('strict'),
        ], [
            'format' => 'post',
            'strict_asset_mode' => true,
            'provider_matrix' => [
                'video' => ['provider' => 'runway'],
                'image' => ['provider' => 'nanobanana'],
            ],
        ]);

        $this->assertSame('brand-assets/people/silvia-front.jpg', data_get($result, 'primary_asset.path'));
        $this->assertSame('selected_primary', data_get($result, 'primary_asset.selection_status'));
        $this->assertNotEmpty($result['supporting_assets']);
        $this->assertGreaterThan(0.70, (float) $result['identity_confidence']);
    }

    public function test_it_prefers_wide_location_reference_for_location_identity(): void
    {
        $result = $this->score([
            $this->locationRow(),
        ], [
            'format' => 'post',
            'provider_matrix' => [
                'image' => ['provider' => 'nanobanana'],
                'video' => ['provider' => 'runway'],
            ],
        ]);

        $this->assertSame('brand-assets/locations/showroom-wide.jpg', data_get($result, 'primary_asset.path'));
        $this->assertContains(
            'location_envelope_fit',
            (array) data_get($result, 'primary_asset.why_selected')
        );
    }

    public function test_it_prefers_product_packshot_over_lifestyle_reference(): void
    {
        $result = $this->score([
            $this->productRow(),
        ], [
            'format' => 'post',
            'provider_matrix' => [
                'image' => ['provider' => 'openai'],
                'video' => ['provider' => 'runway'],
            ],
        ]);

        $this->assertSame('brand-assets/products/premium-packshot-front.jpg', data_get($result, 'primary_asset.path'));
        $this->assertContains(
            'product_packaging_fit',
            (array) data_get($result, 'primary_asset.why_selected')
        );
    }

    public function test_it_respects_strictness_level_when_promoting_supporting_assets(): void
    {
        $balancedRow = $this->personRow('balanced');
        $balancedRow['assets'][1]['meta']['quality_score'] = 0.52;
        $balancedRow['assets'][1]['meta']['source'] = 'supporting_reference';

        $strictRow = $this->personRow('strict');
        $strictRow['assets'][1]['meta']['quality_score'] = 0.52;
        $strictRow['assets'][1]['meta']['source'] = 'supporting_reference';

        $balancedRow['assets'][] = [
            'id' => 13,
            'kind' => 'image',
            'path' => 'brand-assets/people/silvia-podcast.jpg',
            'original_name' => 'silvia-podcast.jpg',
            'created_at' => '2026-03-16 10:00:00',
            'meta' => [
                'source' => 'supporting_reference',
                'quality_score' => 0.35,
                'linked_variable_id' => 101,
                'identity_kind' => 'person',
                'training_priority' => 'supporting',
            ],
        ];

        $strictRow['assets'][] = [
            'id' => 13,
            'kind' => 'image',
            'path' => 'brand-assets/people/silvia-podcast.jpg',
            'original_name' => 'silvia-podcast.jpg',
            'created_at' => '2026-03-16 10:00:00',
            'meta' => [
                'source' => 'supporting_reference',
                'quality_score' => 0.35,
                'linked_variable_id' => 101,
                'identity_kind' => 'person',
                'training_priority' => 'supporting',
            ],
        ];

        $balanced = $this->score([
            $balancedRow,
        ], [
            'format' => 'post',
            'strict_asset_mode' => false,
            'provider_matrix' => [
                'video' => ['provider' => 'runway'],
                'image' => ['provider' => 'openai'],
            ],
        ]);

        $strict = $this->score([
            $strictRow,
        ], [
            'format' => 'post',
            'strict_asset_mode' => true,
            'provider_matrix' => [
                'video' => ['provider' => 'runway'],
                'image' => ['provider' => 'openai'],
            ],
        ]);

        $this->assertGreaterThan(
            count((array) data_get($strict, 'supporting_assets', [])),
            count((array) data_get($balanced, 'supporting_assets', []))
        );
        $this->assertContains(
            'strict_mode_demoted_secondary_reference',
            (array) data_get($strict, 'fallback_assets.0.why_excluded', [])
        );
    }

    public function test_it_applies_provider_specific_selection_for_openai_video_vs_runway(): void
    {
        $openAi = $this->score([
            $this->personRow('strict'),
        ], [
            'format' => 'reel',
            'strict_asset_mode' => true,
            'provider_matrix' => [
                'video' => ['provider' => 'openai'],
                'image' => ['provider' => 'nanobanana'],
            ],
        ]);

        $runway = $this->score([
            $this->personRow('strict'),
        ], [
            'format' => 'reel',
            'strict_asset_mode' => true,
            'provider_matrix' => [
                'video' => ['provider' => 'runway'],
                'image' => ['provider' => 'nanobanana'],
            ],
        ]);

        $this->assertCount(1, (array) data_get($openAi, 'reference_paths', []));
        $this->assertGreaterThanOrEqual(2, count((array) data_get($runway, 'reference_paths', [])));
        $this->assertContains(
            'provider_prefers_primary_person_anchor',
            (array) data_get($openAi, 'fallback_assets.0.why_excluded', [])
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolved
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $assetIdentity
     * @return array<string, mixed>
     */
    private function score(array $resolved, array $context = [], array $assetIdentity = []): array
    {
        /** @var AssetScoringEngine $engine */
        $engine = app(AssetScoringEngine::class);

        return $engine->score(
            ['resolved' => $resolved],
            $assetIdentity,
            array_merge([
                'tenant_id' => 0,
                'content_item_id' => 0,
                'format' => 'post',
                'platform' => 'instagram',
                'strict_asset_mode' => false,
                'provider_matrix' => [
                    'image' => ['provider' => 'nanobanana'],
                    'video' => ['provider' => 'runway'],
                ],
            ], $context)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(string $strictness): array
    {
        return [
            'id' => 101,
            'name' => 'Silvia Bellot',
            'slug' => 'silvia-bellot',
            'kind' => 'person',
            'asset_role' => 'presenter',
            'identity_mode' => $strictness,
            'consistency_threshold' => 92,
            'profile' => [
                'shot_summary' => [
                    ['asset_id' => 11, 'path' => 'brand-assets/people/silvia-front.jpg', 'slot' => 'front', 'label' => 'Front portrait'],
                    ['asset_id' => 12, 'path' => 'brand-assets/people/silvia-three-quarter.jpg', 'slot' => 'three_quarter', 'label' => 'Three quarter'],
                ],
            ],
            'identity_pack' => [
                'type' => 'person',
                'strictness_level' => $strictness,
                'canonical_assets' => [
                    ['asset_id' => 11, 'path' => 'brand-assets/people/silvia-front.jpg', 'is_primary' => true],
                    ['asset_id' => 12, 'path' => 'brand-assets/people/silvia-three-quarter.jpg', 'is_primary' => false],
                ],
            ],
            'assets' => [
                [
                    'id' => 11,
                    'kind' => 'image',
                    'path' => 'brand-assets/people/silvia-front.jpg',
                    'original_name' => 'silvia-front.jpg',
                    'created_at' => '2026-03-20 10:00:00',
                    'meta' => [
                        'source' => 'guided_persona_pack',
                        'quality_score' => 0.94,
                        'linked_variable_id' => 101,
                        'identity_kind' => 'person',
                    ],
                ],
                [
                    'id' => 12,
                    'kind' => 'image',
                    'path' => 'brand-assets/people/silvia-three-quarter.jpg',
                    'original_name' => 'silvia-three-quarter.jpg',
                    'created_at' => '2026-03-18 10:00:00',
                    'meta' => [
                        'source' => 'brand_center_variable_extension',
                        'quality_score' => 0.82,
                        'linked_variable_id' => 101,
                        'identity_kind' => 'person',
                        'training_priority' => 'supporting',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationRow(): array
    {
        return [
            'id' => 201,
            'name' => 'Showroom Milano',
            'slug' => 'showroom-milano',
            'kind' => 'location',
            'asset_role' => 'office',
            'identity_mode' => 'strict',
            'consistency_threshold' => 95,
            'profile' => [
                'shot_summary' => [
                    ['asset_id' => 21, 'path' => 'brand-assets/locations/showroom-wide.jpg', 'slot' => 'wide', 'label' => 'Wide showroom'],
                    ['asset_id' => 22, 'path' => 'brand-assets/locations/showroom-detail.jpg', 'slot' => 'detail', 'label' => 'Detail corner'],
                ],
            ],
            'identity_pack' => [
                'type' => 'location',
                'strictness_level' => 'strict',
                'canonical_assets' => [
                    ['asset_id' => 21, 'path' => 'brand-assets/locations/showroom-wide.jpg', 'is_primary' => true],
                ],
            ],
            'assets' => [
                [
                    'id' => 21,
                    'kind' => 'image',
                    'path' => 'brand-assets/locations/showroom-wide.jpg',
                    'original_name' => 'showroom-wide.jpg',
                    'created_at' => '2026-03-12 10:00:00',
                    'meta' => [
                        'quality_score' => 0.88,
                        'linked_variable_id' => 201,
                        'identity_kind' => 'location',
                    ],
                ],
                [
                    'id' => 22,
                    'kind' => 'image',
                    'path' => 'brand-assets/locations/showroom-detail.jpg',
                    'original_name' => 'showroom-detail.jpg',
                    'created_at' => '2026-03-13 10:00:00',
                    'meta' => [
                        'quality_score' => 0.81,
                        'linked_variable_id' => 201,
                        'identity_kind' => 'location',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(): array
    {
        return [
            'id' => 301,
            'name' => 'Linea Premium',
            'slug' => 'linea-premium',
            'kind' => 'product',
            'asset_role' => 'hero_product',
            'identity_mode' => 'balanced',
            'consistency_threshold' => 90,
            'profile' => [
                'shot_summary' => [
                    ['asset_id' => 31, 'path' => 'brand-assets/products/premium-packshot-front.jpg', 'slot' => 'packshot_front', 'label' => 'Packshot front'],
                    ['asset_id' => 32, 'path' => 'brand-assets/products/premium-lifestyle.jpg', 'slot' => 'lifestyle', 'label' => 'Lifestyle setup'],
                ],
            ],
            'identity_pack' => [
                'type' => 'product',
                'strictness_level' => 'balanced',
                'canonical_assets' => [
                    ['asset_id' => 31, 'path' => 'brand-assets/products/premium-packshot-front.jpg', 'is_primary' => true],
                ],
            ],
            'assets' => [
                [
                    'id' => 31,
                    'kind' => 'image',
                    'path' => 'brand-assets/products/premium-packshot-front.jpg',
                    'original_name' => 'premium-packshot-front.jpg',
                    'created_at' => '2026-03-21 10:00:00',
                    'meta' => [
                        'quality_score' => 0.96,
                        'linked_variable_id' => 301,
                        'identity_kind' => 'product',
                    ],
                ],
                [
                    'id' => 32,
                    'kind' => 'image',
                    'path' => 'brand-assets/products/premium-lifestyle.jpg',
                    'original_name' => 'premium-lifestyle.jpg',
                    'created_at' => '2026-03-19 10:00:00',
                    'meta' => [
                        'quality_score' => 0.87,
                        'linked_variable_id' => 301,
                        'identity_kind' => 'product',
                    ],
                ],
            ],
        ];
    }
}






