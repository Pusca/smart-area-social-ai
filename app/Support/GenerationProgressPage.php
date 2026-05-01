<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\ContentPlan;

class GenerationProgressPage
{
    public static function contextForPlan(?ContentPlan $plan): string
    {
        $mode = trim((string) data_get($plan?->settings, 'mode', ''));

        return match ($mode) {
            'onboarding_quickstart_demo' => 'quickstart',
            'single_manual' => 'single',
            default => 'wizard',
        };
    }

    public static function contextForContentItem(ContentItem $item): string
    {
        $source = trim((string) data_get($item->ai_meta, 'source', ''));
        if ($source === 'manual_single_content') {
            return 'single';
        }

        $mode = trim((string) data_get($item->plan?->settings, 'mode', ''));
        if ($mode === '') {
            $mode = trim((string) data_get($item->ai_meta, 'plan.mode', ''));
        }

        return match ($mode) {
            'onboarding_quickstart_demo' => 'quickstart',
            'single_manual' => 'single',
            default => 'wizard',
        };
    }
}
