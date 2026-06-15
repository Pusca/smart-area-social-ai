<?php

namespace App\Jobs;

use App\Models\CanvaExportJob as CanvaExportJobRecord;
use App\Services\Canva\CanvaExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollCanvaExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public int $canvaExportJobId,
        public int $pollCount = 0
    ) {
        $this->onQueue((string) config('canva.queues.export_poll', 'canva-export'));
    }

    public function handle(CanvaExportService $exportService): void
    {
        $job = CanvaExportJobRecord::query()->find($this->canvaExportJobId);
        if (!$job || trim((string) $job->external_job_id) === '') {
            return;
        }

        $job = $exportService->refreshExportJob($job);

        if ($job->status === 'in_progress' && $this->pollCount < max(1, (int) config('canva.job_max_polls', 12))) {
            self::dispatch($this->canvaExportJobId, $this->pollCount + 1)
                ->delay(now()->addSeconds((int) config('canva.job_poll_delay_seconds', 12)));
        }
    }
}
