<?php

namespace Tests\Feature;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetVariableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuidedPersonaPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_guided_persona_pack_creates_variable_with_structured_profile_and_assets(): void
    {
        Storage::fake('public');

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

        $response = $this->actingAs($user)->post(route('profile.brand.variables.persona.store'), [
            'name' => 'Chef Erika',
            'description' => 'Volto del ristorante per contenuti accoglienti ed eleganti.',
            'persona_role' => 'Chef e volto del brand',
            'immutable_traits' => 'Taglio capelli corto, sorriso naturale, lineamenti reali, età apparente coerente',
            'look_notes' => 'Presenza elegante e sicura, sguardo diretto ma caldo.',
            'styling_notes' => 'Divisa pulita o outfit smart casual coerente col locale.',
            'prompt_notes' => 'Evita close-up artificiali e mantieni pelle e mani credibili.',
            'usage_notes' => 'Usarla per dietro le quinte, piatti signature, accoglienza e reel soft selling.',
            'shot_front' => UploadedFile::fake()->image('front.jpg', 1200, 1200),
            'shot_three_quarter_left' => UploadedFile::fake()->image('three-quarter-left.jpg', 1200, 1200),
            'shot_three_quarter_right' => UploadedFile::fake()->image('three-quarter-right.jpg', 1200, 1200),
            'shot_profile' => UploadedFile::fake()->image('profile.jpg', 1200, 1200),
            'shot_half_body' => UploadedFile::fake()->image('half-body.jpg', 1200, 1600),
            'reference_video' => UploadedFile::fake()->create('chef-erika.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertRedirect(route('profile.brand'));

        $variable = AssetVariable::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($variable);
        $this->assertSame('person', $variable->kind);
        $this->assertSame('Chef Erika', $variable->name);
        $this->assertIsArray($variable->profile);
        $this->assertSame('guided_persona_pack', data_get($variable->profile, 'source_mode'));
        $this->assertSame('Chef e volto del brand', data_get($variable->profile, 'role'));
        $this->assertSame(5, (int) data_get($variable->profile, 'shot_count'));
        $this->assertNotEmpty(data_get($variable->profile, 'reference_video_path'));

        $assets = BrandAsset::query()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $this->assertCount(6, $assets);
        $this->assertSame(5, $assets->where('kind', 'image')->count());
        $this->assertSame(1, $assets->where('kind', 'video')->count());
        $this->assertTrue($assets->every(fn (BrandAsset $asset) => data_get($asset->meta, 'source') === 'guided_persona_pack'));
        $this->assertTrue($assets->every(fn (BrandAsset $asset) => (int) data_get($asset->meta, 'linked_variable_id') === (int) $variable->id));

        /** @var AssetVariableService $catalogService */
        $catalogService = app(AssetVariableService::class);
        $catalog = $catalogService->catalogForTenant((int) $tenant->id);
        $this->assertCount(1, $catalog);
        $this->assertSame('Chef Erika', $catalog[0]['name']);
        $this->assertSame('Chef e volto del brand', data_get($catalog[0], 'profile.role'));
        $this->assertCount(6, (array) ($catalog[0]['assets'] ?? []));
    }
}
