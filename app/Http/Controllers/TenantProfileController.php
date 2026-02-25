<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\TenantProfile;
use App\Services\Editorial\EditorialStrategyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantProfileController extends Controller
{
    public function __construct(
        private readonly EditorialStrategyService $editorialStrategyService
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        $profile = TenantProfile::where('tenant_id', $user->tenant_id)->first();

        $assets = BrandAsset::where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id') // assets “di brand” (non legati a un piano)
            ->latest('id')
            ->get();

        return view('wizard.brand', compact('profile', 'assets'));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'business_name' => 'required|string|max:120',
            'industry' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'vision' => 'nullable|string|max:2000',
            'values' => 'nullable|string|max:2000',
            'business_hours' => 'nullable|string|max:1000',
            'seasonal_offers' => 'nullable|string|max:2000',
            'brand_palette' => 'nullable|string|max:255',

            'services' => 'nullable|string|max:2000',
            'target' => 'nullable|string|max:2000',
            'cta' => 'nullable|string|max:255',

            'default_goal' => 'nullable|string|max:500',
            'default_tone' => 'nullable|string|max:80',
            'default_posts_per_week' => 'nullable|integer|min:1|max:21',
            'default_platforms' => 'nullable|array',
            'default_platforms.*' => 'string|max:50',
            'default_formats' => 'nullable|array',
            'default_formats.*' => 'string|max:50',

            // assets
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:4096',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        DB::beginTransaction();
        try {
            $profile = TenantProfile::updateOrCreate(
                ['tenant_id' => $user->tenant_id],
                [
                    'business_name' => $data['business_name'],
                    'industry' => $data['industry'] ?? null,
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'vision' => $data['vision'] ?? null,
                    'values' => $data['values'] ?? null,
                    'business_hours' => $data['business_hours'] ?? null,
                    'seasonal_offers' => $data['seasonal_offers'] ?? null,
                    'brand_palette' => $data['brand_palette'] ?? null,

                    'services' => $data['services'] ?? null,
                    'target' => $data['target'] ?? null,
                    'cta' => $data['cta'] ?? null,

                    'default_goal' => $data['default_goal'] ?? null,
                    'default_tone' => $data['default_tone'] ?? null,
                    'default_posts_per_week' => $data['default_posts_per_week'] ?? null,
                    'default_platforms' => $data['default_platforms'] ?? [],
                    'default_formats' => $data['default_formats'] ?? [],
                    'completed_at' => Carbon::now(),
                ]
            );

            // Salva assets (brand-level)
            $baseDir = 'brand-assets/' . $user->tenant_id;

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $path = $file->store($baseDir . '/logo', 'public');

                BrandAsset::create([
                    'tenant_id' => $user->tenant_id,
                    'content_plan_id' => null,
                    'kind' => 'logo',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ]);
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $img) {
                    $path = $img->store($baseDir . '/images', 'public');

                    BrandAsset::create([
                        'tenant_id' => $user->tenant_id,
                        'content_plan_id' => null,
                        'kind' => 'image',
                        'path' => $path,
                        'original_name' => $img->getClientOriginalName(),
                        'size' => $img->getSize(),
                        'mime' => $img->getMimeType(),
                    ]);
                }
            }

            $this->editorialStrategyService->refreshForTenant((int) $user->tenant_id, $profile);

            DB::commit();
            return redirect()->route('profile.brand')->with('status', 'Profilo attività salvato ✅');

        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('profile.brand')->with('status', 'Errore salvataggio ❌: ' . $e->getMessage());
        }
    }

    public function destroyAssets(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
        ]);

        $assets = BrandAsset::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id')
            ->whereIn('id', $data['asset_ids'])
            ->get();

        foreach ($assets as $asset) {
            if ($asset->path) {
                Storage::disk('public')->delete($asset->path);
            }
            $asset->delete();
        }

        return redirect()->route('profile.brand')->with('status', 'Assets selezionati eliminati ✅');
    }

    /**
     * ✅ Elimina asset (logo o immagine) del tenant
     * - verifica appartenenza tenant
     * - cancella file da storage public
     * - cancella record DB
     */
    public function destroyAsset(Request $request, BrandAsset $asset)
    {
        $user = $request->user();

        // sicurezza: solo asset del tenant e solo brand-level (non plan-level)
        if ((int)$asset->tenant_id !== (int)$user->tenant_id || !is_null($asset->content_plan_id)) {
            abort(403);
        }

        // cancella file
        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->route('profile.brand')->with('status', 'Asset eliminato ✅');
    }
}
