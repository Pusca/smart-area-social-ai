<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialPublication;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_approval_creates_scheduled_publications_for_meta_platforms(): void
    {
        config()->set('meta.allow_local_public_urls', true);
        Storage::fake('public');
        Storage::disk('public')->put('ai/test-image.png', 'fake-image');

        [$tenant, $user] = $this->createTenantUserPair('tenant-social-approval');
        $plan = $this->createPlan($tenant->id, $user->id);
        $this->createMetaAccount($tenant->id, $user->id, 'facebook', 'fb-page-1');
        $this->createMetaAccount($tenant->id, $user->id, 'instagram', 'ig-business-1');

        $item = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'instagram,facebook',
            'format' => 'post',
            'scheduled_at' => now()->addHour(),
            'status' => 'review',
            'title' => 'Post pronto',
            'caption' => 'Caption di fallback',
            'ai_caption' => 'Caption AI finale',
            'ai_cta' => 'Scrivici in DM.',
            'ai_hashtags' => ['#hostup', '#social'],
            'ai_image_path' => 'ai/test-image.png',
            'ai_status' => 'done',
        ]);

        $this->actingAs($user)
            ->post(route('calendar.content.approve', $item))
            ->assertRedirect();

        $this->assertDatabaseHas('content_items', [
            'id' => $item->id,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseCount('social_publications', 2);
        $this->assertDatabaseHas('social_publications', [
            'content_item_id' => $item->id,
            'platform' => 'facebook',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('social_publications', [
            'content_item_id' => $item->id,
            'platform' => 'instagram',
            'status' => 'scheduled',
        ]);
    }

    public function test_social_publish_due_command_dispatches_due_publications(): void
    {
        Queue::fake();
        config()->set('meta.allow_local_public_urls', true);

        [$tenant, $user] = $this->createTenantUserPair('tenant-social-command');
        $plan = $this->createPlan($tenant->id, $user->id);
        $account = $this->createMetaAccount($tenant->id, $user->id, 'facebook', 'fb-page-2');

        $item = ContentItem::create([
            'tenant_id' => $tenant->id,
            'content_plan_id' => $plan->id,
            'created_by' => $user->id,
            'platform' => 'facebook',
            'format' => 'post',
            'scheduled_at' => now()->subMinute(),
            'status' => 'scheduled',
            'title' => 'Post dovuto',
            'ai_caption' => 'Caption AI finale',
            'ai_status' => 'done',
        ]);

        $publication = SocialPublication::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'social_account_id' => $account->id,
            'provider' => 'meta',
            'platform' => 'facebook',
            'status' => 'scheduled',
            'media_type' => 'image',
            'caption' => 'Caption AI finale',
            'media_url' => 'https://example.test/storage/test-image.png',
            'scheduled_for' => now()->subMinute(),
            'payload' => [],
        ]);

        $this->artisan('social:publish-due --limit=10')
            ->assertExitCode(0);

        Queue::assertPushed(PublishSocialPublication::class, function (PublishSocialPublication $job) use ($publication) {
            return $job->socialPublicationId === $publication->id;
        });

        $this->assertDatabaseHas('social_publications', [
            'id' => $publication->id,
            'status' => 'processing',
        ]);
    }

    private function createTenantUserPair(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => strtoupper($slug),
            'slug' => $slug,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [$tenant, $user];
    }

    private function createPlan(int $tenantId, int $userId): ContentPlan
    {
        return ContentPlan::create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'name' => 'Piano Social',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }

    private function createMetaAccount(int $tenantId, int $userId, string $platform, string $accountId): SocialAccount
    {
        return SocialAccount::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'provider' => 'meta',
            'platform' => $platform,
            'status' => 'active',
            'is_primary' => true,
            'account_name' => strtoupper($platform) . ' Account',
            'account_id' => $accountId,
            'username' => $platform . '_user',
            'access_token' => 'token-' . $platform,
            'connected_at' => now(),
            'last_synced_at' => now(),
            'meta' => [],
        ]);
    }
}
