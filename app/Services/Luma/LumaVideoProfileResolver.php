<?php

namespace App\Services\Luma;

class LumaVideoProfileResolver
{
    /**
     * Map of supported durations (seconds) → Luma model + duration string.
     * Ordered descending so fallback chain works by iterating from requested down.
     */
    private const PROFILES = [
        15 => ['model' => 'ray-flash-2', 'duration' => '15s'],
        10 => ['model' => 'ray-2',       'duration' => '10s'],
        5  => ['model' => 'ray-2',       'duration' => '5s'],
    ];

    /**
     * Normalize any requested duration (seconds) to the nearest supported value.
     * Fallback chain: requested → next lower → 5s.
     *
     * @return array{model: string, duration: string}
     */
    public function resolve(int $requestedSeconds): array
    {
        // Exact match
        if (isset(self::PROFILES[$requestedSeconds])) {
            return self::PROFILES[$requestedSeconds];
        }

        // Find the nearest supported value ≤ requested
        $supported = array_keys(self::PROFILES);
        rsort($supported);
        foreach ($supported as $secs) {
            if ($secs <= $requestedSeconds) {
                return self::PROFILES[$secs];
            }
        }

        // Below all supported values → use minimum
        return self::PROFILES[5];
    }

    /**
     * Fallback chain starting from the requested duration, descending to minimum.
     *
     * @return list<array{model: string, duration: string}>
     */
    public function fallbackChain(int $requestedSeconds): array
    {
        $chain = [];
        $supported = array_keys(self::PROFILES);
        rsort($supported);

        $started = false;
        foreach ($supported as $secs) {
            if ($secs <= $requestedSeconds) {
                $started = true;
            }
            if ($started) {
                $chain[] = self::PROFILES[$secs];
            }
        }

        if (empty($chain)) {
            $chain[] = self::PROFILES[5];
        }

        return $chain;
    }

    /**
     * Parse a duration string like "5s", "10s", "15s" or a plain integer to seconds.
     */
    public function parseDurationToSeconds(mixed $duration): int
    {
        if (is_int($duration)) {
            return $duration;
        }
        $str = trim((string) $duration);
        if (str_ends_with($str, 's')) {
            return (int) rtrim($str, 's');
        }
        return (int) $str;
    }
}
