<?php

namespace App\Services\Feedback;

class TenantFeedbackMemoryService
{
    public function __construct(
        private readonly TenantFeedbackSignalSynthesisService $signalSynthesis
    ) {
    }

    public function buildForTenant(int $tenantId, int $limit = 40): array
    {
        return $this->signalSynthesis->buildForTenant($tenantId, $limit);
    }
}
