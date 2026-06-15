<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiForContentItem;
use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandQuickstartTest extends TestCase
{
    use RefreshDatabase;

    public function test_quickstart_creates_profile_assets_variable_and_demo_plan(): void
    {
        Storage::fake('public');
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

        $response = $this->actingAs($user)->post(route('profile.brand.quickstart.store'), [
            'business_name' => 'Bottega Verde',
            'industry' => 'Erboristeria',
            'services' => 'Cosmesi naturale, consulenza beauty, idee regalo',
            'target' => 'Donne e uomini 28-55 interessati a prodotti naturali',
            'cta' => 'Scrivici su WhatsApp',
            'notes' => 'Punto vendita fisico con forte attenzione alla qualita dei prodotti.',
            'default_tone' => 'amichevole',
            'quickstart_variable_name' => 'Linea viso',
            'quickstart_variable_kind' => 'product',
            'quickstart_variable_description' => 'Prodotti skincare best seller',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
            'images' => [
                UploadedFile::fake()->image('prodotto-1.jpg', 1200, 1200),
                UploadedFile::fake()->image('prodotto-2.jpg', 1200, 1200),
            ],
        ]);

        $response->assertRedirect(route('profile.brand'));

        $profile = TenantProfile::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('Bottega Verde', $profile->business_name);
        $this->assertSame(3, $profile->default_posts_per_week);
        $this->assertSame(['instagram', 'facebook'], $profile->default_platforms);
        $this->assertSame(['post', 'reel'], $profile->default_formats);
        $this->assertNotNull($profile->onboarding_completed_at);
        $this->assertNotNull($profile->quickstart_generated_at);
        $this->assertNotNull($profile->quickstart_last_plan_id);
        $this->assertNull($profile->quickstart_dismissed_at);

        $this->assertSame(3, BrandAsset::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, AssetVariable::query()->where('tenant_id', $tenant->id)->count());

        $plan = ContentPlan::query()->find($profile->quickstart_last_plan_id);
        $this->assertNotNull($plan);
        $this->assertSame('onboarding_quickstart_demo', data_get($plan->settings, 'mode'));
        $this->assertSame(3, (int) data_get($plan->settings, 'posts_total'));
        $this->assertSame(['instagram', 'facebook'], data_get($plan->settings, 'platforms'));

        $items = ContentItem::query()
            ->where('content_plan_id', $plan->id)
            ->orderBy('scheduled_at')
            ->get();

        $this->assertCount(3, $items);
        $this->assertSame(['post', 'post', 'reel'], $items->pluck('format')->all());
        $this->assertTrue($items->every(fn (ContentItem $item) => in_array($item->platform, ['instagram', 'facebook'], true)));
        $this->assertSame(['instagram', 'instagram', 'facebook'], $items->pluck('platform')->all());
        $this->assertTrue($items->every(fn (ContentItem $item) => $item->ai_status === 'queued'));

        Queue::assertPushed(GenerateAiForContentItem::class, 3);
    }

    public function test_quickstart_can_be_saved_and_hidden_without_deleting_generated_content(): void
    {
        Storage::fake('public');
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

        $this->actingAs($user)->post(route('profile.brand.quickstart.store'), [
            'business_name' => 'Bottega Verde',
            'industry' => 'Erboristeria',
            'services' => 'Cosmesi naturale, consulenza beauty, idee regalo',
            'target' => 'Donne e uomini 28-55 interessati a prodotti naturali',
            'cta' => 'Scrivici su WhatsApp',
            'notes' => 'Punto vendita fisico con forte attenzione alla qualita dei prodotti.',
            'default_tone' => 'amichevole',
            'images' => [
                UploadedFile::fake()->image('prodotto-1.jpg', 1200, 1200),
            ],
        ]);

        $profile = TenantProfile::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $planId = (int) $profile->quickstart_last_plan_id;

        $response = $this->actingAs($user)->post(route('profile.brand.quickstart.save'));

        $response->assertRedirect(route('profile.brand'));

        $profile->refresh();
        $this->assertNull($profile->quickstart_last_plan_id);
        $this->assertNotNull($profile->quickstart_dismissed_at);

        $plan = ContentPlan::query()->find($planId);
        $this->assertNotNull($plan);
        $this->assertSame('quickstart_saved', data_get($plan->settings, 'mode'));
        $this->assertSame('onboarding_quickstart_demo', data_get($plan->settings, 'saved_from'));
        $this->assertCount(3, ContentItem::query()->where('content_plan_id', $planId)->get());

        $item = ContentItem::query()->where('content_plan_id', $planId)->firstOrFail();
        $this->assertSame('quickstart_saved_plan', data_get($item->ai_meta, 'source'));
        $this->assertNull(data_get($item->ai_meta, 'demo'));

        $this->actingAs($user)
            ->get(route('profile.brand'))
            ->assertOk()
            ->assertDontSee('Crea una prova credibile in pochi minuti')
            ->assertDontSee('Rigenera demo iniziale')
            ->assertSee('Completa il Brand Center e dai più contesto alla macchina');
    }

    public function test_quickstart_can_be_deleted_and_hidden(): void
    {
        Storage::fake('public');
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

        $this->actingAs($user)->post(route('profile.brand.quickstart.store'), [
            'business_name' => 'Bottega Verde',
            'industry' => 'Erboristeria',
            'services' => 'Cosmesi naturale, consulenza beauty, idee regalo',
            'target' => 'Donne e uomini 28-55 interessati a prodotti naturali',
            'cta' => 'Scrivici su WhatsApp',
            'notes' => 'Punto vendita fisico con forte attenzione alla qualita dei prodotti.',
            'default_tone' => 'amichevole',
            'images' => [
                UploadedFile::fake()->image('prodotto-1.jpg', 1200, 1200),
            ],
        ]);

        $profile = TenantProfile::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $planId = (int) $profile->quickstart_last_plan_id;

        $response = $this->actingAs($user)->delete(route('profile.brand.quickstart.destroy'));

        $response->assertRedirect(route('profile.brand'));

        $profile->refresh();
        $this->assertNull($profile->quickstart_last_plan_id);
        $this->assertNull($profile->quickstart_generated_at);
        $this->assertNotNull($profile->quickstart_dismissed_at);
        $this->assertNull(ContentPlan::query()->find($planId));
        $this->assertSame(0, ContentItem::query()->where('content_plan_id', $planId)->count());

        $this->actingAs($user)
            ->get(route('profile.brand'))
            ->assertOk()
            ->assertDontSee('Crea una prova credibile in pochi minuti')
            ->assertDontSee('Rigenera demo iniziale')
            ->assertSee('Completa il Brand Center e dai più contesto alla macchina');
    }
}
