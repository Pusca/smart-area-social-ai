<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
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
        ]);
    }
}
