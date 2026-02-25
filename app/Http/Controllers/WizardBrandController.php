<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\TenantProfile;
use App\Services\Editorial\EditorialStrategyService;
use Illuminate\Http\Request;

class WizardBrandController extends Controller
{
    public function __construct(
        private readonly EditorialStrategyService $editorialStrategyService
    ) {
    }

    public function brand(Request $request)
    {
        $user = $request->user();

        $assets = BrandAsset::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('content_plan_id')
            ->orderByDesc('id')
            ->get();

        return view('wizard.brand', [
            'plan' => null,
            'assets' => $assets,
        ]);
    }

    public function brandStore(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'logo' => 'nullable|image|max:4096',      // 4MB
            'images' => 'nullable|array|max:8',       // max 8 files
            'images.*' => 'nullable|image|max:6144',  // 6MB cad.
        ]);

        // LOGO
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');

            $path = $file->store(
                'brand-assets/' . $user->tenant_id . '/logo',
                'public'
            );

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

        // IMMAGINI
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if (!$file) continue;

                $path = $file->store(
                    'brand-assets/' . $user->tenant_id . '/images',
                    'public'
                );

                BrandAsset::create([
                    'tenant_id' => $user->tenant_id,
                    'content_plan_id' => null,
                    'kind' => 'image',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ]);
            }
        }

        $profile = TenantProfile::query()->where('tenant_id', $user->tenant_id)->first();
        if ($profile) {
            $this->editorialStrategyService->refreshForTenant((int) $user->tenant_id, $profile);
        }

        return redirect()
            ->route('wizard.brand')
            ->with('status', 'Asset brand salvati ✅');
    }
}
