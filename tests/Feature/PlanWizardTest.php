<?php

namespace Tests\Feature;

use App\Enums\AiStatus;
use App\Jobs\GeneratePlanTopics;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Pizzeria Da Mario', 'slug' => 'da-mario']);

        $this->user = User::create([
            'name' => 'Mario',
            'email' => 'mario@example.com',
            'password' => 'password',
        ]);
        $this->user->forceFill([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ])->save();

        TenantProfile::create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Pizzeria Da Mario',
            'default_tone' => 'amichevole',
            'default_posts_per_week' => 3,
            'default_platforms' => ['instagram', 'facebook'],
            'default_formats' => ['post', 'reel'],
        ]);
    }

    public function test_single_step_wizard_creates_plan_and_starts_generation(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->post(route('wizard.store'), [
            'name' => 'Piano settembre',
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-20', // 2 settimane
            'goal' => 'Più prenotazioni nel weekend',
            'posts_per_week' => 3,
        ]);

        $response->assertRedirect(route('wizard.done'));

        $plan = ContentPlan::firstOrFail();

        // Tono/piattaforme/formati presi dal profilo, non richiesti all'utente
        $this->assertSame('amichevole', $plan->settings['tone']);
        $this->assertSame(['instagram', 'facebook'], $plan->settings['platforms']);
        $this->assertSame(['post', 'reel'], $plan->settings['formats']);

        // posts_per_week è a settimana: 3 × 2 settimane = 6 item
        $items = ContentItem::where('content_plan_id', $plan->id)->get();
        $this->assertCount(6, $items);
        $this->assertTrue($items->every(fn ($i) => $i->ai_status === AiStatus::Queued));

        Queue::assertPushed(GeneratePlanTopics::class, 1);
    }

    public function test_wizard_accepts_long_goal_from_profile_defaults(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post(route('wizard.store'), [
            'name' => 'Piano goal lungo',
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-13',
            'goal' => str_repeat('a', 400), // il profilo consente fino a 500
            'posts_per_week' => 2,
        ])->assertSessionHasNoErrors();
    }

    public function test_wizard_requires_profile(): void
    {
        TenantProfile::query()->delete();

        $this->actingAs($this->user)
            ->get(route('wizard.start'))
            ->assertRedirect(route('profile.brand'));
    }

    public function test_done_page_shows_generation_progress(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post(route('wizard.store'), [
            'name' => 'Piano progress',
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-13',
            'goal' => 'Awareness',
            'posts_per_week' => 4,
        ]);

        ContentItem::query()->limit(2)->update(['ai_status' => AiStatus::Done->value]);

        $this->actingAs($this->user)
            ->get(route('wizard.done'))
            ->assertOk()
            ->assertSee('2/4')
            ->assertSee('Rigenera piano');
    }
}
