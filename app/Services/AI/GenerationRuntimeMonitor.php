<?php

namespace App\Services\AI;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\GenerationRun;
use App\Services\GenerationAuditService;
use App\Support\GenerationExecution;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class GenerationRuntimeMonitor
{
    public function __construct(
        private readonly GenerationAuditService $generationAudit
    ) {
    }

    public function reconcileContentItem(ContentItem $item): bool
    {
        $status = strtolower(trim((string) ($item->ai_status ?? '')));
        if (!in_array($status, ['queued', 'pending'], true)) {
            return false;
        }

        $recoveryTriggered = false;
        if ($status === 'queued') {
            $recoveryTriggered = $this->maybeRecoverQueuedItem($item);
        }

        if ($this->maybeFailStaleItem($item, $status)) {
            return true;
        }

        return $recoveryTriggered;
    }

    public function reconcilePlan(ContentPlan $plan): void
    {
        ContentItem::query()
            ->where('content_plan_id', (int) $plan->id)
            ->whereIn('ai_status', ['queued', 'pending'])
            ->orderBy('id')
            ->get()
            ->each(fn (ContentItem $item) => $this->reconcileContentItem($item));
    }

    private function maybeRecoverQueuedItem(ContentItem $item): bool
    {
        $referenceAt = $this->queuedReferenceAt($item);
        if (!$referenceAt) {
            return false;
        }

        if ($referenceAt->diffInSeconds(now()) < $this->queuedNudgeAfterSeconds()) {
            return false;
        }

        if (!$this->hasAsyncQueueConnection()) {
            return false;
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $monitorMeta = (array) data_get($meta, 'generation_monitor', []);
        $lastRecoveryAttemptAt = $this->parseTimestamp(data_get($monitorMeta, 'last_recovery_attempt_at'));
        if ($lastRecoveryAttemptAt && $lastRecoveryAttemptAt->diffInSeconds(now()) < $this->queuedRecoveryRetrySeconds()) {
            return false;
        }

        $recoveryTriggered = false;
        if (GenerationExecution::shouldKickBackgroundQueueWorker()) {
            $recoveryTriggered = GenerationExecution::ensureBackgroundQueueWorker((int) $item->id) || $recoveryTriggered;
        }
        if ($this->shouldUseHttpDrainFallback()) {
            $recoveryTriggered = $this->drainQueueOnceViaHttp($item) || $recoveryTriggered;
        }
        if (!$recoveryTriggered) {
            return false;
        }

        $monitorMeta['queue_reference_at'] = $referenceAt->toDateTimeString();
        $monitorMeta['last_recovery_attempt_at'] = now()->toDateTimeString();
        $meta['generation_monitor'] = $monitorMeta;
        $item->ai_meta = $meta;
        $item->save();

        return true;
    }

    private function maybeFailStaleItem(ContentItem $item, string $status): bool
    {
        $status = strtolower(trim($status));
        $referenceAt = $status === 'queued'
            ? $this->queuedReferenceAt($item)
            : $this->pendingReferenceAt($item);
        if (!$referenceAt) {
            return false;
        }

        $threshold = $status === 'queued'
            ? $this->queuedStaleAfterSeconds() + $this->queuedRecoveryGraceSeconds()
            : $this->pendingStaleAfterSeconds();
        if ($referenceAt->diffInSeconds(now()) < $threshold) {
            return false;
        }

        $message = $status === 'queued'
            ? 'QUEUE_STALE_TIMEOUT: la generazione e rimasta in coda troppo a lungo e non e partita. Controlla il worker queue e rigenera.'
            : 'JOB_STALE_TIMEOUT: la generazione AI ha superato il tempo massimo ed e stata marcata come non riuscita. Controlla il worker queue e rigenera.';
        $job = new GenerateAiForContentItem((int) $item->id);

        $run = $this->latestActiveRun($item);
        if ($run) {
            $this->generationAudit->failRun($run, $message, [
                'last_error' => $message,
                'effective_output' => $job->buildRunEffectiveOutput($item),
                'result_summary' => $job->buildRunResultSummary($item),
                'overlay_meta' => (array) data_get($item->ai_meta, 'overlay_meta', []),
                'storyboard_meta' => (array) data_get($item->ai_meta, 'storyboard_meta', []),
                'version_meta' => $job->generationVersionMeta(
                    is_array($item->ai_meta) ? $item->ai_meta : []
                ),
            ]);
        }

        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['generation_monitor'] = array_merge(
            (array) data_get($meta, 'generation_monitor', []),
            [
                'stale_status' => $status,
                'stale_reference_at' => $referenceAt->toDateTimeString(),
                'marked_error_at' => now()->toDateTimeString(),
            ]
        );
        $meta['generation_audit'] = array_merge(
            (array) data_get($meta, 'generation_audit', []),
            [
                'latest_run_id' => $run?->id,
                'latest_status' => 'failed',
                'tracked_at' => now()->toDateTimeString(),
            ]
        );

        $item->ai_status = 'error';
        $item->ai_error = $message;
        $item->ai_generated_at = now();
        $item->ai_meta = $meta;
        $item->save();

        return true;
    }

    private function latestActiveRun(ContentItem $item): ?GenerationRun
    {
        return $item->generationRuns()
            ->whereIn('status', ['running', 'pending'])
            ->latest('id')
            ->first();
    }

    private function queuedReferenceAt(ContentItem $item): ?CarbonInterface
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $stored = $this->parseTimestamp(data_get($meta, 'generation_monitor.queue_reference_at'));
        if ($stored) {
            return $stored;
        }

        return $item->updated_at ?: $item->created_at;
    }

    private function pendingReferenceAt(ContentItem $item): ?CarbonInterface
    {
        $run = $this->latestActiveRun($item);
        if ($run?->started_at) {
            return $run->started_at;
        }

        return $item->updated_at ?: $item->created_at;
    }

    private function parseTimestamp(mixed $value): ?CarbonInterface
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function queuedStaleAfterSeconds(): int
    {
        return max(180, (int) config('generation.queued_stale_after_seconds', 480));
    }

    private function queuedNudgeAfterSeconds(): int
    {
        return max(5, min(
            $this->queuedStaleAfterSeconds(),
            (int) config('generation.queued_nudge_after_seconds', 15)
        ));
    }

    private function queuedRecoveryRetrySeconds(): int
    {
        return max(10, min(
            $this->queuedRecoveryGraceSeconds(),
            (int) config('generation.queued_recovery_retry_seconds', 45)
        ));
    }

    private function queuedRecoveryGraceSeconds(): int
    {
        return max(60, (int) config('generation.queued_recovery_grace_seconds', 150));
    }

    private function pendingStaleAfterSeconds(): int
    {
        $configured = max(900, (int) config('generation.pending_stale_after_seconds', 1800));
        $queueConnection = trim((string) config('queue.default', 'database'));
        $retryAfter = (int) config('queue.connections.' . $queueConnection . '.retry_after', 0);
        $jobTimeout = (int) (new GenerateAiForContentItem(0))->timeout;

        return max($configured, $retryAfter + 120, $jobTimeout + 180);
    }

    private function hasAsyncQueueConnection(): bool
    {
        $queueConnection = trim((string) config('queue.default', 'database'));

        return $queueConnection !== '' && $queueConnection !== 'sync';
    }

    protected function shouldUseHttpDrainFallback(): bool
    {
        return !$this->isConsoleContext()
            && $this->hasAsyncQueueConnection()
            && (bool) config('generation.queue_http_drain_fallback', true);
    }

    protected function isConsoleContext(): bool
    {
        return app()->runningInConsole() || app()->runningUnitTests();
    }

    protected function drainQueueOnceViaHttp(ContentItem $item): bool
    {
        $lockKey = 'generation-runtime-monitor:http-drain:' . (int) $item->id;
        if (!Cache::add($lockKey, now()->timestamp, 120)) {
            return true;
        }

        try {
            return GenerationExecution::ensureBackgroundQueueWorker((int) $item->id);
        } catch (\Throwable) {
            return false;
        } finally {
            Cache::forget($lockKey);
        }
    }
}
