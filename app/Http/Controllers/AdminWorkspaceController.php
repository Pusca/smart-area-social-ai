<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\TrendBrief;
use App\Models\User;
use App\Services\GenerationMetricsService;
use App\Services\TenantQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminWorkspaceController extends Controller
{
    public function __construct(
        private readonly TenantQuotaService $tenantQuotaService,
        private readonly GenerationMetricsService $generationMetrics
    ) {
    }

    public function index(Request $request)
    {
        $supportsTenantLimits = Tenant::supportsLimitsColumn();

        $allUsers = User::query()
            ->with('tenant')
            ->orderByDesc('id')
            ->get();

        $adminUsers = $allUsers->filter(fn (User $user) => $user->isPlatformAdmin())->values();

        $managedUsers = $allUsers
            ->reject(fn (User $user) => $user->isPlatformAdmin())
            ->values();

        $usersByTenant = $managedUsers
            ->filter(fn (User $user) => $user->tenant_id !== null)
            ->groupBy(fn (User $user) => (int) $user->tenant_id);

        $tenants = Tenant::query()
            ->orderByDesc('id')
            ->get($this->tenantSelectColumns($supportsTenantLimits));

        $contentAgg = ContentItem::query()
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN ai_status = 'done' THEN 1 ELSE 0 END) as ai_done")
            ->selectRaw("SUM(CASE WHEN ai_status IN ('queued','pending') THEN 1 ELSE 0 END) as ai_queued")
            ->selectRaw("SUM(CASE WHEN ai_status = 'error' THEN 1 ELSE 0 END) as ai_error")
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $planAgg = ContentPlan::query()
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $assetAgg = BrandAsset::query()
            ->whereNull('content_plan_id')
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $profileAgg = TenantProfile::query()
            ->selectRaw('tenant_id, MAX(completed_at) as completed_at')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $trendBriefAgg = TrendBrief::query()
            ->get(['tenant_id', 'freshness_score', 'confidence_score', 'summary', 'fetched_at', 'expires_at'])
            ->keyBy('tenant_id');

        $lastActivityRaw = ContentItem::query()
            ->selectRaw('tenant_id, MAX(COALESCE(ai_generated_at, updated_at, created_at)) as last_activity_at')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $tenantStats = $tenants->map(function (Tenant $tenant) use (
            $contentAgg,
            $planAgg,
            $assetAgg,
            $profileAgg,
            $trendBriefAgg,
            $lastActivityRaw,
            $usersByTenant
        ) {
            $tenantId = (int) $tenant->id;
            $content = $contentAgg->get($tenantId);
            $plan = $planAgg->get($tenantId);
            $asset = $assetAgg->get($tenantId);
            $profile = $profileAgg->get($tenantId);
            $trendBrief = $trendBriefAgg->get($tenantId);
            $lastAct = $lastActivityRaw->get($tenantId);
            $tenantUsers = $usersByTenant->get($tenantId, collect())->values();
            $entryUser = $this->resolveWorkspaceUser($tenantUsers);
            $quota = $this->tenantQuotaService->usageSummary($tenant);

            $lastActivityAt = $lastAct && !empty($lastAct->last_activity_at)
                ? Carbon::parse((string) $lastAct->last_activity_at)
                : null;

            return [
                'tenant' => $tenant,
                'users_total' => (int) ($quota['users_count'] ?? 0),
                'plans_total' => (int) ($plan->total ?? 0),
                'contents_total' => (int) ($content->total ?? 0),
                'ai_done' => (int) ($content->ai_done ?? 0),
                'ai_queued' => (int) ($content->ai_queued ?? 0),
                'ai_error' => (int) ($content->ai_error ?? 0),
                'assets_total' => (int) ($asset->total ?? 0),
                'users' => $tenantUsers->map(fn (User $user) => [
                    'id' => (int) $user->id,
                    'name' => (string) ($user->name ?: 'Utente'),
                    'email' => (string) $user->email,
                    'role' => (string) ($user->role ?: 'editor'),
                ])->all(),
                'entry_user' => $entryUser ? [
                    'id' => (int) $entryUser->id,
                    'name' => (string) ($entryUser->name ?: 'Utente'),
                    'email' => (string) $entryUser->email,
                    'role' => (string) ($entryUser->role ?: 'editor'),
                ] : null,
                'brand_completed_at' => !empty($profile->completed_at) ? Carbon::parse((string) $profile->completed_at) : null,
                'trend_brief' => $trendBrief ? [
                    'freshness_score' => $trendBrief->freshness_score !== null ? (float) $trendBrief->freshness_score : null,
                    'confidence_score' => $trendBrief->confidence_score !== null ? (float) $trendBrief->confidence_score : null,
                    'signals_count' => (int) data_get($trendBrief->summary, 'signals_count', 0),
                    'fresh_signals' => (int) data_get($trendBrief->summary, 'fresh_signals', 0),
                    'fetched_at' => optional($trendBrief->fetched_at)->toDateTimeString(),
                    'expires_at' => optional($trendBrief->expires_at)->toDateTimeString(),
                ] : null,
                'last_activity_at' => $lastActivityAt,
                'is_active_recently' => $lastActivityAt ? $lastActivityAt->gte(now()->subDays(7)) : false,
                'quota' => $quota,
            ];
        })->values();

        $globalContents = $tenantStats->sum('contents_total');
        $globalAiDone = $tenantStats->sum('ai_done');
        $globalAiQueued = $tenantStats->sum('ai_queued');
        $globalAiError = $tenantStats->sum('ai_error');
        $globalPlans = $tenantStats->sum('plans_total');
        $activeTenants = $tenantStats->where('is_active_recently', true)->count();
        $brandReadyTenants = $tenantStats->filter(fn ($row) => $row['brand_completed_at'] instanceof Carbon)->count();
        $trendBriefsFresh = $tenantStats->filter(fn ($row) => (float) data_get($row, 'trend_brief.freshness_score', 0) >= 0.60)->count();
        $tenantsUsersOverLimit = $tenantStats->filter(fn ($row) => (bool) data_get($row, 'quota.users_over_limit', false))->count();
        $tenantsContentOverLimit = $tenantStats->filter(fn ($row) => (bool) data_get($row, 'quota.content_items_over_limit', false))->count();

        return view('admin.dashboard', [
            'adminUsers' => $adminUsers,
            'managedUsers' => $managedUsers,
            'tenants' => $tenants,
            'tenantStats' => $tenantStats,
            'commonPlans' => ['trial', 'pro', 'internal', 'custom'],
            'supportsTenantLimits' => $supportsTenantLimits,
            'stats' => [
                'users_total' => $managedUsers->count(),
                'users_without_tenant' => $managedUsers->whereNull('tenant_id')->count(),
                'tenants_total' => $tenants->count(),
                'tenants_active_recently' => $activeTenants,
                'tenants_brand_ready' => $brandReadyTenants,
                'tenants_with_fresh_trend_brief' => $trendBriefsFresh,
                'tenants_users_over_limit' => $tenantsUsersOverLimit,
                'tenants_content_over_limit' => $tenantsContentOverLimit,
                'plans_total' => $globalPlans,
                'contents_total' => $globalContents,
                'ai_done' => $globalAiDone,
                'ai_queued' => $globalAiQueued,
                'ai_error' => $globalAiError,
                'ai_completion' => $globalContents > 0 ? (int) round(($globalAiDone / $globalContents) * 100) : 0,
            ],
        ]);
    }

    public function generationMetrics(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $tenantId = isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null;
        $days = isset($validated['days']) ? (int) $validated['days'] : null;
        $snapshot = $this->generationMetrics->dashboardSnapshot($tenantId, $days);

        return view('admin.generation-metrics', [
            'selectedTenantId' => $tenantId,
            'selectedDays' => (int) data_get($snapshot, 'window_days', $days ?? 30),
            'summary' => (array) data_get($snapshot, 'summary', []),
            'costByTenant' => collect((array) data_get($snapshot, 'cost_by_tenant', [])),
            'costByProvider' => collect((array) data_get($snapshot, 'cost_by_provider', [])),
            'latencyByProvider' => collect((array) data_get($snapshot, 'latency_by_provider', [])),
            'failureModes' => collect((array) data_get($snapshot, 'failure_modes', [])),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateUserTenant(Request $request, User $user): RedirectResponse
    {
        if ($user->isPlatformAdmin()) {
            return back()->with('status', 'Non puoi modificare il tenant di un utente admin.');
        }

        $data = $request->validate([
            'tenant_action' => 'required|string|in:existing,new,detach',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'tenant_name' => 'nullable|string|max:120',
            'role' => 'nullable|string|in:owner,editor',
        ]);

        $action = (string) $data['tenant_action'];
        $tenantId = null;

        if ($action === 'existing') {
            if (empty($data['tenant_id'])) {
                return back()->withErrors(['tenant_id' => 'Seleziona un tenant valido.']);
            }

            $tenant = Tenant::query()->findOrFail((int) $data['tenant_id']);

            try {
                $this->tenantQuotaService->assertCanAssignUser($tenant, $user);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['tenant_id' => $e->getMessage()]);
            }

            $tenantId = (int) $tenant->id;
        } elseif ($action === 'new') {
            $name = trim((string) ($data['tenant_name'] ?? ''));
            if ($name === '') {
                return back()->withErrors(['tenant_name' => 'Inserisci il nome del nuovo tenant.']);
            }

            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $this->generateUniqueTenantSlug($name),
                'plan' => 'trial',
                'is_active' => true,
            ] + (Tenant::supportsLimitsColumn() ? ['limits' => []] : []));
            $tenantId = (int) $tenant->id;
        }

        $user->tenant_id = $tenantId;

        if ($tenantId !== null) {
            $selectedRole = strtolower(trim((string) ($data['role'] ?? $user->role ?? '')));
            $user->role = in_array($selectedRole, ['owner', 'editor'], true) ? $selectedRole : 'owner';
        }

        $user->save();

        $message = $tenantId !== null
            ? 'Tenant e ruolo aggiornati per ' . $user->email . '.'
            : 'Tenant scollegato per ' . $user->email . '.';

        return back()->with('status', $message);
    }

    public function updateTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan' => 'required|string|max:60',
            'max_users' => 'nullable|integer|min:1|max:1000',
            'max_content_items' => 'nullable|integer|min:1|max:500000',
        ]);

        $supportsTenantLimits = Tenant::supportsLimitsColumn();

        $tenant->plan = trim((string) $data['plan']);
        $tenant->is_active = $request->boolean('is_active');

        if ($supportsTenantLimits) {
            $tenant->limits = $this->tenantQuotaService->normalizeLimits($data);
        }

        $tenant->save();

        $message = 'Tenant aggiornato: ' . $tenant->name . '.';
        if (!$supportsTenantLimits) {
            $message .= ' Limiti non salvati: esegui la migration tenants.limits sul server.';
        }

        return back()->with('status', $message);
    }

    public function impersonateTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        $targetUser = $this->resolveWorkspaceUser($tenant->users()->get());

        if (!$targetUser) {
            return back()->with('status', 'Questo tenant non ha utenti disponibili per entrare nel workspace.');
        }

        return $this->startImpersonation($request, $targetUser, $tenant);
    }

    public function impersonateUser(Request $request, User $user): RedirectResponse
    {
        if ((int) ($user->tenant_id ?? 0) < 1) {
            return back()->with('status', 'Questo utente non ha un tenant associato.');
        }

        return $this->startImpersonation($request, $user, $user->tenant);
    }

    public function stopImpersonation(Request $request): RedirectResponse
    {
        $sessionData = (array) $request->session()->get('admin_impersonation', []);
        $originalAdminId = (int) ($sessionData['original_admin_id'] ?? 0);

        if ($originalAdminId < 1) {
            return redirect()->route('dashboard');
        }

        $admin = User::query()->find($originalAdminId);
        if (!$admin || !$admin->isPlatformAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $request->session()->forget('admin_impersonation');
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Tornato al controllo amministrativo.');
    }

    private function startImpersonation(Request $request, User $targetUser, ?Tenant $tenant = null): RedirectResponse
    {
        $admin = $request->user();

        if (!$admin || !$admin->isPlatformAdmin()) {
            abort(403);
        }

        if ((int) $targetUser->id === (int) $admin->id) {
            return redirect()->route('admin.dashboard')->with('status', 'Sei gia dentro l account admin.');
        }

        Auth::login($targetUser);
        $request->session()->regenerate();
        $request->session()->put('admin_impersonation', [
            'original_admin_id' => (int) $admin->id,
            'original_admin_name' => (string) ($admin->name ?: 'Admin'),
            'original_admin_email' => (string) $admin->email,
            'target_user_id' => (int) $targetUser->id,
            'target_user_name' => (string) ($targetUser->name ?: 'Utente'),
            'target_tenant_id' => (int) ($targetUser->tenant_id ?? 0),
            'target_tenant_name' => (string) ($tenant?->name ?: ($targetUser->tenant?->name ?: 'Tenant')),
            'started_at' => now()->toDateTimeString(),
        ]);

        return redirect()->route('dashboard')->with('status', 'Accesso admin attivo su ' . ($tenant?->name ?: 'workspace tenant') . '.');
    }

    private function generateUniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 1;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function resolveWorkspaceUser(Collection $users): ?User
    {
        return $users
            ->sortBy(function (User $user): string {
                $rank = match (strtolower(trim((string) ($user->role ?? '')))) {
                    'owner' => 0,
                    'editor' => 1,
                    default => 2,
                };

                return sprintf('%02d-%010d', $rank, (int) $user->id);
            })
            ->first();
    }

    /**
     * @return list<string>
     */
    private function tenantSelectColumns(bool $supportsTenantLimits): array
    {
        $columns = ['id', 'name', 'slug', 'plan', 'is_active', 'created_at'];

        if ($supportsTenantLimits) {
            $columns[] = 'limits';
        }

        return $columns;
    }
}
