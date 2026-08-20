<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentItemCrudTest extends TestCase
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
    }

    public function test_manual_post_keeps_typed_caption(): void
    {
        $this->actingAs($this->user)->post(route('posts.store'), [
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Post manuale',
            'ai_caption' => 'Caption scritta a mano dal cliente.',
        ])->assertRedirect(route('posts.index'));

        $this->assertSame(
            'Caption scritta a mano dal cliente.',
            ContentItem::firstOrFail()->ai_caption
        );
    }

    public function test_update_saves_status_hashtags_and_cta(): void
    {
        $item = ContentItem::create([
            'tenant_id' => $this->user->tenant_id,
            'created_by' => $this->user->id,
            'platform' => 'instagram',
            'format' => 'post',
            'status' => 'draft',
            'title' => 'Da approvare',
        ]);

        $this->actingAs($this->user)->put(route('posts.update', $item), [
            'platform' => 'instagram',
            'format' => 'reel',
            'status' => 'approved',
            'title' => 'Approvato',
            'ai_caption' => 'Caption rivista',
            'ai_hashtags' => '#pizza, #napoli #damario',
            'ai_cta' => 'Prenota ora',
        ])->assertSessionHasNoErrors()->assertRedirect(route('posts.index'));

        $item->refresh();

        $this->assertSame('approved', $item->status);
        $this->assertSame('reel', $item->format);
        $this->assertSame(['#pizza', '#napoli', '#damario'], $item->ai_hashtags);
        $this->assertSame('Prenota ora', $item->ai_cta);
    }
}
