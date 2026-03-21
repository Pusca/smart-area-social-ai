<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssetIdentityFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_identity_variable_stores_anchor_locks_and_allowed_transforms(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $officeA = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/office-a.jpg',
            'original_name' => 'office-a.jpg',
            'mime' => 'image/jpeg',
        ]);
        $officeB = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/office-b.jpg',
            'original_name' => 'office-b.jpg',
            'mime' => 'image/jpeg',
        ]);

        $response = $this->actingAs($user)->post(route('profile.brand.variables.store'), [
            'name' => 'Ufficio Milano',
            'kind' => 'location',
            'asset_role' => 'office',
            'description' => 'Workspace principale del team commerciale.',
            'asset_ids' => [$officeA->id, $officeB->id],
            'canonical_asset_id' => $officeA->id,
            'identity_mode' => 'strict',
            'consistency_threshold' => 93,
            'descriptor_summary' => 'Reception chiara, logo a parete, vetrate e bancone frontale.',
            'immutable_elements' => 'layout reception, parete logo, bancone, vetrate, posizione accesso',
            'allowed_transforms' => "decorazioni natalizie\nluci piu calde\nprops promozionali",
            'prompt_notes' => 'Mantieni prospettiva reale, no collage, no cambi architetturali.',
            'usage_notes' => 'Usarlo per auguri, promo showroom e contenuti corporate.',
        ]);

        $response->assertRedirect(route('profile.brand'));

        $variable = AssetVariable::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($variable);
        $this->assertSame('location', $variable->kind);
        $this->assertSame('office', $variable->asset_role);
        $this->assertSame((int) $officeA->id, (int) $variable->canonical_asset_id);
        $this->assertSame('strict', $variable->identity_mode);
        $this->assertSame(93, (int) $variable->consistency_threshold);
        $this->assertSame('Reception chiara, logo a parete, vetrate e bancone frontale.', data_get($variable->profile, 'descriptor.summary'));
        $this->assertSame('layout reception, parete logo, bancone, vetrate, posizione accesso', data_get($variable->profile, 'prompt_lock.immutable_elements'));
        $this->assertSame(
            ['decorazioni natalizie', 'luci piu calde', 'props promozionali'],
            data_get($variable->profile, 'allowed_transforms')
        );
        $this->assertSame('location', data_get($variable->identity_pack, 'type'));
        $this->assertSame('strict', data_get($variable->identity_pack, 'strictness_level'));
        $this->assertSame('Reception chiara, logo a parete, vetrate e bancone frontale.', data_get($variable->identity_pack, 'descriptor.summary'));
        $this->assertSame(
            ['layout reception', 'parete logo', 'bancone', 'vetrate', 'posizione accesso'],
            data_get($variable->identity_pack, 'invariants')
        );
        $this->assertSame(
            ['decorazioni natalizie', 'luci piu calde', 'props promozionali'],
            data_get($variable->identity_pack, 'transformables')
        );
        $this->assertNotEmpty(data_get($variable->identity_pack, 'canonical_assets.0.path'));

        $officeA->refresh();
        $officeB->refresh();
        $this->assertTrue((bool) data_get($officeA->meta, 'is_canonical_for_identity'));
        $this->assertFalse((bool) data_get($officeB->meta, 'is_canonical_for_identity'));
        $this->assertSame((int) $variable->id, (int) data_get($officeA->meta, 'linked_variable_id'));
    }

    public function test_single_content_store_persists_presenter_product_place_identity_context(): void
    {
        Queue::fake();

        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo-tenant',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Social AI Demo',
            'industry' => 'Retail',
            'cta' => 'Scrivici per una demo',
        ]);

        $presenterAsset = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/presenter.jpg',
            'original_name' => 'presenter.jpg',
            'mime' => 'image/jpeg',
        ]);
        $productAsset = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/product.jpg',
            'original_name' => 'product.jpg',
            'mime' => 'image/jpeg',
        ]);
        $placeAsset = BrandAsset::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => null,
            'kind' => 'image',
            'path' => 'brand-assets/' . $tenant->id . '/office.jpg',
            'original_name' => 'office.jpg',
            'mime' => 'image/jpeg',
        ]);

        $presenter = AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Manuel',
            'slug' => 'manuel',
            'kind' => 'person',
            'asset_role' => 'presenter',
            'description' => 'Volto commerciale del brand',
            'asset_ids' => [$presenterAsset->id],
            'canonical_asset_id' => $presenterAsset->id,
            'identity_mode' => 'strict',
            'consistency_threshold' => 92,
            'profile' => [
                'descriptor' => ['summary' => 'Volto sorridente con camicia chiara.'],
                'prompt_lock' => ['immutable_elements' => 'volto, taglio capelli, lineamenti'],
                'allowed_transforms' => ['cambio inquadratura', 'luci piu calde'],
            ],
            'is_active' => true,
        ]);
        $product = AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Linea Premium',
            'slug' => 'linea-premium',
            'kind' => 'product',
            'asset_role' => 'hero_product',
            'description' => 'Prodotto hero della campagna',
            'asset_ids' => [$productAsset->id],
            'canonical_asset_id' => $productAsset->id,
            'identity_mode' => 'balanced',
            'consistency_threshold' => 88,
            'profile' => [
                'descriptor' => ['summary' => 'Packaging nero opaco con dettagli oro.'],
                'prompt_lock' => ['immutable_elements' => 'forma flacone, etichetta, colori packaging'],
                'allowed_transforms' => ['ambientazione premium', 'props stagionali'],
            ],
            'is_active' => true,
        ]);
        $place = AssetVariable::create([
            'tenant_id' => $tenant->id,
            'name' => 'Showroom Milano',
            'slug' => 'showroom-milano',
            'kind' => 'location',
            'asset_role' => 'office',
            'description' => 'Spazio reale del brand',
            'asset_ids' => [$placeAsset->id],
            'canonical_asset_id' => $placeAsset->id,
            'identity_mode' => 'strict',
            'consistency_threshold' => 95,
            'profile' => [
                'descriptor' => ['summary' => 'Parete logo, espositori e vetrata frontale.'],
                'prompt_lock' => ['immutable_elements' => 'parete logo, disposizione scaffali, vetrata'],
                'allowed_transforms' => ['decorazioni natalizie', 'luci piu calde'],
            ],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->post(route('posts.store'), [
                'platforms' => ['instagram'],
                'format' => 'post',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'generation_brief' => 'Crea un post di Natale con Manuel che presenta la Linea Premium nello showroom.',
                'presenter_variable_id' => $presenter->id,
                'product_variable_id' => $product->id,
                'place_variable_id' => $place->id,
                'seasonal_overlay' => 'decorazioni natalizie eleganti',
                'consistency_mode' => 'strict',
            ]);

        $item = \App\Models\ContentItem::query()->latest('id')->first();

        $response->assertRedirect(route('posts.edit', $item));
        $this->assertNotNull($item);
        $this->assertSame('strict', data_get($item->ai_meta, 'asset_identity.consistency_mode'));
        $this->assertSame('decorazioni natalizie eleganti', data_get($item->ai_meta, 'asset_identity.seasonal_overlay'));
        $this->assertSame((int) $presenter->id, (int) data_get($item->ai_meta, 'asset_identity.slots.presenter.id'));
        $this->assertSame((int) $product->id, (int) data_get($item->ai_meta, 'asset_identity.slots.product.id'));
        $this->assertSame((int) $place->id, (int) data_get($item->ai_meta, 'asset_identity.slots.place.id'));
        $this->assertSame(
            [$presenter->id, $product->id, $place->id],
            data_get($item->ai_meta, 'asset_identity.slot_ids')
        );
        $this->assertContains('decorazioni natalizie eleganti', (array) data_get($item->ai_meta, 'asset_identity.allowed_changes'));
        $this->assertSame('person', data_get($item->ai_meta, 'asset_identity.slots.presenter.identity_pack.type'));
        $this->assertSame('product', data_get($item->ai_meta, 'asset_identity.slots.product.identity_pack.type'));
        $this->assertSame('location', data_get($item->ai_meta, 'asset_identity.slots.place.identity_pack.type'));
        $this->assertNotEmpty(data_get($item->ai_meta, 'asset_identity.slots.place.canonical_assets'));
        $this->assertNotEmpty(data_get($item->ai_meta, 'asset_identity.slots.presenter.maintain_elements'));
        $this->assertContains('decorazioni natalizie', (array) data_get($item->ai_meta, 'asset_identity.slots.place.changeable_elements'));
        $this->assertTrue(collect((array) $item->source_refs)->contains(fn ($row) => data_get($row, 'type') === 'asset_identity_slot' && data_get($row, 'slot') === 'presenter'));

        Queue::assertPushed(GenerateAiForContentItem::class, 1);
    }
}

