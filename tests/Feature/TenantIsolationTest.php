<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private ContentPlan $planA;
    private ContentItem $itemA;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $this->userA = $this->makeUser($tenantA->id, 'a@example.com');
        $this->userB = $this->makeUser($tenantB->id, 'b@example.com');

        $this->planA = ContentPlan::create([
            'tenant_id' => $tenantA->id,
            'created_by' => $this->userA->id,
            'name' => 'Piano A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'settings' => ['goal' => 'Lead', 'tone' => 'professionale'],
        ]);

        $this->itemA = ContentItem::create([
            'tenant_id' => $tenantA->id,
            'content_plan_id' => $this->planA->id,
            'created_by' => $this->userA->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Post di A',
            'ai_status' => 'idle',
        ]);
    }

    private function makeUser(int $tenantId, string $email): User
    {
        $user = User::create([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => 'password',
        ]);
        $user->forceFill([
            'tenant_id' => $tenantId,
            'email_verified_at' => now(),
        ])->save();

        return $user->fresh();
    }

    public function test_owner_can_open_own_content_item(): void
    {
        $this->actingAs($this->userA)
            ->get(route('posts.edit', $this->itemA))
            ->assertOk();
    }

    public function test_other_tenant_cannot_open_content_item(): void
    {
        $this->actingAs($this->userB)
            ->get(route('posts.edit', $this->itemA))
            ->assertNotFound();
    }

    public function test_other_tenant_cannot_trigger_item_generation(): void
    {
        $this->actingAs($this->userB)
            ->post(route('ai.content.generate', $this->itemA))
            ->assertNotFound();
    }

    public function test_other_tenant_cannot_trigger_plan_generation(): void
    {
        $this->actingAs($this->userB)
            ->post(route('ai.plan.generate', $this->planA))
            ->assertNotFound();
    }

    public function test_status_endpoint_does_not_leak_other_tenant_items(): void
    {
        $this->actingAs($this->userB)
            ->getJson(route('ai.status', ['ids' => [$this->itemA->id]]))
            ->assertOk()
            ->assertJson(['items' => []]);
    }

    public function test_posts_index_only_shows_own_items(): void
    {
        $this->actingAs($this->userB)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('Post di A');
    }
}
