<?php

namespace App\Services\Trends\Adapters;

use App\Services\Trends\TrendSourceAdapter;

class CuratedTrendSourceAdapter implements TrendSourceAdapter
{
    public function __construct(
        private readonly ConfigTrendSourceAdapter $configTrendSourceAdapter
    ) {
    }

    public function collect(array $context = []): array
    {
        return $this->configTrendSourceAdapter->normalizeSignals(
            (array) config('trends.curated_manual_signals', []),
            'curated_manual_signal',
            'curated'
        );
    }
}
