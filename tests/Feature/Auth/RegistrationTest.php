<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        // L'onboarding parte dal profilo attività (sito web → AI)
        $response->assertRedirect(route('profile.brand', absolute: false));
    }

    public function test_registration_creates_tenant_and_grants_access(): void
    {
        $this->post('/register', [
            'name' => 'Pizzeria Da Mario',
            'email' => 'mario@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'mario@example.com')->firstOrFail();

        $this->assertNotNull($user->tenant_id);
        $this->assertSame('owner', $user->role);
        $this->assertSame('Pizzeria Da Mario', Tenant::find($user->tenant_id)->name);

        // Senza tenant il middleware hasTenant risponderebbe 403
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_two_registrations_with_same_name_get_distinct_slugs(): void
    {
        foreach (['a@example.com', 'b@example.com'] as $email) {
            $this->post('/logout');
            $this->post('/register', [
                'name' => 'Studio Rossi',
                'email' => $email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
        }

        $this->assertSame(2, Tenant::where('name', 'Studio Rossi')->count());
        $this->assertSame(2, Tenant::where('name', 'Studio Rossi')->distinct()->count('slug'));
    }
}
