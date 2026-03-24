<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use Tests\TestCase;

class GenerateAiForContentItemAssetSelectionTest extends TestCase
{
    public function test_it_includes_asset_selection_summary_in_visual_attempt_payloads(): void
    {
        $item = new ContentItem([
            'format' => 'post',
            'platform' => 'instagram',
            'ai_image_path' => 'ai/generated.png',
            'ai_meta' => [
                'image_provider' => 'nanobanana',
                'image_generation' => [
                    'provider' => 'nanobanana',
                    'source' => 'brand_image_edit',
                    'brand_source_paths' => ['brand-assets/front.jpg'],
                ],
                'asset_scoring' => [
                    'version' => 'asset_scoring_engine_v1',
                    'selection_area' => 'image',
                    'provider' => 'nanobanana',
                    'identity_confidence' => 0.83,
                    'primary_asset' => [
                        'path' => 'brand-assets/front.jpg',
                        'why_selected' => ['primary_canonical_anchor'],
                    ],
                    'asset_ranking' => [
                        ['path' => 'brand-assets/front.jpg', 'score' => 0.91],
                        ['path' => 'brand-assets/support.jpg', 'score' => 0.74],
                    ],
                    'selection_summary' => [
                        'selected_count' => 2,
                    ],
                ],
            ],
        ]);

        $job = new GenerateAiForContentItem(1);
        $summary = $job->buildVisualAttemptOutputSummary($item);
        $references = $job->buildVisualAttemptOutputReferences($item);

        $this->assertEquals(0.83, data_get($summary, 'asset_selection.identity_confidence'));
        $this->assertSame('brand-assets/front.jpg', data_get($summary, 'asset_selection.primary_asset.path'));
        $this->assertCount(2, (array) data_get($references, 'asset_selection_ranking', []));
    }

    public function test_it_prefers_precomputed_asset_scoring_paths_before_generic_identity_pack_logic(): void
    {
        $job = new GenerateAiForContentItem(1);

        $selected = $job->applyIdentityPackReferenceSelection(
            ['generic.jpg', 'support.jpg', 'primary.jpg'],
            [
                'resolved' => [
                    [
                        'kind' => 'person',
                        'canonical_asset_path' => 'generic.jpg',
                        'identity_pack' => [
                            'canonical_assets' => [
                                ['path' => 'generic.jpg', 'is_primary' => true],
                            ],
                        ],
                    ],
                ],
            ],
            [],
            true,
            [
                'reference_paths' => ['primary.jpg', 'support.jpg'],
                'fallback_paths' => ['generic.jpg'],
            ]
        );

        $this->assertSame(['primary.jpg', 'support.jpg'], $selected);
    }
}
