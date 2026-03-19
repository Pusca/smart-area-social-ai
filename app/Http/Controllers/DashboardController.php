<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Services\ContentMediaPreviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ContentMediaPreviewService $mediaPreviewService
    ) {
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $tenantId = (int) ($u?->tenant_id ?? 0);

        $latestPlan = ContentPlan::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();

        $baseQuery = ContentItem::query()->where('tenant_id', $tenantId);

        $totalItems = (int) (clone $baseQuery)->count();

        $itemsAgg = (clone $baseQuery)
            ->selectRaw("SUM(CASE WHEN ai_status IN ('queued','pending') THEN 1 ELSE 0 END) as ai_queued")
            ->selectRaw("SUM(CASE WHEN ai_status = 'done' THEN 1 ELSE 0 END) as ai_done")
            ->selectRaw("SUM(CASE WHEN ai_status = 'error' THEN 1 ELSE 0 END) as ai_error")
            ->selectRaw('SUM(CASE WHEN scheduled_at IS NOT NULL THEN 1 ELSE 0 END) as scheduled_items')
            ->selectRaw("SUM(CASE WHEN status = 'published' OR published_at IS NOT NULL THEN 1 ELSE 0 END) as published_items")
            ->first();

        $queuedItems = (int) ($itemsAgg->ai_queued ?? 0);
        $doneItems = (int) ($itemsAgg->ai_done ?? 0);
        $errorItems = (int) ($itemsAgg->ai_error ?? 0);
        $scheduledItems = (int) ($itemsAgg->scheduled_items ?? 0);
        $publishedItems = (int) ($itemsAgg->published_items ?? 0);

        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $weekPlanned = (int) (clone $baseQuery)
            ->whereBetween('scheduled_at', [$weekStart, $weekEnd])
            ->count();
        $weekPublished = (int) (clone $baseQuery)
            ->whereBetween('published_at', [$weekStart, $weekEnd])
            ->count();

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $todayItems = (clone $baseQuery)
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();
        $todayPending = $todayItems->where('status', '!=', 'published')->count();

        $nextItem = (clone $baseQuery)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $recentItems = (clone $baseQuery)
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        $instagramFeedTotal = $totalItems;
        $instagramFeedItems = (clone $baseQuery)
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(24)
            ->get();
        $this->mediaPreviewService->attachPreviewData($instagramFeedItems);

        $aiCompletion = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;
        $scheduleCoverage = $totalItems > 0 ? (int) round(($scheduledItems / $totalItems) * 100) : 0;
        $publishRate = $totalItems > 0 ? (int) round(($publishedItems / $totalItems) * 100) : 0;
        $errorRate = $totalItems > 0 ? (int) round(($errorItems / $totalItems) * 100) : 0;
        $queueRate = $totalItems > 0 ? (int) round(($queuedItems / $totalItems) * 100) : 0;

        $workspaceScore = (int) round(max(0, min(100, 100 - ($errorRate * 1.5) - ($queueRate * 0.5) + ($publishRate * 0.3))));
        $scoreLabel = $workspaceScore >= 80
            ? 'Ottimo'
            : ($workspaceScore >= 60
                ? 'Buono'
                : ($workspaceScore >= 40 ? 'Attenzione' : 'Critico'));
        $scoreBadgeClass = $workspaceScore >= 80
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : ($workspaceScore >= 60
                ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                : ($workspaceScore >= 40
                    ? 'border-amber-200 bg-amber-50 text-amber-700'
                    : 'border-red-200 bg-red-50 text-red-700'));

        $planItemsTotal = 0;
        $planItemsDone = 0;
        $planItemsQueued = 0;
        $planItemsError = 0;
        $planTimeProgress = 0;
        $planOutputProgress = 0;
        $planDaysElapsed = 0;
        $planDaysTotal = 0;

        if ($latestPlan) {
            $planAgg = ContentItem::query()
                ->where('content_plan_id', $latestPlan->id)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN ai_status = 'done' THEN 1 ELSE 0 END) as done")
                ->selectRaw("SUM(CASE WHEN ai_status IN ('queued','pending') THEN 1 ELSE 0 END) as queued")
                ->selectRaw("SUM(CASE WHEN ai_status = 'error' THEN 1 ELSE 0 END) as errors")
                ->first();

            $planItemsTotal = (int) ($planAgg->total ?? 0);
            $planItemsDone = (int) ($planAgg->done ?? 0);
            $planItemsQueued = (int) ($planAgg->queued ?? 0);
            $planItemsError = (int) ($planAgg->errors ?? 0);

            $start = Carbon::parse($latestPlan->start_date)->startOfDay();
            $end = Carbon::parse($latestPlan->end_date)->endOfDay();
            if ($end->lt($start)) {
                $end = $start->copy()->endOfDay();
            }
            $planDaysTotal = max(1, $start->diffInDays($end->copy()->startOfDay()) + 1);
            $planStatus = strtolower(trim((string) ($latestPlan->status ?? '')));
            if (in_array($planStatus, ['draft', 'queued', 'pending'], true)) {
                $planDaysElapsed = 0;
                $planTimeProgress = 0;
            } else {
                $today = now();
                if ($today->lt($start)) {
                    $planDaysElapsed = 0;
                } elseif ($today->gt($end)) {
                    $planDaysElapsed = $planDaysTotal;
                } else {
                    $planDaysElapsed = $start->diffInDays($today->copy()->startOfDay()) + 1;
                }
                $planTimeProgress = (int) round(($planDaysElapsed / $planDaysTotal) * 100);
            }
            $planOutputProgress = $planItemsTotal > 0 ? (int) round(($planItemsDone / $planItemsTotal) * 100) : 0;
        }

        return view('dashboard', compact(
            'u',
            'latestPlan',
            'totalItems',
            'queuedItems',
            'doneItems',
            'errorItems',
            'scheduledItems',
            'publishedItems',
            'weekPlanned',
            'weekPublished',
            'todayItems',
            'todayPending',
            'nextItem',
            'recentItems',
            'instagramFeedTotal',
            'instagramFeedItems',
            'aiCompletion',
            'scheduleCoverage',
            'publishRate',
            'errorRate',
            'queueRate',
            'workspaceScore',
            'scoreLabel',
            'scoreBadgeClass',
            'planItemsTotal',
            'planItemsDone',
            'planItemsQueued',
            'planItemsError',
            'planTimeProgress',
            'planOutputProgress',
            'planDaysElapsed',
            'planDaysTotal'
        ));
    }
}
