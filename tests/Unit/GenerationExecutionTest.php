<?php

namespace Tests\Unit;

use App\Jobs\GenerateAiForContentItem;
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

    public function test_database_queue_retry_after_is_longer_than_ai_generation_timeout(): void
    {
        $job = new GenerateAiForContentItem(123);

        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.database.retry_after')
        );
    }
}
