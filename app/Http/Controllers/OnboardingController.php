<?php

namespace App\Http\Controllers;

use App\Models\BrandAsset;
use App\Models\TenantProfile;
use App\Services\Onboarding\QuickstartOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly QuickstartOnboardingService $quickstartService
    ) {
    }

    /**
     * Mostra il wizard di onboarding per il primo accesso.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user    = $request->user();
        $profile = TenantProfile::query()->where('tenant_id', $user->tenant_id)->first();

        // Se l'onboarding è già completato, vai al dashboard
        if ($profile?->onboarding_completed_at) {
            return redirect()->route('dashboard');
        }

        return view('onboarding.index', [
            'profile'  => $profile,
            'userName' => $user->name,
        ]);
    }

    /**
     * Salva il passo 1: dati brand base.
     */
    public function saveBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_name' => 'required|string|max:120',
            'industry'   => 'required|string|max:120',
            'tone'       => 'required|string|max:80',
        ]);

        $user = $request->user();

        TenantProfile::query()->updateOrCreate(
            ['tenant_id' => $user->tenant_id],
            [
                'business_name'         => $data['brand_name'],
                'industry'              => $data['industry'],
                'default_tone'          => $data['tone'],
                'onboarding_started_at' => Carbon::now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Salva il passo 2: audience e servizi.
     */
    public function saveAudience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target'       => 'required|string|max:255',
            'services'     => 'required|string|max:500',
            'default_goal' => 'nullable|string|in:awareness,engagement,lead,conversion,trust',
        ]);

        $user = $request->user();

        TenantProfile::query()->where('tenant_id', $user->tenant_id)->update([
            'target'       => $data['target'],
            'services'     => $data['services'],
            'default_goal' => $data['default_goal'] ?? 'awareness',
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Passo 3: carica logo e immagini di riferimento come BrandAsset.
     */
    public function uploadAssets(Request $request): JsonResponse
    {
        $request->validate([
            'logo'     => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:8192',
            'images'   => 'nullable|array|max:8',
            'images.*' => 'file|mimes:png,jpg,jpeg,webp|max:10240',
        ]);

        $user     = $request->user();
        $tenantId = (int) $user->tenant_id;
        $baseDir  = 'brand-assets/' . $tenantId;
        $count    = 0;

        if ($request->hasFile('logo')) {
            /** @var UploadedFile $file */
            $file = $request->file('logo');
            $path = $file->store($baseDir . '/logo', 'public');

            BrandAsset::query()->create([
                'tenant_id'       => $tenantId,
                'content_plan_id' => null,
                'kind'            => 'logo',
                'path'            => $path,
                'original_name'   => $file->getClientOriginalName(),
                'size'            => $file->getSize(),
                'mime'            => $file->getMimeType(),
            ]);

            $count++;
        }

        foreach ((array) $request->file('images', []) as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store($baseDir . '/images', 'public');

            BrandAsset::query()->create([
                'tenant_id'       => $tenantId,
                'content_plan_id' => null,
                'kind'            => 'image',
                'path'            => $path,
                'original_name'   => $file->getClientOriginalName(),
                'size'            => $file->getSize(),
                'mime'            => $file->getMimeType(),
            ]);

            $count++;
        }

        return response()->json(['ok' => true, 'stored' => $count]);
    }

    /**
     * Passo 4 — skip social (mantenuto per compatibilità).
     */
    public function skipSocial(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * Completa l'onboarding, genera la demo quickstart (2 img NanoBanana + 1 video Veo 3.1)
     * e reindirizza alla pagina di progresso generazione.
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        TenantProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->update(['onboarding_completed_at' => Carbon::now()]);

        try {
            $result = $this->quickstartService->regenerateQuickstart($user);

            return response()->json([
                'ok'           => true,
                'redirect_url' => route('plans.generating', [
                    'contentPlan' => $result['plan']->id,
                    'context'     => 'quickstart',
                ]),
            ]);
        } catch (\Throwable $e) {
            // Quickstart fallito (es. nessuna immagine) → vai al dashboard
            return response()->json([
                'ok'           => true,
                'redirect_url' => route('dashboard'),
            ]);
        }
    }
}
