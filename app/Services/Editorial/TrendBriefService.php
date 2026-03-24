<?php

namespace App\Services\Editorial;

use App\Models\TrendBrief;
use App\Services\Trends\TrendOpportunitySynthesisService;
use Illuminate\Support\Carbon;

class TrendBriefService
{
    public function __construct(
        private readonly TrendOpportunitySynthesisService $trendOpportunitySynthesis
    ) {
    }

    public function getBriefForTenant(int $tenantId): array
    {
        $snapshot = $this->trendOpportunitySynthesis->buildPlanningBrief($tenantId);

        TrendBrief::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'snapshot' => $snapshot,
                'fetched_at' => Carbon::now(),
            ]
        );

        return $snapshot;
    }
}