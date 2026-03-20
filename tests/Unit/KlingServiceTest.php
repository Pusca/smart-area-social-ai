<?php

namespace Tests\Unit;

use App\Services\KlingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KlingServiceTest extends TestCase
{
    public function test_it_creates_a_kling_multi_image_job_with_expected_payload(): void
    {
        config()->set('kling.access_key', 'kling-ak');
        config()->set('kling.secret_key', 'kling-sk');
        config()->set('kling.base_url', 'https://api-singapore.klingai.com');

        Http::fake([
            'https://api-singapore.klingai.com/v1/videos/multi-image2video' => function (Request $request) {
                $payload = $request->data();

                $this->assertSame('Bearer', strtok((string) $request->header('Authorization')[0], ' '));
                $this->assertSame('kling-v2', $payload['model_name']);
                $this->assertArrayNotHasKey('mode', $payload);
                $this->assertSame(10, $payload['duration']);
                $this->assertSame('9:16', $payload['aspect_ratio']);
                $this->assertSame('identity drift', $payload['negative_prompt']);
                $this->assertCount(2, $payload['image_list']);
                $this->assertSame('https://cdn.example.com/front.jpg', $payload['image_list'][0]['image']);

                return Http::response([
                    'data' => [
                        'task_id' => 'kling-task-123',
                    ],
                ], 200);
            },
        ]);

        $service = app(KlingService::class);
        $result = $service->createVideoJob(
            'Create a premium social reel with the same real subject across shots.',
            ['https://cdn.example.com/front.jpg', 'https://cdn.example.com/left.jpg'],
            [
                'request_mode' => 'multi-image',
                'model' => 'kling-v2-6',
                'seconds' => 9,
                'size' => '720x1280',
                'negative_prompt' => 'identity drift',
            ]
        );

        $this->assertSame('kling-task-123', $result['id']);
        $this->assertSame('multi-image', $result['request_mode']);
        $this->assertSame(2, $result['request_summary']['reference_count']);
    }

    public function test_it_retries_kling_multi_image_with_supported_model_when_configured_one_is_rejected(): void
    {
        config()->set('kling.access_key', 'kling-ak');
        config()->set('kling.secret_key', 'kling-sk');
        config()->set('kling.base_url', 'https://kling-proxy.internal');

        $attempt = 0;
        Http::fake([
            'https://kling-proxy.internal/v1/videos/multi-image2video' => function (Request $request) use (&$attempt) {
                $attempt++;
                $payload = $request->data();

                if ($attempt === 1) {
                    $this->assertSame('kling-v1-6', $payload['model_name']);

                    return Http::response([
                        'code' => 1201,
                        'message' => 'model is not supported',
                    ], 400);
                }

                $this->assertSame('kling-v2', $payload['model_name']);
                $this->assertArrayNotHasKey('mode', $payload);

                return Http::response([
                    'data' => [
                        'task_id' => 'kling-task-456',
                    ],
                ], 200);
            },
        ]);

        $service = app(KlingService::class);
        $result = $service->createVideoJob(
            'Create a premium social reel with the same real subject across shots.',
            ['https://cdn.example.com/front.jpg', 'https://cdn.example.com/left.jpg'],
            [
                'request_mode' => 'multi-image',
                'model' => 'kling-v1-6',
                'seconds' => 5,
            ]
        );

        $this->assertSame('kling-task-456', $result['id']);
        $this->assertSame(2, $attempt);
    }

    public function test_it_retrieves_kling_job_from_specific_endpoint_when_generic_one_fails(): void
    {
        config()->set('kling.access_key', 'kling-ak');
        config()->set('kling.secret_key', 'kling-sk');
        config()->set('kling.base_url', 'https://api-singapore.klingai.com');
        config()->set('kling.retrieve_endpoint', '/v1/videos/{id}');
        config()->set('kling.image_retrieve_endpoint', '/v1/videos/image2video/{id}');

        Http::fake([
            'https://api-singapore.klingai.com/v1/videos/kling-task-123' => Http::response([
                'message' => 'Not found',
            ], 404),
            'https://api-singapore.klingai.com/v1/videos/image2video/kling-task-123' => Http::response([
                'data' => [
                    'task_status' => 'succeed',
                    'task_result' => [
                        'videos' => [
                            ['url' => 'https://cdn.example.com/result.mp4'],
                        ],
                    ],
                ],
            ], 200),
            'https://cdn.example.com/result.mp4' => Http::response('video-bytes', 200),
        ]);

        $service = app(KlingService::class);
        $job = $service->waitForVideoCompletion('kling-task-123', 'image');

        $this->assertSame('succeed', data_get($job, 'data.task_status'));
        $this->assertSame('video-bytes', $service->downloadVideoContent($job));
    }

    public function test_it_retries_kling_image_with_older_models_when_v2_1_is_rejected(): void
    {
        config()->set('kling.access_key', 'kling-ak');
        config()->set('kling.secret_key', 'kling-sk');
        config()->set('kling.base_url', 'https://api-singapore.klingai.com');

        $attempt = 0;
        Http::fake([
            'https://api-singapore.klingai.com/v1/videos/image2video' => function (Request $request) use (&$attempt) {
                $attempt++;
                $payload = $request->data();

                if ($attempt === 1) {
                    $this->assertSame('kling-v2-1', $payload['model_name']);

                    return Http::response([
                        'code' => 1201,
                        'message' => 'model is not supported',
                    ], 400);
                }

                if ($attempt === 2) {
                    $this->assertSame('kling-v2', $payload['model_name']);
                    $this->assertArrayNotHasKey('mode', $payload);

                    return Http::response([
                        'code' => 1201,
                        'message' => 'model is not supported',
                    ], 400);
                }

                $this->assertSame('kling-v1-6', $payload['model_name']);
                $this->assertSame('pro', $payload['mode']);

                return Http::response([
                    'data' => [
                        'task_id' => 'kling-task-legacy-image',
                    ],
                ], 200);
            },
        ]);

        $service = app(KlingService::class);
        $result = $service->createVideoJob(
            'Create a premium social reel with the same real subject.',
            ['https://cdn.example.com/front.jpg'],
            [
                'request_mode' => 'image',
                'model' => 'kling-v2-1',
                'seconds' => 5,
            ]
        );

        $this->assertSame('kling-task-legacy-image', $result['id']);
        $this->assertSame('image', $result['request_mode']);
        $this->assertSame('kling-v1-6', $result['request_summary']['model']);
        $this->assertTrue($result['request_summary']['fallback_applied']);
        $this->assertSame(3, $attempt);
    }

    public function test_it_falls_back_to_single_image_when_multi_image_models_are_rejected(): void
    {
        config()->set('kling.access_key', 'kling-ak');
        config()->set('kling.secret_key', 'kling-sk');
        config()->set('kling.base_url', 'https://api-singapore.klingai.com');

        $multiImageAttempt = 0;
        $imageAttempt = 0;
        Http::fake([
            'https://api-singapore.klingai.com/v1/videos/multi-image2video' => function (Request $request) use (&$multiImageAttempt) {
                $multiImageAttempt++;
                $payload = $request->data();

                $this->assertSame('kling-v2', $payload['model_name']);
                $this->assertArrayNotHasKey('mode', $payload);
                $this->assertCount(2, $payload['image_list']);

                return Http::response([
                    'code' => 1201,
                    'message' => 'model is not supported',
                ], 400);
            },
            'https://api-singapore.klingai.com/v1/videos/image2video' => function (Request $request) use (&$imageAttempt) {
                $imageAttempt++;
                $payload = $request->data();

                $this->assertSame('kling-v2-1', $payload['model_name']);
                $this->assertSame('https://cdn.example.com/front.jpg', $payload['image']);

                return Http::response([
                    'data' => [
                        'task_id' => 'kling-task-single-image',
                    ],
                ], 200);
            },
        ]);

        $service = app(KlingService::class);
        $result = $service->createVideoJob(
            'Create a premium social reel with the same real subject across shots.',
            ['https://cdn.example.com/front.jpg', 'https://cdn.example.com/left.jpg'],
            [
                'request_mode' => 'multi-image',
                'model' => 'kling-v2-1',
                'seconds' => 5,
            ]
        );

        $this->assertSame('kling-task-single-image', $result['id']);
        $this->assertSame('image', $result['request_mode']);
        $this->assertSame('kling-v2-1', $result['request_summary']['model']);
        $this->assertSame(1, $result['request_summary']['reference_count']);
        $this->assertTrue($result['request_summary']['fallback_applied']);
        $this->assertSame(1, $multiImageAttempt);
        $this->assertSame(1, $imageAttempt);
    }
}
