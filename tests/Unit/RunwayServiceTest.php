<?php

namespace Tests\Unit;

use App\Services\RunwayService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RunwayServiceTest extends TestCase
{
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
