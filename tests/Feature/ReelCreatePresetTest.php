<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReelCreatePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_index_shows_dedicated_create_reel_entrypoint(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Reel',
            'slug' => 'tenant-reel',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('Crea reel')
            ->assertSee(route('posts.reels.create'), false);
    }

    public function test_dedicated_reel_page_preselects_reel_and_runway(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Reel',
            'slug' => 'tenant-reel',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)
            ->get(route('posts.reels.create'))
            ->assertOk()
            ->assertSee('Crea un reel singolo pensato per il feed')
            ->assertSee('Runway image-to-video')
            ->assertSee('Google Veo 3.1 (direct)')
            ->assertSee('Kling (coerenza persona)')
            ->assertSee('value="reel"', false)
            ->assertSee('value="runway" selected', false);
    }

    public function test_generic_create_page_shows_single_content_and_plan_without_reel_card(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Reel',
            'slug' => 'tenant-reel',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)
            ->get(route('posts.create'))
            ->assertOk()
            ->assertSee('Scegli se partire da un contenuto singolo o da un piano editoriale')
            ->assertSee('Piano editoriale')
            ->assertDontSee('Runway image-to-video');
    }
}
