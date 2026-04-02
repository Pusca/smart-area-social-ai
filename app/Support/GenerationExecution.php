<?php

namespace App\Support;

use App\Jobs\GenerateAiForContentItem;
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

    public static function buildBackgroundQueueWorkerCommand(
        ?string $phpBinary = null,
        ?string $artisanPath = null,
        ?string $connection = null
    ): string {
        $phpBinary = trim((string) ($phpBinary ?: self::resolvePhpBinary()));
        $artisanPath = trim((string) ($artisanPath ?: base_path('artisan')));
        $connection = trim((string) ($connection ?: config('queue.default', 'database')));
        $command = implode(' ', array_map('escapeshellarg', [
            $phpBinary,
            $artisanPath,
            'queue:work',
            $connection,
            '--once',
            '--timeout=1200',
            '--tries=1',
            '--sleep=1',
            '--no-interaction',
        ]));

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
                artisanPath: base_path('artisan'),
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
}
