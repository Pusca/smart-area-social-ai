<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Jobs\GenerateAiImageForContentItem;
use App\Models\ContentItem;
use App\Models\ContentPlan;

class AiGenerateController extends Controller
{
    public function generateOne(ContentItem $contentItem)
    {
        $contentItem->ai_status = 'queued';
        $contentItem->ai_error = null;
        $contentItem->save();

        if (app()->environment('local')) {
            GenerateAiForContentItem::dispatchSync($contentItem->id);
        } else {
            GenerateAiForContentItem::dispatch($contentItem->id);
        }

        return back()->with('status', app()->environment('local')
            ? 'Rigenerazione AI completata (sync locale).'
            : 'Rigenerazione AI messa in coda (JOBv4).');
    }

    public function generatePlan(ContentPlan $contentPlan)
    {
        $items = ContentItem::where('content_plan_id', $contentPlan->id)->get();

        foreach ($items as $item) {
            $item->ai_status = 'queued';
            $item->ai_error = null;
            $item->save();

            GenerateAiForContentItem::dispatch($item->id);
        }

        return back()->with('status', 'Rigenerazione AI del piano messa in coda (background).');
    }

    public function generateImage(ContentItem $contentItem)
    {
        $contentItem->ai_status = 'queued';
        $contentItem->ai_error = null;
        $contentItem->save();

        if (app()->environment('local')) {
            GenerateAiImageForContentItem::dispatchSync($contentItem->id);
        } else {
            GenerateAiImageForContentItem::dispatch($contentItem->id);
        }

        return back()->with('status', app()->environment('local')
            ? 'Rigenerazione IMMAGINE completata (sync locale).'
            : 'Rigenerazione IMMAGINE messa in coda.');
    }
}
