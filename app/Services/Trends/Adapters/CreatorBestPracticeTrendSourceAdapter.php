<?php

namespace App\Services\Trends\Adapters;

use App\Services\Trends\TrendSourceAdapter;

class CreatorBestPracticeTrendSourceAdapter implements TrendSourceAdapter
{
    public function __construct(
        private readonly ConfigTrendSourceAdapter $configTrendSourceAdapter
    ) {
    }

    public function collect(array $context = []): array
    {
        return $this->configTrendSourceAdapter->normalizeSignals(
            (array) config('trends.creator_best_practice_signals', []),
            'creator_best_practice_signal',
            'creator-note'
        );
    }
}
