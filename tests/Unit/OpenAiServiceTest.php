<?php

namespace Tests\Unit;

use App\Services\OpenAiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiServiceTest extends TestCase
{
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
