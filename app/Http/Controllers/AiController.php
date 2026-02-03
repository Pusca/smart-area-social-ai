<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiForContentItem;
use App\Models\ContentItem;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function index()
    {
        return view('ai.index');
    }

    /**
     * Route: ai.generate (POST ai/generate)
     * Accetta un array di content_item_ids e li mette in coda
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'content_item_ids' => ['required', 'array', 'min:1'],
            'content_item_ids.*' => ['integer'],
        ]);

        $items = ContentItem::whereIn('id', $data['content_item_ids'])->get();

        foreach ($items as $item) {
            $item->ai_status = 'queued';
            $item->ai_error = null;
            $item->save();

            GenerateAiForContentItem::dispatch($item->id);
        }

        return back()->with('status', 'Generazione AI messa in coda.');
    }
}
