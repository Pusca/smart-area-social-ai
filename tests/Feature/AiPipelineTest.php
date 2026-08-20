<?php

namespace Tests\Feature;

use App\Enums\AiStatus;
use App\Jobs\GenerateAiForContentItem;
use App\Jobs\GeneratePlanTopics;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use App\Services\ContentAiGenerator;
use App\Services\OpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private ContentPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.api_key' => 'test-key',
            'openai.base_url' => 'https://api.openai.com',
            'openai.refine_pass' => true,
        ]);

        $this->tenant = Tenant::create(['name' => 'Pizzeria Da Mario', 'slug' => 'da-mario']);

        $this->user = User::create([
            'name' => 'Mario',
            'email' => 'mario@example.com',
            'password' => 'password',
        ]);
        $this->user->forceFill([
            'tenant_id' => $this->tenant->id,
            'email_verified_at' => now(),
        ])->save();

        TenantProfile::create([
            'tenant_id' => $this->tenant->id,
            'business_name' => 'Pizzeria Da Mario',
            'industry' => 'Ristorazione',
            'services' => 'Pizza napoletana, impasti a lunga lievitazione',
            'target' => 'Famiglie e giovani della zona',
            'cta' => 'Prenota un tavolo',
            'brand_voice' => 'Caloroso e diretto, diamo del tu.',
        ]);

        $this->plan = ContentPlan::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Piano test',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'settings' => [
                'goal' => 'Lead',
                'tone' => 'amichevole',
                'platforms' => ['instagram'],
                'formats' => ['post'],
            ],
        ]);
    }

    private function makeItems(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ContentItem::create([
                'tenant_id' => $this->tenant->id,
                'content_plan_id' => $this->plan->id,
                'created_by' => $this->user->id,
                'platform' => 'instagram',
                'format' => 'post',
                'status' => 'draft',
                'scheduled_at' => now()->addDays($i),
                'title' => 'Placeholder',
                'ai_status' => AiStatus::Queued,
            ]);
        }
    }

    /**
     * Fake delle Responses API: risponde in base allo schema richiesto
     * (plan_topics o social_post) e alle Images API.
     */
    private function fakeOpenAi(int $topicsCount = 3): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => function (Request $request) use ($topicsCount) {
                $schemaName = data_get($request->data(), 'text.format.name');

                $payload = match ($schemaName) {
                    'plan_topics' => [
                        'topics' => collect(range(1, $topicsCount))->map(fn ($i) => [
                            'title' => "Argomento {$i}",
                            'angle' => 'educativo',
                            'key_points' => ['punto uno', 'punto due'],
                        ])->all(),
                    ],
                    default => [
                        'caption' => 'La nostra pizza nasce da 48 ore di lievitazione.',
                        'hashtags' => ['pizzanapoletana', '#damario'],
                        'cta' => 'Prenota un tavolo',
                        'image_prompt' => 'Neapolitan pizza on rustic table, warm light',
                    ],
                };

                return Http::response([
                    'output' => [[
                        'content' => [[
                            'type' => 'output_text',
                            'text' => json_encode($payload),
                        ]],
                    ]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
                ]);
            },
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode('fake-png-bytes')]],
                'usage' => [],
            ]),
        ]);
    }

    public function test_plan_topics_are_assigned_and_items_queued(): void
    {
        $this->makeItems(3);
        $this->fakeOpenAi(topicsCount: 3);
        Queue::fake();

        (new GeneratePlanTopics($this->plan->id))->handle(app(OpenAiService::class));

        $items = ContentItem::where('content_plan_id', $this->plan->id)
            ->orderBy('scheduled_at')->get();

        $this->assertSame(
            ['Argomento 1', 'Argomento 2', 'Argomento 3'],
            $items->map(fn ($i) => data_get($i->ai_meta, 'topic.title'))->all()
        );
        $this->assertSame('Argomento 1', $items->first()->title);

        Queue::assertPushed(GenerateAiForContentItem::class, 3);
    }

    public function test_topic_ideation_retries_when_model_returns_too_few(): void
    {
        $this->makeItems(5);
        // Il fake ne restituisce 3 per chiamata: servono 2 chiamate per 5 item
        $this->fakeOpenAi(topicsCount: 3);
        Queue::fake();

        (new GeneratePlanTopics($this->plan->id))->handle(app(OpenAiService::class));

        $titles = ContentItem::where('content_plan_id', $this->plan->id)
            ->get()
            ->map(fn ($i) => data_get($i->ai_meta, 'topic.title'))
            ->filter();

        $this->assertCount(5, $titles);
        Http::assertSentCount(2);
    }

    public function test_item_generation_writes_text_and_image(): void
    {
        Storage::fake('public');
        $this->makeItems(1);
        $this->fakeOpenAi();

        $item = ContentItem::firstOrFail();
        $meta = ['topic' => ['title' => 'Lievitazione 48h', 'angle' => 'educativo', 'key_points' => ['impasto']]];
        $item->ai_meta = $meta;
        $item->save();

        (new GenerateAiForContentItem($item->id))->handle(app(ContentAiGenerator::class));

        $item->refresh();

        $this->assertSame(AiStatus::Done, $item->ai_status);
        $this->assertSame('La nostra pizza nasce da 48 ore di lievitazione.', $item->ai_caption);
        // Gli hashtag vengono normalizzati con # iniziale
        $this->assertSame(['#pizzanapoletana', '#damario'], $item->ai_hashtags);
        $this->assertNotNull($item->ai_image_path);
        Storage::disk('public')->assertExists($item->ai_image_path);

        // Il contesto brand è letto live dal profilo (brand_voice incluso nel prompt)
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1/responses')
                && str_contains((string) data_get($request->data(), 'instructions'), 'Caloroso e diretto');
        });
    }

    public function test_writer_prompt_includes_brand_facts_and_sibling_openings(): void
    {
        Storage::fake('public');
        $this->makeItems(2);
        $this->fakeOpenAi();

        [$first, $second] = ContentItem::orderBy('id')->get()->all();
        $first->ai_caption = "La lievitazione lenta cambia tutto.\nSeconda riga.";
        $first->save();

        (new GenerateAiForContentItem($second->id))->handle(app(ContentAiGenerator::class));

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/v1/responses')) {
                return false;
            }
            $instructions = (string) data_get($request->data(), 'instructions');

            // Scheda attività promossa a istruzioni + anti-ripetizione tra post fratelli
            return str_contains($instructions, 'Pizza napoletana, impasti a lunga lievitazione')
                && str_contains($instructions, 'Famiglie e giovani della zona')
                && str_contains($instructions, 'La lievitazione lenta cambia tutto.');
        });
    }

    public function test_ideation_uses_dedicated_reasoning_effort(): void
    {
        $this->makeItems(2);
        $this->fakeOpenAi(topicsCount: 2);
        Queue::fake();

        (new GeneratePlanTopics($this->plan->id))->handle(app(OpenAiService::class));

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1/responses')
                && data_get($request->data(), 'text.format.name') === 'plan_topics'
                && data_get($request->data(), 'reasoning.effort') === 'medium';
        });
    }

    public function test_generation_error_marks_item_as_error(): void
    {
        $this->makeItems(1);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        $item = ContentItem::firstOrFail();
        $job = new GenerateAiForContentItem($item->id);

        try {
            $job->handle(app(ContentAiGenerator::class));
            $this->fail('Expected exception');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $item->refresh();
        $this->assertSame(AiStatus::Error, $item->ai_status);
        $this->assertNotNull($item->ai_error);
    }

    public function test_brand_prefill_from_website(): void
    {
        Http::fake([
            'www.damario.example/*' => Http::response(
                '<html><head><title>Da Mario</title></head><body>'
                . str_repeat('<p>Pizzeria napoletana a Venezia. Impasti a lunga lievitazione, forno a legna, ingredienti DOP. Prenota il tuo tavolo.</p>', 10)
                . '</body></html>'
            ),
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'business_name' => 'Pizzeria Da Mario',
                            'industry' => 'Ristorazione',
                            'services' => 'Pizza napoletana, forno a legna',
                            'target' => 'Famiglie della zona',
                            'cta' => 'Prenota un tavolo',
                            'brand_voice' => 'Caloroso e genuino',
                            'notes' => 'Ingredienti DOP',
                        ]),
                    ]],
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 80],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('profile.brand.prefill'), ['website' => 'https://www.damario.example/'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.business_name', 'Pizzeria Da Mario');
    }
}
