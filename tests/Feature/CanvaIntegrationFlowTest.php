<?php

namespace Tests\Feature;

use App\Jobs\PollCanvaDesignAutofillJob;
use App\Jobs\PollCanvaExportJob;
use App\Models\BrandAsset;
use App\Models\CanvaConnection;
use App\Models\CanvaDesign;
use App\Models\CanvaExportJob;
use App\Models\CanvaTemplateMapping;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CanvaIntegrationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('social_manager.features.canva_integration_v1', true);
        config()->set('canva.enabled', true);
        config()->set('canva.client_id', 'canva-client');
        config()->set('canva.client_secret', 'canva-secret');
        config()->set('canva.redirect_uri', 'https://social-ai.test/settings/integrations/canva/callback');
        config()->set('canva.api_base_url', 'https://api.canva.test/rest/v1');
        config()->set('canva.token_url', 'https://api.canva.test/rest/v1/oauth/token');
        config()->set('canva.authorize_url', 'https://www.canva.test/api/oauth/authorize');
        config()->set('canva.manual_editor_url', 'https://www.canva.test/');
    }

    public function test_canva_oauth_callback_persists_connection_and_capabilities(): void
    {
        [$tenant, $user] = $this->createTenantUserPair('tenant-canva-oauth');

        Http::fake([
            'https://api.canva.test/rest/v1/oauth/token' => Http::response([
                'access_token' => 'canva-access',
                'refresh_token' => 'canva-refresh',
                'expires_in' => 3600,
                'scope' => 'profile:read asset:write brandtemplate:meta:read brandtemplate:content:read design:content:read design:content:write',
            ], 200),
            'https://api.canva.test/rest/v1/users/me' => Http::response([
                'team_user' => [
                    'user_id' => 'canva-user-1',
                    'team_id' => 'canva-team-1',
                ],
            ], 200),
            'https://api.canva.test/rest/v1/users/me/capabilities' => Http::response([
                'capabilities' => ['autofill', 'brand_template'],
            ], 200),
            'https://api.canva.test/rest/v1/users/me/profile' => Http::response([
                'profile' => [
                    'display_name' => 'Canva Tester',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession([
                'canva_oauth' => [
                    'state' => 'state-123',
                    'code_verifier' => 'verifier-123',
                ],
            ])
            ->get(route('settings.integrations.canva.callback', [
                'state' => 'state-123',
                'code' => 'oauth-code-123',
            ]))
            ->assertRedirect(route('settings'));

        $this->assertDatabaseHas('canva_connections', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'canva_user_id' => 'canva-user-1',
            'canva_team_id' => 'canva-team-1',
            'status' => 'active',
        ]);

        $connection = CanvaConnection::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertContains('autofill', (array) $connection->capabilities);
        $this->assertSame('Canva Tester', $connection->canva_display_name);
    }

    public function test_send_content_item_to_canva_uses_autofill_and_queues_poll_job(): void
    {
        Queue::fake();
        Storage::fake('public');

        [$tenant, $user] = $this->createTenantUserPair('tenant-canva-autofill');
        $plan = $this->createPlan($tenant->id, $user->id);
        $item = $this->createContentItem($tenant->id, $user->id, $plan->id);

        Storage::disk('public')->put('brand/logo.png', 'logo-bytes');
        Storage::disk('public')->put('ai/canva-visual.png', 'image-bytes');

        BrandAsset::create([
            'tenant_id' => $tenant->id,
            'kind' => 'logo',
            'path' => 'brand/logo.png',
            'original_name' => 'logo.png',
            'size' => 12,
            'mime' => 'image/png',
            'meta' => [],
        ]);

        CanvaConnection::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'canva_user_id' => 'canva-user-2',
            'canva_team_id' => 'canva-team-2',
            'access_token_encrypted' => 'token-active',
            'refresh_token_encrypted' => 'refresh-active',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['asset:write', 'brandtemplate:content:read', 'design:content:write', 'design:content:read'],
            'capabilities' => ['autofill', 'brand_template'],
            'status' => 'active',
            'meta' => [],
        ]);

        CanvaTemplateMapping::create([
            'tenant_id' => $tenant->id,
            'channel_format' => 'instagram_post',
            'canva_template_id' => 'tmpl_instagram_post',
            'canva_template_name' => 'Instagram Hero Template',
            'dataset_schema_json' => [
                'HEADLINE' => ['type' => 'text'],
                'CTA' => ['type' => 'text'],
                'HERO_IMAGE' => ['type' => 'image'],
                'LOGO' => ['type' => 'image'],
            ],
            'mapping_rules_json' => [
                'field_map' => [
                    'HEADLINE' => 'headline',
                    'CTA' => 'cta',
                    'HERO_IMAGE' => 'primary_image',
                    'LOGO' => 'logo',
                ],
            ],
            'status' => 'active',
            'canva_view_url' => 'https://www.canva.test/design/template/view',
            'canva_create_url' => 'https://www.canva.test/design/template/remix',
            'meta' => [],
        ]);

        Http::fake([
            'https://api.canva.test/rest/v1/asset-uploads' => Http::sequence()
                ->push([
                    'job' => [
                        'id' => 'asset-job-logo',
                        'status' => 'success',
                        'asset' => ['id' => 'asset-logo-id'],
                    ],
                ], 200)
                ->push([
                    'job' => [
                        'id' => 'asset-job-image',
                        'status' => 'success',
                        'asset' => ['id' => 'asset-image-id'],
                    ],
                ], 200),
            'https://api.canva.test/rest/v1/autofills' => Http::response([
                'job' => [
                    'id' => 'autofill-job-1',
                    'status' => 'in_progress',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('content-items.canva.send', $item), [
                'channel_format' => 'instagram_post',
                'include_generated_visual' => '1',
                'include_logo' => '1',
            ])
            ->assertRedirect(route('content-items.show', $item));

        $design = CanvaDesign::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame('autofill', $design->source_mode);
        $this->assertSame('in_progress', $design->status);
        $this->assertSame('tmpl_instagram_post', $design->template_id);
        $this->assertSame('asset-image-id', data_get($design->generation_payload_json, 'autofill_data.HERO_IMAGE.asset_id'));

        Queue::assertPushed(PollCanvaDesignAutofillJob::class, function (PollCanvaDesignAutofillJob $job) use ($design) {
            return $job->canvaDesignId === $design->id;
        });
    }

    public function test_send_content_item_to_canva_falls_back_to_manual_when_autofill_is_unavailable(): void
    {
        Storage::fake('public');

        [$tenant, $user] = $this->createTenantUserPair('tenant-canva-manual');
        $plan = $this->createPlan($tenant->id, $user->id);
        $item = $this->createContentItem($tenant->id, $user->id, $plan->id, [
            'ai_image_path' => null,
        ]);

        CanvaConnection::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'canva_user_id' => 'canva-user-3',
            'canva_team_id' => 'canva-team-3',
            'access_token_encrypted' => 'token-active',
            'refresh_token_encrypted' => 'refresh-active',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['design:content:read'],
            'capabilities' => [],
            'status' => 'active',
            'meta' => [],
        ]);

        $response = $this->actingAs($user)
            ->post(route('content-items.canva.send', $item), [
                'channel_format' => 'instagram_post',
            ]);

        $design = CanvaDesign::query()->where('content_item_id', $item->id)->firstOrFail();

        $response->assertRedirect(route('canva.designs.handoff', $design));
        $this->assertSame('fallback_manual', $design->source_mode);
        $this->assertSame('manual_handoff_ready', $design->status);
        $this->assertSame('https://www.canva.test/', $design->canva_edit_url);
    }

    public function test_can_request_and_refresh_canva_export_back_into_storage(): void
    {
        Queue::fake();
        Storage::fake('public');

        [$tenant, $user] = $this->createTenantUserPair('tenant-canva-export');
        $plan = $this->createPlan($tenant->id, $user->id);
        $item = $this->createContentItem($tenant->id, $user->id, $plan->id, [
            'assets' => [],
        ]);

        CanvaConnection::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'canva_user_id' => 'canva-user-4',
            'canva_team_id' => 'canva-team-4',
            'access_token_encrypted' => 'token-active',
            'refresh_token_encrypted' => 'refresh-active',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['design:content:read'],
            'capabilities' => [],
            'status' => 'active',
            'meta' => [],
        ]);

        $design = CanvaDesign::create([
            'tenant_id' => $tenant->id,
            'content_item_id' => $item->id,
            'content_plan_id' => $plan->id,
            'design_type' => 'instagram_post',
            'canva_design_id' => 'design-export-1',
            'canva_edit_url' => 'https://www.canva.test/design/design-export-1/edit',
            'source_mode' => 'autofill',
            'generation_payload_json' => [],
            'status' => 'ready_in_canva',
            'meta' => [],
        ]);

        Http::fake([
            'https://api.canva.test/rest/v1/exports' => Http::response([
                'job' => [
                    'id' => 'export-job-1',
                    'status' => 'in_progress',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('canva.designs.export', $design), [
                'export_type' => 'png',
            ])
            ->assertRedirect(route('content-items.show', $item));

        $exportJob = CanvaExportJob::query()->where('canva_design_id', $design->id)->firstOrFail();
        Queue::assertPushed(PollCanvaExportJob::class, function (PollCanvaExportJob $job) use ($exportJob) {
            return $job->canvaExportJobId === $exportJob->id;
        });

        Http::fake([
            'https://api.canva.test/rest/v1/exports/export-job-1' => Http::response([
                'job' => [
                    'id' => 'export-job-1',
                    'status' => 'success',
                    'urls' => ['https://download.canva.test/export.png'],
                ],
            ], 200),
            'https://download.canva.test/export.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($user)
            ->post(route('canva.exports.refresh', $exportJob))
            ->assertRedirect();

        $exportJob->refresh();
        $design->refresh();
        $item->refresh();

        $this->assertSame('success', $exportJob->status);
        $this->assertNotNull($exportJob->stored_path);
        Storage::disk('public')->assertExists($exportJob->stored_path);
        $this->assertSame('exported', $design->status);
        $this->assertSame($exportJob->stored_path, $design->exported_asset_path);
        $this->assertNotEmpty($item->assets);
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
            'name' => 'Piano Canva',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'settings' => [],
            'strategy' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createContentItem(int $tenantId, int $userId, int $planId, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'tenant_id' => $tenantId,
            'content_plan_id' => $planId,
            'created_by' => $userId,
            'platform' => 'instagram',
            'format' => 'post',
            'scheduled_at' => now()->addHour(),
            'status' => 'draft',
            'title' => 'Titolo Canva',
            'caption' => 'Caption sorgente',
            'content_angle' => 'Angle test',
            'ai_caption' => 'Caption finale pronta per Canva',
            'ai_cta' => 'Scrivici per saperne di piu.',
            'ai_image_path' => 'ai/canva-visual.png',
            'ai_status' => 'done',
            'ai_meta' => [
                'hook_meta' => [
                    'main_hook' => 'Hook principale per Canva',
                    'narrative_angle' => 'Narrative angle Canva',
                ],
                'creative_brief' => [
                    'content_angle' => 'Narrative angle Canva',
                ],
            ],
        ], $overrides));
    }
}
