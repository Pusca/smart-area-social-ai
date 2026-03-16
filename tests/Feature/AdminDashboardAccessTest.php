<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::flushSchemaSupportCache();
    }

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

    public function test_platform_admin_dashboard_still_loads_when_limits_column_is_missing(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('limits');
        });

        Tenant::flushSchemaSupportCache();

        $tenant = Tenant::create([
            'name' => 'Tenant Senza Limits',
            'slug' => 'tenant-senza-limits',
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
            ->assertSee('Controllo account e tenant');

        Tenant::flushSchemaSupportCache();
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

    public function test_platform_admin_can_impersonate_a_tenant_user_and_return_to_admin(): void
    {
        $tenant = Tenant::create([
            'name' => 'Workspace Demo',
            'slug' => 'workspace-demo',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $targetUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $admin = User::factory()->create([
            'email' => 'puscastanislav0@gmail.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.impersonate', $tenant))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($targetUser);

        $this->post(route('admin.impersonation.stop'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_platform_admin_can_update_tenant_limits_and_activation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Workspace Demo',
            'slug' => 'workspace-demo',
            'plan' => 'trial',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'email' => 'puscastanislav0@gmail.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.tenants.update', $tenant), [
                'plan' => 'pro',
                'max_users' => 3,
                'max_content_items' => 40,
            ])
            ->assertRedirect();

        $tenant->refresh();

        $this->assertSame('pro', $tenant->plan);
        $this->assertFalse($tenant->is_active);
        $this->assertSame(3, data_get($tenant->limits, 'max_users'));
        $this->assertSame(40, data_get($tenant->limits, 'max_content_items'));
    }

    public function test_assigning_user_to_tenant_respects_user_limit(): void
    {
        $tenant = Tenant::create([
            'name' => 'Workspace Demo',
            'slug' => 'workspace-demo',
            'plan' => 'trial',
            'is_active' => true,
            'limits' => ['max_users' => 1],
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $detachedUser = User::factory()->create([
            'tenant_id' => null,
            'role' => 'editor',
        ]);

        $admin = User::factory()->create([
            'email' => 'puscastanislav0@gmail.com',
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.dashboard'))
            ->put(route('admin.users.tenant.update', $detachedUser), [
                'tenant_action' => 'existing',
                'tenant_id' => $tenant->id,
                'role' => 'editor',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors(['tenant_id']);

        $this->assertNull($detachedUser->fresh()?->tenant_id);
    }
}
