<?php

namespace App\Support;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class GenerationExecution
{
    public static function shouldRunSync(): bool
    {
        return app()->environment('local');
    }

    public static function shouldDispatchAfterResponse(): bool
    {
        return false;
    }

    public static function shouldKickBackgroundQueueWorker(): bool
    {
        return !self::shouldRunSync()
            && !app()->runningInConsole()
            && !app()->runningUnitTests()
            && (bool) config('generation.queue_auto_kick', true);
    }

    public static function shouldShowProgressPage(): bool
    {
        return !app()->runningUnitTests() && !self::shouldRunSync();
    }

    public static function dispatchContentItem(int $contentItemId): void
    {
        if (self::shouldRunSync()) {
            GenerateAiForContentItem::dispatchSync($contentItemId);
            return;
        }

        if (self::shouldKickBackgroundQueueWorker()) {
            if (self::ensureBackgroundQueueWorker($contentItemId)) {
                return;
            }
        }

        GenerateAiForContentItem::dispatch($contentItemId);
    }

    public static function ensureBackgroundQueueWorker(?int $contentItemId = null): bool
    {
        if (!self::shouldKickBackgroundQueueWorker()) {
            return false;
        }

        return self::kickBackgroundQueueWorker($contentItemId);
    }

    public static function primeQueuedState(ContentItem $item): void
    {
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $generationMonitor = (array) data_get($meta, 'generation_monitor', []);
        $generationMonitor['queue_reference_at'] = now()->toDateTimeString();
        unset(
            $generationMonitor['last_recovery_attempt_at'],
            $generationMonitor['stale_status'],
            $generationMonitor['stale_reference_at'],
            $generationMonitor['marked_error_at']
        );
        $meta['generation_monitor'] = $generationMonitor;

        $generationAudit = (array) data_get($meta, 'generation_audit', []);
        $generationAudit['latest_status'] = 'queued';
        $generationAudit['tracked_at'] = now()->toDateTimeString();
        $generationAudit['latest_run_id'] = null;
        $meta['generation_audit'] = $generationAudit;

        $item->ai_status = 'queued';
        $item->ai_error = null;
        $item->ai_generated_at = null;
        $item->ai_meta = $meta;
    }

    public static function buildBackgroundQueueWorkerCommand(
        ?string $phpBinary = null,
        ?string $artisanPath = null,
        ?string $connection = null,
        ?int $contentItemId = null
    ): string {
        $phpBinary = trim((string) ($phpBinary ?: self::resolvePhpBinary()));
        $artisanPath = trim((string) ($artisanPath ?: self::resolveQueueWorkerEntrypoint()));
        $arguments = [
            'ai:drain-generation-queue',
            '--once',
            '--no-interaction',
        ];
        if (($contentItemId ?? 0) > 0) {
            $arguments[] = '--content-item-id=' . (int) $contentItemId;
        }

        if (self::shouldUseArtisanWrapper($artisanPath)) {
            $command = implode(' ', array_map('escapeshellarg', [
                self::resolveShellBinary(),
                $artisanPath,
                ...$arguments,
            ]));
        } else {
            $command = implode(' ', array_map('escapeshellarg', [
                $phpBinary,
                $artisanPath,
                ...$arguments,
            ]));
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'start /B "" ' . $command;
        }

        return 'nohup ' . $command . ' > /dev/null 2>&1 &';
    }

