<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Jobs\GenerateAiImageForContentItem;
use App\Jobs\GeneratePlanTopics;
use App\Models\ContentItem;
use App\Models\ContentPlan;

class AiGenerateController extends Controller
{
    public function generateOne(ContentItem $contentItem)
    {
        $contentItem->ai_status = 'queued';
        $contentItem->ai_error = null;
        $contentItem->save();

        GenerateAiForContentItem::dispatch($contentItem->id);

        return back()->with('status', 'Rigenerazione AI messa in coda.');
    }

    public function generatePlan(ContentPlan $contentPlan)
    {
        ContentItem::where('content_plan_id', $contentPlan->id)
            ->update(['ai_status' => 'queued', 'ai_error' => null]);

        // Ri-idea gli argomenti del piano, poi accoda la generazione dei singoli item
        GeneratePlanTopics::dispatch($contentPlan->id);

        return back()->with('status', 'Rigenerazione AI del piano messa in coda.');
    }

    public function generateImage(ContentItem $contentItem)
    {
        $contentItem->ai_error = null;
        $contentItem->save();

        GenerateAiImageForContentItem::dispatch($contentItem->id);

        return back()->with('status', 'Rigenerazione immagine messa in coda.');
    }
}
