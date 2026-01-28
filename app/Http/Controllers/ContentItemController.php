<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ContentItemController extends Controller
{
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
            'caption' => 'nullable|string',
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

        return redirect()->route('posts')->with('status', 'Contenuto creato ✅');
    }

    public function edit(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        return view('posts.edit', ['item' => $contentItem]);
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        $data = $request->validate([
            'platform' => 'required|string|max:50',
            'format' => 'required|string|max:50',
            'scheduled_at' => 'nullable|date',
            'title' => 'nullable|string|max:120',
            'caption' => 'nullable|string',
            'status' => 'required|string|max:30',
        ]);

        $contentItem->platform = $data['platform'];
        $contentItem->format = $data['format'];
        $contentItem->status = $data['status'];
        $contentItem->title = $data['title'] ?? null;
        $contentItem->caption = $data['caption'] ?? null;
        $contentItem->scheduled_at = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        $contentItem->save();

        return redirect()->route('posts')->with('status', 'Contenuto aggiornato ✅');
    }

    public function destroy(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);
        $contentItem->delete();

        return redirect()->route('posts')->with('status', 'Contenuto eliminato 🗑️');
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int)$item->tenant_id !== (int)$request->user()->tenant_id) {
            abort(403);
        }
    }
}
