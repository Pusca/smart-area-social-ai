<?php

namespace App\Http\Controllers;

use App\Enums\AiStatus;
use App\Jobs\GenerateAiForContentItem;
use App\Jobs\GenerateAiImageForContentItem;
use App\Jobs\GeneratePlanTopics;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * L'isolamento tenant è garantito dal global scope BelongsToTenant:
 * il route model binding restituisce 404 per risorse di altri tenant.
 */
class AiGenerateController extends Controller
{
    public function generateOne(ContentItem $contentItem)
    {
        $contentItem->ai_status = AiStatus::Queued;
        $contentItem->ai_error = null;
        $contentItem->save();

        GenerateAiForContentItem::dispatch($contentItem->id);

        return back()->with('status', 'Rigenerazione AI messa in coda.');
    }

    public function generatePlan(ContentPlan $contentPlan)
    {
        ContentItem::where('content_plan_id', $contentPlan->id)
            ->update(['ai_status' => AiStatus::Queued->value, 'ai_error' => null]);

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

    /**
     * Stato di generazione degli item richiesti (per il polling della UI).
     */
    public function status(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $items = ContentItem::whereIn('id', $data['ids'])
            ->get(['id', 'ai_status', 'ai_image_path']);

        return response()->json([
            'items' => $items->mapWithKeys(fn ($i) => [
                $i->id => [
                    'status' => $i->ai_status?->value,
                    'busy' => (bool) $i->ai_status?->isBusy(),
                    'image_url' => $i->ai_image_path ? Storage::disk('public')->url($i->ai_image_path) : null,
                ],
            ]),
        ]);
    }
}
