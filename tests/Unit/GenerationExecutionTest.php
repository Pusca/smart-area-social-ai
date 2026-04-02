<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Support\GenerationExecution;
use Tests\TestCase;

class GenerationExecutionTest extends TestCase
{
    public function test_it_never_uses_after_response_dispatch_for_ai_generation(): void
    {
        config()->set('generation.force_sync', true);

        $this->assertFalse(GenerationExecution::shouldDispatchAfterResponse());
    }

    public function test_it_does_not_kick_background_worker_inside_unit_tests(): void
    {
        config()->set('generation.force_sync', true);

        $this->assertFalse(GenerationExecution::shouldKickBackgroundQueueWorker());
    }

    public function test_generate_ai_job_has_extended_timeout_and_fails_on_timeout(): void
    {
        $job = new GenerateAiForContentItem(123);

        $this->assertSame(1200, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(1, $job->tries);
    }

    public function test_it_builds_a_detached_background_worker_command(): void
    {
        $command = GenerationExecution::buildBackgroundQueueWorkerCommand(
            phpBinary: '/usr/bin/php',
            artisanPath: '/var/www/app/artisan',
            connection: 'database'
        );

        $this->assertStringContainsString('queue:work', $command);
        $this->assertStringContainsString('database', $command);
        $this->assertStringContainsString('--once', $command);
        $this->assertStringContainsString('--timeout=1200', $command);
    }

    public function test_it_prefers_artisan_wrapper_for_background_worker_when_available(): void
    {
        $command = GenerationExecution::buildBackgroundQueueWorkerCommand(
            phpBinary: '/usr/bin/php',
            artisanPath: '/var/www/app/artisan-egpcs',
            connection: 'database'
        );

        $this->assertStringContainsString('artisan-egpcs', $command);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString('/usr/bin/php', $command);
        } else {
            $this->assertStringNotContainsString('/usr/bin/php', $command);
            $this->assertStringContainsString('bash', $command);
        }
        $this->assertStringContainsString('queue:work', $command);
    }

    public function test_database_queue_retry_after_is_longer_than_ai_generation_timeout(): void
    {
        $job = new GenerateAiForContentItem(123);

        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.database.retry_after')
        );
    }

    public function test_prime_queued_state_resets_stale_generation_markers(): void
    {
        $item = new ContentItem([
            'ai_status' => 'error',
            'ai_error' => 'QUEUE_STALE_TIMEOUT',
            'ai_generated_at' => now(),
            'ai_meta' => [
                'generation_monitor' => [
                    'queue_reference_at' => now()->subHour()->toDateTimeString(),
                    'last_recovery_attempt_at' => now()->subMinutes(30)->toDateTimeString(),
                    'stale_status' => 'queued',
                    'marked_error_at' => now()->subMinutes(25)->toDateTimeString(),
                ],
                'generation_audit' => [
                    'latest_run_id' => 99,
                    'latest_status' => 'failed',
                ],
            ],
        ]);

        GenerationExecution::primeQueuedState($item);

        $this->assertSame('queued', $item->ai_status);
        $this->assertNull($item->ai_error);
        $this->assertNull($item->ai_generated_at);
        $this->assertSame('queued', data_get($item->ai_meta, 'generation_audit.latest_status'));
        $this->assertNull(data_get($item->ai_meta, 'generation_audit.latest_run_id'));
        $this->assertNotEmpty((string) data_get($item->ai_meta, 'generation_monitor.queue_reference_at'));
        $this->assertNull(data_get($item->ai_meta, 'generation_monitor.last_recovery_attempt_at'));
        $this->assertNull(data_get($item->ai_meta, 'generation_monitor.stale_status'));
        $this->assertNull(data_get($item->ai_meta, 'generation_monitor.marked_error_at'));
    }
}
