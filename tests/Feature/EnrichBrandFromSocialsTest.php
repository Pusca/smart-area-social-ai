<?php

namespace Tests\Feature;

use App\Jobs\EnrichBrandFromSocials;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Services\OpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichBrandFromSocialsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.api_key' => 'test-key',
            'openai.base_url' => 'https://api.openai.com',
            'services.apify.token' => 'apify-test-token',
        ]);

        $this->tenant = Tenant::create(['name' => 'Pizzeria Da Mario', 'slug' => 'da-mario']);
    }

    public function test_real_instagram_posts_fill_examples_and_voice(): void
    {
        TenantProfile::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Pizzeria Da Mario',
            'social_links' => ['instagram' => 'https://www.instagram.com/damario'],
        ]);

        Http::fake([
            'api.apify.com/*' => Http::response([[
                'username' => 'damario',
                'biography' => 'Pizza napoletana dal 1990',
                'latestPosts' => [
                    ['caption' => 'Stasera si inforna! 🍕 Vi aspettiamo dalle 19.'],
                    ['caption' => 'Impasto 48 ore: la differenza si sente al primo morso.'],
                    ['caption' => ''],
                    ['caption' => 'Grazie a tutti per il weekend pieno! ❤️'],
                ],
            ]]),
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['brand_voice' => 'Tono caloroso e diretto, dà del tu, emoji frequenti.']),
                    ]],
                ]],
                'usage' => ['input_tokens' => 30, 'output_tokens' => 40],
            ]),
        ]);

        (new EnrichBrandFromSocials($this->tenant->id))->handle(app(OpenAiService::class));

        $profile = TenantProfile::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertStringContainsString('Stasera si inforna', $profile->example_posts);
        $this->assertStringContainsString('Impasto 48 ore', $profile->example_posts);
        $this->assertSame('Tono caloroso e diretto, dà del tu, emoji frequenti.', $profile->brand_voice);
    }

    public function test_user_written_fields_are_never_overwritten(): void
    {
        TenantProfile::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Pizzeria Da Mario',
            'brand_voice' => 'Voce scritta a mano dal cliente.',
            'example_posts' => 'Esempio scritto a mano.',
            'social_links' => ['instagram' => 'https://www.instagram.com/damario'],
        ]);

        Http::fake([
            'api.apify.com/*' => Http::response([[
                'latestPosts' => [['caption' => 'Post nuovo di Instagram']],
            ]]),
        ]);

        (new EnrichBrandFromSocials($this->tenant->id))->handle(app(OpenAiService::class));

        $profile = TenantProfile::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertSame('Voce scritta a mano dal cliente.', $profile->brand_voice);
        $this->assertSame('Esempio scritto a mano.', $profile->example_posts);
        // Nessuna chiamata OpenAI: non serviva nulla
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_apify_error_is_swallowed(): void
    {
        TenantProfile::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Pizzeria Da Mario',
            'social_links' => ['instagram' => 'https://www.instagram.com/damario'],
        ]);

        Http::fake(['api.apify.com/*' => Http::response(['error' => 'quota'], 500)]);

        // Non deve lanciare: l'arricchimento è best-effort
        (new EnrichBrandFromSocials($this->tenant->id))->handle(app(OpenAiService::class));

        $this->assertNull(TenantProfile::where('tenant_id', $this->tenant->id)->value('example_posts'));
    }
}
