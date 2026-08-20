<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeBrandVisuals;
use App\Jobs\BuildBrandProfileFromWebsite;
use App\Models\BrandAsset;
use App\Models\TenantProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = TenantProfile::first();

        $assets = BrandAsset::whereNull('content_plan_id') // assets "di brand" (non legati a un piano)
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

            'services' => 'nullable|string|max:2000',
            'target' => 'nullable|string|max:2000',
            'cta' => 'nullable|string|max:255',

            'brand_voice' => 'nullable|string|max:2000',
            'example_posts' => 'nullable|string|max:6000',

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
            TenantProfile::updateOrCreate(
                ['tenant_id' => $user->tenant_id],
                [
                    'business_name' => $data['business_name'],
                    'industry' => $data['industry'] ?? null,
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,

                    'services' => $data['services'] ?? null,
                    'target' => $data['target'] ?? null,
                    'cta' => $data['cta'] ?? null,

                    'brand_voice' => $data['brand_voice'] ?? null,
                    'example_posts' => $data['example_posts'] ?? null,

                    'default_goal' => $data['default_goal'] ?? null,
                    'default_tone' => $data['default_tone'] ?? null,
                    'default_posts_per_week' => $data['default_posts_per_week'] ?? null,
                    'default_platforms' => $data['default_platforms'] ?? [],
                    'default_formats' => $data['default_formats'] ?? [],
                    'completed_at' => Carbon::now(),
                ]
            );

            $uploadedSomething = false;
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

                $uploadedSomething = true;
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

                    $uploadedSomething = true;
                }
            }

            DB::commit();

            // Nuove foto → aggiorna la guida di stile visivo usata dagli image prompt
            if ($uploadedSomething) {
                AnalyzeBrandVisuals::dispatch($user->tenant_id);
            }

            return redirect()->route('profile.brand')->with('status', 'Profilo attività salvato ✅');
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('profile.brand')->with('status', 'Errore salvataggio ❌: ' . $e->getMessage());
        }
    }

    /**
     * Onboarding "solo URL": mette in coda il crawling del sito (multi-pagina,
     * canali social inclusi) e la compilazione AI del profilo.
     * La UI segue l'avanzamento con prefillStatus.
     */
    public function prefill(Request $request)
    {
        $data = $request->validate([
            'website' => 'required|url:http,https|max:255',
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        Cache::put(
            BuildBrandProfileFromWebsite::cacheKey($tenantId),
            ['status' => 'queued'],
            now()->addMinutes(15)
        );

        BuildBrandProfileFromWebsite::dispatch($tenantId, $data['website']);

        return response()->json(['ok' => true, 'queued' => true]);
    }

    /**
     * Stato del prefill in corso (polling della pagina brand).
     */
    public function prefillStatus(Request $request)
    {
        $state = Cache::get(BuildBrandProfileFromWebsite::cacheKey((int) $request->user()->tenant_id));

        return response()->json($state ?? ['status' => 'idle']);
    }

    /**
     * Elimina asset (logo o immagine) del tenant.
     * Il global scope garantisce già che l'asset appartenga al tenant.
     */
    public function destroyAsset(Request $request, BrandAsset $asset)
    {
        // solo asset brand-level (non plan-level)
        if (!is_null($asset->content_plan_id)) {
            abort(403);
        }

        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->route('profile.brand')->with('status', 'Asset eliminato ✅');
    }
}
