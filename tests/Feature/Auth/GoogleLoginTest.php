<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $id, string $email, string $name): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getId')->andReturn($id);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getNickname')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_new_google_user_gets_tenant_and_lands_on_onboarding(): void
    {
        $this->fakeGoogleUser('g-123', 'nuova@example.com', 'Pasticceria Dolce Vita');

        $response = $this->get('/auth/google/callback');

        $user = User::where('email', 'nuova@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at); // Google garantisce l'email
        $this->assertNotNull($user->tenant_id);
        $this->assertSame('owner', $user->role);
        $this->assertSame('Pasticceria Dolce Vita', Tenant::find($user->tenant_id)->name);

        $response->assertRedirect(route('profile.brand'));
    }

    public function test_existing_email_user_is_linked_and_verified(): void
    {
        $tenant = Tenant::create(['name' => 'Esistente', 'slug' => 'esistente']);
        $existing = User::create([
            'name' => 'Esistente',
            'email' => 'esistente@example.com',
            'password' => 'password',
        ]);
        $existing->forceFill(['tenant_id' => $tenant->id])->save();

        $this->fakeGoogleUser('g-999', 'esistente@example.com', 'Esistente');

        $this->get('/auth/google/callback');

        $existing->refresh();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('g-999', $existing->google_id);
        $this->assertNotNull($existing->email_verified_at);
        // Nessun tenant duplicato
        $this->assertSame(1, Tenant::count());
    }
}
