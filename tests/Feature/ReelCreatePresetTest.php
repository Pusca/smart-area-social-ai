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
            ->assertSee(route('posts.create', ['preset' => 'reel']), false);
    }

    public function test_create_page_preset_reel_preselects_reel_and_runway(): void
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
            ->get(route('posts.create', ['preset' => 'reel']))
            ->assertOk()
            ->assertSee('Modalita attiva')
            ->assertSee('Runway preimpostato')
            ->assertSee('value="reel" selected', false)
            ->assertSee('value="runway" selected', false);
    }
}
