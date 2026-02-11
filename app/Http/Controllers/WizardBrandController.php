<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\ContentPlan;
use Illuminate\Http\Request;

class WizardBrandController extends Controller
{
    public function brand(Request $request)
    {
        $user = $request->user();

        // aggancia gli asset al piano più recente del tenant (se esiste)
        $plan = ContentPlan::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('id')
            ->first();

        $assets = BrandAsset::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($q) use ($plan) {
                $q->whereNull('content_plan_id');
                if ($plan) {
                    $q->orWhere('content_plan_id', $plan->id);
                }
            })
            ->orderByDesc('id')
            ->get();

        return view('wizard.brand', [
            'plan' => $plan,
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

        $plan = ContentPlan::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('id')
            ->first();

        // LOGO
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');

            $path = $file->store(
                'brand-assets/' . $user->tenant_id . '/logo',
                'public'
            );

            BrandAsset::create([
                'tenant_id' => $user->tenant_id,
                'content_plan_id' => $plan?->id,
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
                    'content_plan_id' => $plan?->id,
                    'kind' => 'image',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ]);
            }
        }

        return redirect()
            ->route('wizard.brand')
            ->with('status', 'Asset brand salvati ✅');
    }
}
