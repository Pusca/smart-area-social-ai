<?php

namespace App\Http\Controllers;

use App\Models\TenantProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class OnboardingController extends Controller
{
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
                'business_name'        => $data['brand_name'],
                'industry'             => $data['industry'],
                'default_tone'         => $data['tone'],
                'onboarding_started_at'=> Carbon::now(),
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
     * Passo 3: connessione social — gestito lato frontend con link OAuth.
     * Questo endpoint registra solo il "skip" o il completamento del passo.
     */
    public function skipSocial(Request $request): JsonResponse
    {
        // Nessuna azione server-side necessaria: il frontend avanza al passo 4.
        return response()->json(['ok' => true]);
    }

    /**
     * Completa l'onboarding e segna il profilo come configurato.
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        TenantProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->update(['onboarding_completed_at' => Carbon::now()]);

        return response()->json([
            'ok'           => true,
            'redirect_url' => route('wizard.start'),
        ]);
    }
}
