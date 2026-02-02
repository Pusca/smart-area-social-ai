<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateContentItemAiJob;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Http\Request;

class AiGenerateController extends Controller
{
    public function generateOne(Request $request, ContentItem $contentItem)
    {
        // sicurezza tenant
        abort_unless($contentItem->tenant_id === auth()->user()->tenant_id, 403);

        GenerateContentItemAiJob::dispatch($contentItem->id);

        return back()->with('status', 'Generazione AI avviata per: '.$contentItem->title);
    }

    public function generatePlan(Request $request, ContentPlan $contentPlan)
    {
        abort_unless($contentPlan->tenant_id === auth()->user()->tenant_id, 403);

        $items = ContentItem::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('content_plan_id', $contentPlan->id)
            ->get();

        foreach ($items as $item) {
            GenerateContentItemAiJob::dispatch($item->id);
        }

        return back()->with('status', 'Generazione AI avviata per '.count($items).' contenuti.');
    }
}
