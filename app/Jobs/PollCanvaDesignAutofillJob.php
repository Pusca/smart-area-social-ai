<?php

namespace App\Jobs;

use App\Models\CanvaDesign;
use App\Services\Canva\CanvaDesignGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollCanvaDesignAutofillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public int $canvaDesignId,
        public int $pollCount = 0
    ) {
        $this->onQueue((string) config('canva.queues.autofill_poll', 'canva-autofill'));
    }

    public function handle(CanvaDesignGenerationService $designGenerationService): void
    {
        $design = CanvaDesign::query()->find($this->canvaDesignId);
        if (!$design || $design->source_mode !== 'autofill') {
            return;
        }

        $design = $designGenerationService->refreshAutofillStatus($design);

        if ($design->status === 'in_progress' && $this->pollCount < max(1, (int) config('canva.job_max_polls', 12))) {
            self::dispatch($this->canvaDesignId, $this->pollCount + 1)
                ->delay(now()->addSeconds((int) config('canva.job_poll_delay_seconds', 12)));
        }
    }
}