    public static function runContentItemDirect(int $contentItemId, ?string $runKey = null, bool $force = false): int
    {
        $item = ContentItem::query()->find($contentItemId);
        if (!$item) {
            Log::warning('GenerationExecution direct run skipped because content item does not exist', [
                'content_item_id' => $contentItemId,
            ]);

            return 0;
        }

        $status = strtolower(trim((string) ($item->ai_status ?? '')));
        if (!$force && !in_array($status, ['queued', 'pending'], true)) {
            Log::info('GenerationExecution direct run skipped because content item is not queued', [
                'content_item_id' => $item->id,
                'ai_status' => $status,
            ]);

            return 0;
        }

        $lockKey = 'generation:content-item-running:' . (int) $item->id;
        if (!Cache::add($lockKey, now()->timestamp, 90)) {
            Log::info('GenerationExecution direct run skipped because content item is already starting', [
                'content_item_id' => $item->id,
                'ai_status' => $status,
            ]);

            return 0;
        }

        $job = new GenerateAiForContentItem((int) $item->id, $runKey);

        try {
            @set_time_limit(0);
            app()->call([$job, 'handle']);

            return 0;
        } catch (Throwable $e) {
            $job->failed($e);

            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }

    private static function kickBackgroundQueueWorker(?int $contentItemId = null): bool
    {
        $lockKey = 'generation:processor-kick:' . (($contentItemId ?? 0) > 0 ? (int) $contentItemId : 'global');
        if (!Cache::add($lockKey, now()->timestamp, 15)) {
            return true;
        }

        try {
            $command = self::buildBackgroundQueueWorkerCommand(
                phpBinary: self::resolvePhpBinary(),
                artisanPath: self::resolveQueueWorkerEntrypoint(),
                connection: null,
                contentItemId: $contentItemId
            );

            $spawnMethod = self::spawnDetachedCommand($command);
            if ($spawnMethod === null) {
                throw new \RuntimeException('No detached execution function is available on this PHP installation.');
            }

            Log::info('GenerationExecution kicked background generation processor', [
                'content_item_id' => $contentItemId,
                'command' => $command,
                'spawn_method' => $spawnMethod,
            ]);

            return true;
        } catch (Throwable $e) {
            Cache::forget($lockKey);

            Log::warning('GenerationExecution failed to kick background generation processor', [
                'content_item_id' => $contentItemId,
                'error' => $e->getMessage(),
                'available_spawn_methods' => self::availableSpawnMethods(),
            ]);

            return false;
        }
    }

    private static function spawnDetachedCommand(string $command): ?string
    {
        if (self::isFunctionEnabled('proc_open')) {
            $process = Process::fromShellCommandline($command, base_path());
            $process->disableOutput();
            $process->start();

            return 'proc_open';
        }

        if (self::isFunctionEnabled('exec')) {
            @exec($command);

            return 'exec';
        }

        if (self::isFunctionEnabled('shell_exec')) {
            @shell_exec($command);

            return 'shell_exec';
        }

        if (self::isFunctionEnabled('popen')) {
            $handle = @popen($command, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
            }

            return 'popen';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function availableSpawnMethods(): array
    {
        $methods = [];

        foreach (['proc_open', 'exec', 'shell_exec', 'popen'] as $function) {
            if (self::isFunctionEnabled($function)) {
                $methods[] = $function;
            }
        }

        return $methods;
    }

    private static function isFunctionEnabled(string $function): bool
    {
        $function = trim($function);
        if ($function === '' || !function_exists($function)) {
            return false;
        }

        $disabled = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            explode(',', (string) ini_get('disable_functions'))
        )));

        return !in_array($function, $disabled, true);
    }

    private static function resolvePhpBinary(): string
    {
        $finder = new PhpExecutableFinder();
        $resolved = $finder->find(false);
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }

        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && trim(PHP_BINARY) !== '') {
            return trim(PHP_BINARY);
        }

        return 'php';
    }

    private static function resolveQueueWorkerEntrypoint(): string
    {
        $wrapper = base_path('artisan-egpcs');
        if (PHP_OS_FAMILY !== 'Windows' && is_file($wrapper)) {
            return $wrapper;
        }

        return base_path('artisan');
    }

    private static function shouldUseArtisanWrapper(string $artisanPath): bool
    {
        if ($artisanPath === '' || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return is_file($artisanPath) && basename($artisanPath) === 'artisan-egpcs';
    }

    private static function resolveShellBinary(): string
    {
        foreach (['/bin/bash', '/usr/bin/bash', 'bash'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }
}
