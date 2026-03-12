<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_platform_admin_can_access_admin_dashboard(): void
    {
        config()->set('platform_admin.authorized_emails', ['puscastanislav0@gmail.com']);

        $tenant = Tenant::create([
            'name' => 'Tenant Admin',
            'slug' => 'tenant-admin',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $admin = User::factory()->create([
            'email' => 'puscastanislav0@gmail.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Controllo account e tenant')
            ->assertSee('tenant-admin');
    }

    public function test_non_authorized_admin_email_gets_forbidden_on_admin_dashboard(): void
    {
        config()->set('platform_admin.authorized_emails', ['puscastanislav0@gmail.com']);

        $admin = User::factory()->create([
            'email' => 'altro-admin@example.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_platform_admin_ensure_command_creates_expected_admin_account(): void
    {
        config()->set('platform_admin.default_email', 'puscastanislav0@gmail.com');

        Artisan::call('platform-admin:ensure', [
            '--password' => 'Qwerty12345@00',
            '--name' => 'Stanislav Admin',
        ]);

        $user = User::query()->where('email', 'puscastanislav0@gmail.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Stanislav Admin', $user->name);
        $this->assertSame('super_admin', $user->role);
        $this->assertNull($user->tenant_id);
        $this->assertTrue(Hash::check('Qwerty12345@00', (string) $user->password));
    }
}
