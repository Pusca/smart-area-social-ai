<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentOverlayFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_content_store_persists_overlay_settings_and_overlay_meta(): void
    {
        Queue::fake();

        $tenant = Tenant::create([
            'name' => 'Overlay Tenant',
            'slug' => 'overlay-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Overlay Studio',
            'industry' => 'Consulting',
            'cta' => 'Prenota una call',
            'target' => 'Founder e marketing manager',
            'overlay_preferences' => [
                'tone_preset' => 'modern',
                'font_family' => 'bahnschrift',
                'fallback_font_family' => 'arial',
                'preset' => 'modern_split_caption',
                'safe_area' => 'upper_third',
                'auto_enabled' => true,
            ],
        ]);

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/hero.jpg',
            'original_name' => 'hero.jpg',
            'mime' => 'image/jpeg',
        ]);

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'platforms' => ['instagram'],
            'format' => 'reel',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'generation_brief' => 'Crea un reel autorevole che spiega il criterio giusto per leggere la pipeline.',
            'overlay_mode' => 'manual',
            'overlay_preset' => 'bold_hook_banner',
            'overlay_text' => 'Pipeline: conta il criterio',
            'overlay_secondary_text' => 'Metodo usato sul campo',
            'overlay_font_family' => 'impact',
            'overlay_font_fallback' => 'arial',
            'overlay_font_weight' => '800',
            'overlay_font_size_mode' => 'xl',
            'overlay_text_case' => 'uppercase',
            'overlay_alignment' => 'center',
            'overlay_position' => 'upper_center',
            'overlay_safe_area' => 'upper_third',
            'overlay_max_lines' => 2,
            'overlay_color' => '#FFFFFF',
            'overlay_stroke_color' => '#111827',
            'overlay_shadow' => '1',
            'overlay_background_style' => 'dark_box',
            'overlay_animation_style' => 'pop',
            'overlay_timing_start_ms' => 0,
            'overlay_timing_end_ms' => 3000,
            'overlay_emphasis_words' => 'Pipeline, criterio',
        ]);

        $item = ContentItem::query()->latest('id')->first();

        $response->assertStatus(302);
        $this->assertNotNull($item);
        $this->assertSame('manual', data_get($item->ai_meta, 'overlay_settings.mode'));
        $this->assertSame('bold_hook_banner', data_get($item->ai_meta, 'overlay_settings.preset'));
        $this->assertSame('Pipeline: conta il criterio', data_get($item->ai_meta, 'overlay_settings.manual_override.text'));
        $this->assertSame('impact', data_get($item->ai_meta, 'overlay_meta.templates.0.font_family'));
        $this->assertSame('upper_third', data_get($item->ai_meta, 'overlay_meta.templates.0.safe_area'));
        $this->assertNotSame('', (string) data_get($item->ai_meta, 'item_brain.overlay_brief'));

        Queue::assertPushed(GenerateAiForContentItem::class, 1);
    }
}
