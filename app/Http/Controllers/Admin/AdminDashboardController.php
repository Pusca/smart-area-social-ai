<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $allUsers = User::query()
            ->with('tenant')
            ->orderByDesc('id')
            ->get();

        $adminUsers = $allUsers->filter(fn (User $user) => $user->isPlatformAdmin())->values();

        $managedUsers = $allUsers->reject(fn (User $user) => $user->isPlatformAdmin())->values();
        $usersByTenant = $managedUsers
            ->filter(fn (User $user) => $user->tenant_id !== null)
            ->groupBy(fn (User $user) => (int) $user->tenant_id);

        $tenants = Tenant::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'slug', 'plan', 'is_active', 'created_at']);

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
            $lastActivityRaw,
            $usersByTenant
        ) {
            $tenantId = (int) $tenant->id;
            $content = $contentAgg->get($tenantId);
            $plan = $planAgg->get($tenantId);
            $asset = $assetAgg->get($tenantId);
            $profile = $profileAgg->get($tenantId);
            $lastAct = $lastActivityRaw->get($tenantId);
            $tenantUsers = $usersByTenant->get($tenantId, collect())->values();

            $lastActivityAt = $lastAct && !empty($lastAct->last_activity_at)
                ? Carbon::parse((string) $lastAct->last_activity_at)
                : null;

            return [
                'tenant' => $tenant,
                'users_total' => (int) User::query()->where('tenant_id', $tenantId)->count(),
                'plans_total' => (int) ($plan->total ?? 0),
                'contents_total' => (int) ($content->total ?? 0),
                'ai_done' => (int) ($content->ai_done ?? 0),
                'ai_queued' => (int) ($content->ai_queued ?? 0),
                'ai_error' => (int) ($content->ai_error ?? 0),
                'assets_total' => (int) ($asset->total ?? 0),
                'users' => $tenantUsers->map(fn (User $user) => [
                    'name' => (string) ($user->name ?: 'Utente'),
                    'email' => (string) $user->email,
                    'role' => (string) ($user->role ?: 'editor'),
                ])->all(),
                'brand_completed_at' => !empty($profile->completed_at) ? Carbon::parse((string) $profile->completed_at) : null,
                'last_activity_at' => $lastActivityAt,
                'is_active_recently' => $lastActivityAt ? $lastActivityAt->gte(now()->subDays(7)) : false,
            ];
        })->values();

        $globalContents = $tenantStats->sum('contents_total');
        $globalAiDone = $tenantStats->sum('ai_done');
        $globalAiQueued = $tenantStats->sum('ai_queued');
        $globalAiError = $tenantStats->sum('ai_error');
        $globalPlans = $tenantStats->sum('plans_total');
        $activeTenants = $tenantStats->where('is_active_recently', true)->count();
        $brandReadyTenants = $tenantStats->filter(fn ($row) => $row['brand_completed_at'] instanceof Carbon)->count();

        return view('admin.dashboard', [
            'adminUsers' => $adminUsers,
            'managedUsers' => $managedUsers,
            'tenants' => $tenants,
            'tenantStats' => $tenantStats,
            'stats' => [
                'users_total' => $managedUsers->count(),
                'users_without_tenant' => $managedUsers->whereNull('tenant_id')->count(),
                'tenants_total' => $tenants->count(),
                'tenants_active_recently' => $activeTenants,
                'tenants_brand_ready' => $brandReadyTenants,
                'plans_total' => $globalPlans,
                'contents_total' => $globalContents,
                'ai_done' => $globalAiDone,
                'ai_queued' => $globalAiQueued,
                'ai_error' => $globalAiError,
                'ai_completion' => $globalContents > 0 ? (int) round(($globalAiDone / $globalContents) * 100) : 0,
            ],
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
        ]);

        $action = (string) $data['tenant_action'];
        $tenantId = null;

        if ($action === 'existing') {
            if (empty($data['tenant_id'])) {
                return back()->withErrors(['tenant_id' => 'Seleziona un tenant valido.']);
            }
            $tenantId = (int) $data['tenant_id'];
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
            ]);
            $tenantId = (int) $tenant->id;
        }

        $user->tenant_id = $tenantId;
        if ($tenantId !== null) {
            $role = strtolower(trim((string) ($user->role ?? '')));
            if (!in_array($role, ['owner', 'editor'], true)) {
                $user->role = 'owner';
            }
        }
        $user->save();

        return back()->with('status', 'Tenant aggiornato per ' . $user->email . '.');
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
}
