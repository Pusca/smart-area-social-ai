<?php

namespace Tests\Feature;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\AssetVariableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandVariableAssetExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_person_variable_can_be_enriched_with_supporting_media(): void
    {
        Storage::fake('public');

        config([
            'generation.speech_providers' => ['openai', 'elevenlabs'],
            'generation.speech_provider_default' => 'openai',
            'generation.voice_clone_provider_default' => 'elevenlabs',
        ]);

        $tenant = Tenant::create([
            'name' => 'Pusca Media',
            'slug' => 'pusca-media',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Pusca Media',
            'industry' => 'Media',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $initialImage = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/variables/silvia-bellot/images/front.jpg',
            'original_name' => 'front.jpg',
            'mime' => 'image/jpeg',
            'meta' => [
                'source' => 'guided_persona_pack',
            ],
        ]);

        Storage::disk('public')->put($initialImage->path, 'seed-image');

        $variable = AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Silvia Bellot',
            'slug' => 'silvia-bellot',
            'kind' => 'person',
            'asset_role' => 'presenter',
            'description' => 'Voce e volto del brand per contenuti social e podcast.',
            'asset_ids' => [$initialImage->id],
            'canonical_asset_id' => $initialImage->id,
            'identity_mode' => 'strict',
            'consistency_threshold' => 92,
            'profile' => [
                'role' => 'Host e founder',
                'prompt_notes' => 'Mantieni lineamenti reali, espressione naturale e presenza credibile.',
                'shot_summary' => [
                    [
                        'slot' => 'reference_still_1',
                        'label' => 'Riferimento 1',
                        'asset_id' => $initialImage->id,
                        'path' => $initialImage->path,
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('profile.brand.variables.assets.store', $variable), [
            'variable_images' => [
                UploadedFile::fake()->image('silvia-podcast.jpg', 1200, 1600),
            ],
            'variable_videos' => [
                UploadedFile::fake()->create('silvia-studio.mp4', 4096, 'video/mp4'),
            ],
            'variable_audios' => [
                UploadedFile::fake()->create('silvia-voice.mp3', 1024, 'audio/mpeg'),
            ],
            'variable_asset_notes' => 'Silvia in studio podcast con microfono, tono piu istituzionale, camminata naturale e voce pulita.',
            'variable_asset_set_video_reference' => '1',
            'variable_asset_set_voice_reference' => '1',
        ]);

        $response->assertRedirect(route('profile.brand'));

        $variable->refresh();

        $assets = BrandAsset::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $assets);

        $newAssets = $assets->where('id', '!=', $initialImage->id)->values();
        $this->assertSame(1, $newAssets->where('kind', 'image')->count());
        $this->assertSame(1, $newAssets->where('kind', 'video')->count());
        $this->assertSame(1, $newAssets->where('kind', 'audio')->count());
        $this->assertTrue($newAssets->every(fn (BrandAsset $asset) => data_get($asset->meta, 'source') === 'brand_center_variable_extension'));
        $this->assertTrue($newAssets->every(fn (BrandAsset $asset) => (int) data_get($asset->meta, 'linked_variable_id') === (int) $variable->id));

        $imageAsset = $newAssets->firstWhere('kind', 'image');
        $videoAsset = $newAssets->firstWhere('kind', 'video');
        $audioAsset = $newAssets->firstWhere('kind', 'audio');

        $this->assertNotNull($imageAsset);
        $this->assertNotNull($videoAsset);
        $this->assertNotNull($audioAsset);

        $this->assertContains((int) $imageAsset->id, (array) $variable->asset_ids);
        $this->assertContains((int) $videoAsset->id, (array) $variable->asset_ids);
        $this->assertContains((int) $audioAsset->id, (array) $variable->asset_ids);

        $this->assertSame('supporting_reference', data_get($imageAsset->meta, 'slot'));
        $this->assertSame('reference_video', data_get($videoAsset->meta, 'slot'));
        $this->assertSame('voice_sample', data_get($audioAsset->meta, 'slot'));

        $this->assertSame((int) $videoAsset->id, (int) data_get($variable->profile, 'reference_video_asset_id'));
        $this->assertSame((string) $videoAsset->path, (string) data_get($variable->profile, 'reference_video_path'));
        $this->assertSame((int) $audioAsset->id, (int) $variable->voice_asset_id);
        $this->assertSame('elevenlabs', (string) $variable->voice_provider);
        $this->assertSame('sample_ready', (string) $variable->voice_status);
        $this->assertSame((int) $audioAsset->id, (int) data_get($variable->profile, 'voice_reference.sample_asset_id'));
        $this->assertSame((string) $audioAsset->path, (string) data_get($variable->profile, 'voice_reference.sample_path'));
        $this->assertSame('sample_ready', (string) data_get($variable->profile, 'voice_reference.status'));

        $this->assertCount(2, (array) data_get($variable->profile, 'shot_summary', []));
        $this->assertStringContainsString(
            'Mantieni lineamenti reali',
            (string) data_get($variable->profile, 'prompt_notes')
        );
        $this->assertStringContainsString(
            'Nuovi riferimenti: Silvia in studio podcast con microfono',
            (string) data_get($variable->profile, 'prompt_notes')
        );
        $this->assertStringContainsString(
            'Silvia in studio podcast con microfono',
            (string) data_get($variable->profile, 'usage_notes')
        );

        Storage::disk('public')->assertExists((string) $imageAsset->path);
        Storage::disk('public')->assertExists((string) $videoAsset->path);
        Storage::disk('public')->assertExists((string) $audioAsset->path);

        /** @var AssetVariableService $catalogService */
        $catalogService = app(AssetVariableService::class);
        $catalogRow = collect($catalogService->catalogForTenant((int) $tenant->id))
            ->firstWhere('id', (int) $variable->id);

        $this->assertIsArray($catalogRow);
        $this->assertCount(4, (array) ($catalogRow['assets'] ?? []));
        $this->assertSame((string) $audioAsset->path, (string) ($catalogRow['voice_asset_path'] ?? ''));
        $this->assertSame((string) $videoAsset->path, (string) data_get($catalogRow, 'profile.reference_video_path'));
    }

    public function test_brand_center_page_renders_variable_asset_enrichment_form(): void
    {
        $tenant = Tenant::create([
            'name' => 'Pusca Media',
            'slug' => 'pusca-media',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Pusca Media',
            'industry' => 'Media',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Silvia Bellot',
            'slug' => 'silvia-bellot',
            'kind' => 'person',
            'asset_role' => 'presenter',
            'description' => 'Voce e volto del brand',
            'asset_ids' => [],
            'profile' => [
                'role' => 'Host e founder',
            ],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('profile.brand'))
            ->assertOk()
            ->assertSee('Silvia Bellot')
            ->assertSee('Aggiungi nuovi riferimenti a questa variabile')
            ->assertSee('Immagini aggiuntive')
            ->assertSee('Video aggiuntivi')
            ->assertSee('Audio aggiuntivi');
    }
}
