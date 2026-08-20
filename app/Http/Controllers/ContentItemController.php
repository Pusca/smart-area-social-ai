<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * L'isolamento tenant è garantito dal global scope BelongsToTenant
 * (query filtrate e binding 404 per risorse di altri tenant).
 */
class ContentItemController extends Controller
{
    /**
     * LISTA "POSTS" => resources/views/posts/index.blade.php
     */
    public function index(Request $request)
    {
        $items = ContentItem::query()
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('posts.index', compact('items'));
    }

    /**
     * GALLERIA (con immagini) => resources/views/content-items/index.blade.php
     */
    public function gallery(Request $request)
    {
        $q = ContentItem::query()
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('platform')) {
            $q->where('platform', $request->string('platform')->toString());
        }

        $items = $q->paginate(24)->withQueryString();

        return view('content-items.index', compact('items'));
    }

    public function show(Request $request, ContentItem $contentItem)
    {
        return view('content-items.show', ['item' => $contentItem]);
    }

    public function create(Request $request)
    {
        return view('posts.create');
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'ai_caption' => 'nullable|string',
            'ai_image_prompt' => 'nullable|string',
            'status' => 'required|string|max:30',
        ]);

        $item = new ContentItem();
        $item->tenant_id = $user->tenant_id;
        $item->content_plan_id = null; // post manuale, non legato a un piano
        $item->created_by = $user->id;

        $item->platform = $data['platform'];
        $item->format = $data['format'];
        $item->status = $data['status'];
        $item->title = $data['title'] ?? null;
        $item->ai_caption = $data['ai_caption'] ?? null;
        $item->ai_image_prompt = $data['ai_image_prompt'] ?? null;

        $item->scheduled_at = !empty($data['scheduled_at'])
            ? Carbon::parse($data['scheduled_at'])
            : null;

        $item->save();

        return redirect()->route('posts.index')->with('status', 'Contenuto creato ✅');
    }

    public function edit(Request $request, ContentItem $contentItem)
    {
        return view('posts.edit', compact('contentItem'));
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'ai_caption' => 'nullable|string',
            'ai_hashtags' => 'nullable|string|max:1000',
            'ai_cta' => 'nullable|string|max:255',
            'ai_image_prompt' => 'nullable|string',
            'status' => 'required|string|max:30',
        ]);

        $contentItem->platform = $data['platform'];
        $contentItem->format = $data['format'];
        $contentItem->status = $data['status'];
        $contentItem->title = $data['title'] ?? null;
        $contentItem->ai_caption = $data['ai_caption'] ?? null;
        $contentItem->ai_hashtags = $data['ai_hashtags'] ?? null;
        $contentItem->ai_cta = $data['ai_cta'] ?? null;
        $contentItem->ai_image_prompt = $data['ai_image_prompt'] ?? null;
        $contentItem->scheduled_at = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        $contentItem->save();

        return redirect()->route('posts.index')->with('status', 'Contenuto aggiornato ✅');
    }

    public function destroy(Request $request, ContentItem $contentItem)
    {
        $contentItem->delete();

        return redirect()->route('posts.index')->with('status', 'Contenuto eliminato 🗑️');
    }
}
