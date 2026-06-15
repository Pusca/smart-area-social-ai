<?php

namespace Tests\Unit;

use App\Services\OpenAiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiServiceTest extends TestCase
{
    public function test_it_requests_structured_json_for_generate_content_and_parses_balanced_payload(): void
    {
        config()->set('openai.api_key', 'openai-test-key');
        config()->set('openai.base_url', 'https://api.openai.com');
        config()->set('openai.text_model', 'gpt-4.1-mini');
        config()->set('openai.text_max_output_tokens', 1400);

        Http::fake([
            'https://api.openai.com/v1/responses' => function (Request $request) {
                $payload = $request->data();

                $this->assertSame('gpt-4.1-mini', $payload['model']);
                $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
                $this->assertSame('social_content_generation', data_get($payload, 'text.format.name'));
                $this->assertTrue((bool) data_get($payload, 'text.format.strict'));
                $this->assertSame(1400, (int) ($payload['max_output_tokens'] ?? 0));

                return Http::response([
                    'id' => 'resp_text_123',
                    'output' => [[
                        'content' => [[
                            'type' => 'output_text',
                            'text' => "JSON richiesto:\n{\n  \"caption\": \"Caption finale coerente.\",\n  \"hashtags\": [\"#porsche911\", \"#motorsportworkspace\"],\n  \"cta\": \"Scrivici per dettagli.\",\n  \"image_prompt\": \"Foto editoriale realistica della Porsche 911 bianca.\",\n  \"video_prompt\": \"Reel verticale realistico con presenter e auto.\",\n  \"voiceover\": \"Un dettaglio fa davvero la differenza.\",\n  \"reel_blueprint\": null\n}\nNote finali da ignorare.",
                        ]],
                    ]],
                    'usage' => [
                        'input_tokens' => 120,
                        'output_tokens' => 80,
                        'total_tokens' => 200,
                    ],
                ], 200);
            },
        ]);

        $service = app(OpenAiService::class);
        $result = $service->generateContent([
            'item' => [
                'platform' => 'instagram',
                'format' => 'reel',
                'title' => 'Test reel',
            ],
        ]);

        $this->assertSame('Caption finale coerente.', $result['caption']);
        $this->assertSame(['#porsche911', '#motorsportworkspace'], $result['hashtags']);
        $this->assertSame('Scrivici per dettagli.', $result['cta']);
        $this->assertSame('Foto editoriale realistica della Porsche 911 bianca.', $result['image_prompt']);
        $this->assertSame('Reel verticale realistico con presenter e auto.', $result['video_prompt']);
        $this->assertSame('Un dettaglio fa davvero la differenza.', $result['voiceover']);
        $this->assertNull($result['reel_blueprint']);
        $this->assertSame('resp_text_123', $result['response_id']);
        $this->assertSame(['input_tokens' => 120, 'output_tokens' => 80, 'total_tokens' => 200], $result['usage']);
    }

    public function test_it_sends_video_input_reference_as_json_object(): void
    {
        config()->set('openai.api_key', 'openai-test-key');
        config()->set('openai.base_url', 'https://api.openai.com');

        $tmpPath = tempnam(sys_get_temp_dir(), 'openai-video-ref-');
        file_put_contents(
            $tmpPath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4////fwAJ+wP9KobjigAAAABJRU5ErkJggg==')
        );

        try {
            Http::fake([
                'https://api.openai.com/v1/videos' => function (Request $request) {
                    $payload = $request->data();

                    $this->assertSame('sora-2', $payload['model']);
                    $this->assertSame('Prompt di test', $payload['prompt']);
                    $this->assertSame('8', (string) $payload['seconds']);
                    $this->assertSame('720x1280', $payload['size']);
                    $this->assertIsArray($payload['input_reference']);
                    $this->assertArrayHasKey('image_url', $payload['input_reference']);
                    $this->assertStringStartsWith('data:image/png;base64,', (string) $payload['input_reference']['image_url']);

                    return Http::response([
                        'id' => 'video_123',
                    ], 200);
                },
            ]);

            $service = app(OpenAiService::class);
            $result = $service->createVideoJob('Prompt di test', $tmpPath, [
                'model' => 'sora-2',
                'seconds' => 8,
                'size' => '720x1280',
            ]);

            $this->assertSame('video_123', $result['id']);
        } finally {
            if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
