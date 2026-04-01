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

    public function test_generate_ai_job_has_extended_timeout_and_fails_on_timeout(): void
    {
        $job = new GenerateAiForContentItem(123);

        $this->assertSame(1200, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(1, $job->tries);
    }
}
