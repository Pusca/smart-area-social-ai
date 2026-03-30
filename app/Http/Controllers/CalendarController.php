<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Services\ContentMediaPreviewService;
use App\Services\Social\SocialPublishingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function __construct(
        private readonly ContentMediaPreviewService $mediaPreviewService,
        private readonly SocialPublishingService $socialPublishingService
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $tz = config('app.timezone', 'Europe/Rome');

        $anchorRaw = trim((string) ($request->query('date') ?: $request->query('week') ?: now($tz)->toDateString()));
        $anchor = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorRaw) === 1) {
            try {
                $anchor = Carbon::createFromFormat('Y-m-d', $anchorRaw, $tz);
            } catch (\Throwable) {
                $anchor = null;
            }
        }
        if (!$anchor) {
            $anchor = now($tz);
        }
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $anchor->copy()->endOfWeek(Carbon::SUNDAY);

        $items = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('scheduled_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
            ->orderBy('scheduled_at')
            ->get();
        $this->mediaPreviewService->attachPreviewData($items);

        $byDay = [];
        for ($d = $weekStart->copy(); $d->lte($weekEnd); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $byDay[$key] = [
                'date' => $d->copy(),
                'items' => collect(),
            ];
        }

        foreach ($items as $it) {
            if (!$it->scheduled_at) {
                continue;
            }
            $key = $it->scheduled_at->copy()->timezone($tz)->format('Y-m-d');
            if (!isset($byDay[$key])) {
                continue;
            }
            $byDay[$key]['items']->push($it);
        }

        $connectedPlatforms = SocialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'meta')
            ->where('status', 'active')
            ->pluck('platform')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('calendar.index', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevDate' => $weekStart->copy()->subWeek()->toDateString(),
            'nextDate' => $weekStart->copy()->addWeek()->toDateString(),
            'byDay' => $byDay,
            'stats' => [
                'total' => $items->count(),
                'scheduled' => $items->where('status', 'scheduled')->count(),
                'published' => $items->where('status', 'published')->count(),
            ],
            'connectedPlatforms' => $connectedPlatforms,
        ]);
    }

    public function approve(Request $request, ContentItem $contentItem)
    {
        $this->authorizeTenant($request, $contentItem);

        try {
            $result = $this->socialPublishingService->approve($contentItem);
        } catch (\RuntimeException $e) {
            return back()->with('status', $e->getMessage());
        }

        $warnings = array_values(array_filter((array) ($result['warnings'] ?? [])));
        $scheduled = (int) ($result['scheduled'] ?? 0);

        $message = $scheduled > 0
            ? "Contenuto approvato e programmato su {$scheduled} canali."
            : 'Contenuto approvato.';

        if (!empty($warnings)) {
            $message .= ' ' . implode(' ', $warnings);
        }

        return back()->with('status', trim($message));
    }

    private function authorizeTenant(Request $request, ContentItem $item): void
    {
        if ((int) $item->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }
}
