<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $tz = config('app.timezone', 'Europe/Rome');

        // Settimana corrente (Lun → Dom) con navigazione ?week=YYYY-MM-DD
        $weekStart = $request->query('week')
            ? Carbon::parse($request->query('week'), $tz)->startOfWeek(Carbon::MONDAY)
            : now($tz)->startOfWeek(Carbon::MONDAY);

        $weekEnd = (clone $weekStart)->endOfWeek(Carbon::SUNDAY);

        $items = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('scheduled_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
            ->orderBy('scheduled_at')
            ->get();

        // Raggruppo per giorno (Y-m-d)
        $byDay = [];
        for ($d = $weekStart->copy(); $d->lte($weekEnd); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $byDay[$key] = [
                'date' => $d->copy(),
                'items' => collect(),
            ];
        }

        foreach ($items as $it) {
            if (!$it->scheduled_at) continue;
            $key = Carbon::parse($it->scheduled_at, $tz)->format('Y-m-d');
            if (!isset($byDay[$key])) continue;
            $byDay[$key]['items']->push($it);
        }

        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        return view('calendar.index', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'byDay' => $byDay,
        ]);
    }
}
