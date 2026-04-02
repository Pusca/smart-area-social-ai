<?php

namespace Tests\Unit;

use App\Services\GoogleVeoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleVeoServiceTest extends TestCase
{
    public function test_it_creates_a_google_veo_job_with_expected_payload(): void
    {
        config()->set('google_veo.api_key', 'google-veo-key');
        config()->set('google_veo.base_url', 'https://generativelanguage.googleapis.com');
        config()->set('google_veo.api_version', 'v1beta');
        config()->set('google_veo.model', 'veo-3.1-generate-preview');
        config()->set('google_veo.video_ratio', '9:16');

        $imagePath = tempnam(sys_get_temp_dir(), 'veo-img-');
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn1o8sAAAAASUVORK5CYII='));

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-generate-preview:predictLongRunning' => function (Request $request) {
                $this->assertTrue($request->hasHeader('x-goog-api-key', 'google-veo-key'));
                $this->assertSame(8, data_get($request->data(), 'parameters.durationSeconds'));
                $this->assertSame('9:16', data_get($request->data(), 'parameters.aspectRatio'));
                $this->assertNull(data_get($request->data(), 'parameters.generateAudio'));
                $this->assertSame('allow_adult', data_get($request->data(), 'parameters.personGeneration'));
                $this->assertNotEmpty((string) data_get($request->data(), 'instances.0.image.bytesBase64Encoded'));
                $this->assertSame('image/png', data_get($request->data(), 'instances.0.image.mimeType'));

                return Http::response([
                    'name' => 'operations/veo-task-123',
                ], 200);
            },
        ]);

        try {
            $service = app(GoogleVeoService::class);
            $result = $service->createVideoJob('Prompt di test', $imagePath, [
                'model' => 'veo3.1',
                'seconds' => 5,
                'size' => '720x1280',
            ]);

            $this->assertSame('operations/veo-task-123', $result['id']);
            $this->assertSame('image_to_video', data_get($result, 'request_summary.mode'));
            $this->assertFalse((bool) data_get($result, 'request_summary.generate_audio_forwarded'));
            $this->assertSame(8, data_get($result, 'request_summary.seconds'));
        } finally {
            @unlink($imagePath);
        }
    }

    public function test_it_waits_for_completion_and_downloads_google_veo_video(): void
    {
        config()->set('google_veo.api_key', 'google-veo-key');
        config()->set('google_veo.base_url', 'https://generativelanguage.googleapis.com');
        config()->set('google_veo.api_version', 'v1beta');

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/operations/veo-task-123' => Http::response([
                'name' => 'operations/veo-task-123',
                'done' => true,
                'response' => [
                    'generatedVideos' => [
                        [
                            'video' => [
                                'downloadUri' => 'https://generativelanguage.googleapis.com/v1beta/files/generated-123:download',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://generativelanguage.googleapis.com/v1beta/files/generated-123:download' => Http::response('VIDEO_BYTES', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        $service = app(GoogleVeoService::class);
        $job = $service->waitForVideoCompletion('operations/veo-task-123');
        $bytes = $service->downloadVideoContent($job);

        $this->assertSame('VIDEO_BYTES', $bytes);
    }

    public function test_it_surfaces_google_veo_operation_failure_reason(): void
    {
        config()->set('google_veo.api_key', 'google-veo-key');
        config()->set('google_veo.base_url', 'https://generativelanguage.googleapis.com');
        config()->set('google_veo.api_version', 'v1beta');

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/operations/veo-task-failed' => Http::response([
                'name' => 'operations/veo-task-failed',
                'done' => true,
                'error' => [
                    'message' => 'safety_blocked',
                ],
            ], 200),
        ]);

        $service = app(GoogleVeoService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Veo video generation failed: safety_blocked');

        $service->waitForVideoCompletion('operations/veo-task-failed');
    }
}
