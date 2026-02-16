<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ContentItemController extends Controller
{
    /**
     * LISTA "POSTS" (la tua pagina attuale) => resources/views/posts/index.blade.php
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $items = ContentItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('posts.index', compact('items'));
    }

    /**
     * LISTA "CONTENT ITEMS" (nuova galleria con immagini) => resources/views/content-items/index.blade.php
     */
    public function gallery(Request $request)
    {
        $user = $request->user();

        $q = ContentItem::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        // filtri opzionali (se li aggiungi in futuro)
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('platform')) {
            $q->where('platform', $request->string('platform')->toString());
        }

        $items = $q->paginate(24)->withQueryString();

        return view('content-items.index', compact('items'));
    }

    /**
     * DETTAGLIO "CONTENT ITEM" (immagine grande) => resources/views/content-items/show.blade.php
     */
    public function show(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        return view('content-items.show', [
            'item' => $contentItem,
        ]);
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
        $item->content_plan_id = 1; // per ora placeholder (poi lo rendiamo dinamico)
        $item->created_by = $user->id;

        $item->platform = $data['platform'];
        $item->format = $data['format'];
        $item->status = $data['status'];
        $item->title = $data['title'] ?? null;
        $item->caption = $data['caption'] ?? null;

        $item->scheduled_at = !empty($data['scheduled_at'])
            ? Carbon::parse($data['scheduled_at'])
            : null;

        $item->save();

        return redirect()->route('posts.index')->with('status', 'Contenuto creato ✅');
    }

    public function edit(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        return view('posts.edit', compact('contentItem'));
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'ai_caption' => 'nullable|string',
            'ai_image_prompt' => 'nullable|string',
            'status' => 'required|string|max:30',
        ]);

        $contentItem->platform = $data['platform'];
        $contentItem->format = $data['format'];
        $contentItem->status = $data['status'];
        $contentItem->title = $data['title'] ?? null;
        $contentItem->ai_caption = $data['ai_caption'] ?? null;
        $contentItem->ai_image_prompt = $data['ai_image_prompt'] ?? null;
        $contentItem->scheduled_at = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        $contentItem->save();

        return redirect()->route('posts.index')->with('status', 'Contenuto aggiornato ✅');
    }

    public function destroy(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        $contentItem->delete();

        return redirect()->route('posts.index')->with('status', 'Contenuto eliminato 🗑️');
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int)$item->tenant_id !== (int)$request->user()->tenant_id) {
            abort(403);
        }
    }
}
