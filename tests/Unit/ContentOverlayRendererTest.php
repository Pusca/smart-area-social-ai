<?php

namespace Tests\Unit;

use App\Services\Overlays\ContentOverlayFontRegistry;
use App\Services\Overlays\ContentOverlayReadabilityService;
use App\Services\Overlays\ContentOverlayRenderer;
use Tests\TestCase;

class ContentOverlayRendererTest extends TestCase
{
    public function test_it_replaces_existing_rendered_asset_of_same_type_instead_of_accumulating_duplicates(): void
    {
        $renderer = new ContentOverlayRenderer(
            new ContentOverlayFontRegistry(),
            new ContentOverlayReadabilityService()
        );

        $method = new \ReflectionMethod($renderer, 'upsertRenderedAsset');
        $method->setAccessible(true);

        $assets = [
            ['type' => 'brand_reference', 'path' => 'brand/source.jpg'],
            ['type' => 'ai_overlay_rendered', 'path' => 'ai/overlays/old.png'],
            ['type' => 'ai_overlay_rendered', 'path' => 'ai/overlays/older.png'],
        ];

        $result = $method->invoke($renderer, $assets, 'ai_overlay_rendered', 'ai/overlays/new.png');

        $this->assertSame([
            ['type' => 'brand_reference', 'path' => 'brand/source.jpg'],
            ['type' => 'ai_overlay_rendered', 'path' => 'ai/overlays/new.png'],
        ], $result);
    }
}
