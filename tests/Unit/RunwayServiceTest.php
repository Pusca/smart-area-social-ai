<?php

namespace Tests\Unit;

use App\Services\RunwayService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RunwayServiceTest extends TestCase
{
    public function test_it_caps_runway_duration_to_ten_seconds_for_image_to_video(): void
    {
        config()->set('runway.api_key', 'runway-test-key');
        config()->set('runway.base_url', 'https://api.dev.runwayml.com');
        config()->set('runway.create_endpoint', '/v1/image_to_video');

        Http::fake([
            'https://api.dev.runwayml.com/v1/image_to_video' => function ($request) {
                $this->assertSame(10, $request['duration']);

                return Http::response([
                    'id' => 'task_123',
                ], 200);
            },
        ]);

        $service = app(RunwayService::class);
        $result = $service->createVideoJob('Prompt di test', null, [
            'model' => 'gen4.5',
            'seconds' => 12,
            'size' => '720x1280',
        ]);

        $this->assertSame('task_123', $result['id']);
    }

    public function test_it_caps_runway_veo_duration_to_eight_seconds(): void
    {
        config()->set('runway.api_key', 'runway-test-key');
        config()->set('runway.base_url', 'https://api.dev.runwayml.com');
        config()->set('runway.create_endpoint', '/v1/image_to_video');

        Http::fake([
            'https://api.dev.runwayml.com/v1/image_to_video' => function (Request $request) {
                $this->assertSame(8, $request['duration']);
                $this->assertSame('veo3.1_fast', $request['model']);

                return Http::response([
                    'id' => 'task_veo_123',
                ], 200);
            },
        ]);

        $service = app(RunwayService::class);
        $result = $service->createVideoJob('Prompt di test', null, [
            'model' => 'veo3.1_fast',
            'seconds' => 10,
            'size' => '720x1280',
        ]);

        $this->assertSame('task_veo_123', $result['id']);
    }

    public function test_it_surfaces_runway_failure_code_in_exception_message(): void
    {
        config()->set('runway.api_key', 'runway-test-key');
        config()->set('runway.base_url', 'https://api.dev.runwayml.com');
        config()->set('runway.retrieve_endpoint', '/v1/tasks/{id}');
        config()->set('runway.poll_timeout', 30);
        config()->set('runway.poll_interval', 2);

        Http::fake([
            'https://api.dev.runwayml.com/v1/tasks/*' => Http::response([
                'status' => 'failed',
                'error' => [
                    'code' => 'safety_rejected',
                    'message' => 'video_generation_failed',
                ],
            ], 200),
        ]);

        $service = app(RunwayService::class);

        try {
            $service->waitForVideoCompletion('task_123');
            $this->fail('Expected waitForVideoCompletion to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Runway video generation failed: safety_rejected: video_generation_failed', $e->getMessage());
        }
    }
}
