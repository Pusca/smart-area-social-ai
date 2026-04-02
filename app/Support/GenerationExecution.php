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
        $connection = trim((string) config('queue.default', 'database'));

        return !self::shouldRunSync()
            && !app()->runningInConsole()
            && !app()->runningUnitTests()
            && $connection !== ''
            && $connection !== 'sync'
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

        GenerateAiForContentItem::dispatch($contentItemId);

        if (self::shouldKickBackgroundQueueWorker()) {
            self::ensureBackgroundQueueWorker();
        }
    }

    public static function ensureBackgroundQueueWorker(): bool
    {
        if (!self::shouldKickBackgroundQueueWorker()) {
            return false;
        }

        self::kickBackgroundQueueWorker();

        return true;
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
        ?string $connection = null
    ): string {
        $phpBinary = trim((string) ($phpBinary ?: self::resolvePhpBinary()));
        $artisanPath = trim((string) ($artisanPath ?: self::resolveQueueWorkerEntrypoint()));
        $connection = trim((string) ($connection ?: config('queue.default', 'database')));
        $arguments = [
            'queue:work',
            $connection,
            '--once',
            '--timeout=1200',
            '--tries=1',
            '--sleep=1',
            '--no-interaction',
        ];

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

    private static function kickBackgroundQueueWorker(): void
    {
        $connection = trim((string) config('queue.default', 'database'));
        if ($connection === '' || $connection === 'sync') {
            Log::warning('GenerationExecution skipped background worker kick because queue connection is not async', [
                'queue_connection' => $connection,
            ]);

            return;
        }

        $lockKey = 'generation:queue-worker-kick:' . $connection;
        if (!Cache::add($lockKey, now()->timestamp, 15)) {
            return;
        }

        try {
            $command = self::buildBackgroundQueueWorkerCommand(
                phpBinary: self::resolvePhpBinary(),
                artisanPath: self::resolveQueueWorkerEntrypoint(),
                connection: $connection
            );

            $spawnMethod = self::spawnDetachedCommand($command);
            if ($spawnMethod === null) {
                throw new \RuntimeException('No detached execution function is available on this PHP installation.');
            }

            Log::info('GenerationExecution kicked background queue worker', [
                'queue_connection' => $connection,
                'command' => $command,
                'spawn_method' => $spawnMethod,
            ]);
        } catch (Throwable $e) {
            Cache::forget($lockKey);

            Log::warning('GenerationExecution failed to kick background queue worker', [
                'queue_connection' => $connection,
                'error' => $e->getMessage(),
                'available_spawn_methods' => self::availableSpawnMethods(),
            ]);
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
