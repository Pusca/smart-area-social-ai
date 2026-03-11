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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageProviderEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_content_accepts_openai_for_single_content_when_requested(): void
    {
        Storage::fake('public');
        Queue::fake();

        $tenant = Tenant::create([
            'name' => 'Tenant Images',
            'slug' => 'tenant-images',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Padel',
            'industry' => 'Sport',
            'services' => 'Campi, tornei, lezioni',
            'target' => 'Giocatori amatoriali e competitivi',
            'cta' => 'Prenota ora',
            'default_tone' => 'amichevole',
        ]);

        Storage::disk('public')->put('brand-assets/' . $tenant->id . '/images/padel.jpg', 'fake-image');
        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/images/padel.jpg',
            'original_name' => 'padel.jpg',
            'size' => 10,
            'mime' => 'image/jpeg',
        ]);

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'platforms' => ['instagram', 'facebook'],
            'format' => 'post',
            'video_provider' => 'openai',
            'image_provider' => 'openai',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'generation_brief' => 'Crea un post Instagram sul circolo, usando l immagine reale e uno stile premium.',
        ]);

        $item = ContentItem::query()->latest('id')->first();

        $response->assertRedirect(route('posts.edit', $item));
        $this->assertNotNull($item);
        $this->assertSame('openai', data_get($item->ai_meta, 'image_provider'));

        Queue::assertPushed(GenerateAiForContentItem::class, 1);
    }

    public function test_manual_content_defaults_to_nanobanana_when_no_provider_is_sent(): void
    {
        Storage::fake('public');
        Queue::fake();

        $tenant = Tenant::create([
            'name' => 'Tenant Images Default',
            'slug' => 'tenant-images-default',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Studio Padel',
            'industry' => 'Sport',
            'services' => 'Campi, tornei, lezioni',
            'target' => 'Giocatori amatoriali e competitivi',
            'cta' => 'Prenota ora',
            'default_tone' => 'amichevole',
        ]);

        Storage::disk('public')->put('brand-assets/' . $tenant->id . '/images/padel.jpg', 'fake-image');
        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/images/padel.jpg',
            'original_name' => 'padel.jpg',
            'size' => 10,
            'mime' => 'image/jpeg',
        ]);

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'platforms' => ['instagram'],
            'format' => 'post',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'generation_brief' => 'Crea un post Instagram sul circolo con visual premium e taglio editoriale.',
        ]);

        $item = ContentItem::query()->latest('id')->first();

        $response->assertRedirect(route('posts.edit', $item));
        $this->assertNotNull($item);
        $this->assertSame('nanobanana', data_get($item->ai_meta, 'image_provider'));

        Queue::assertPushed(GenerateAiForContentItem::class, 1);
    }
}
