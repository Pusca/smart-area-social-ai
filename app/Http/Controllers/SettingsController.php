<?php

namespace App\Http\Controllers;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\SocialAccount;
use App\Models\TenantProfile;
use App\Services\AI\TenantFineTuningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function index(Request $request, TenantFineTuningService $tenantFineTuningService): View
    {
        $tenantId = (int) $request->user()->tenant_id;

        $profile = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $assets = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->get();
        $assetVariablesCount = AssetVariable::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();
        $socialAccounts = SocialAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_primary')
            ->orderBy('platform')
            ->orderBy('account_name')
            ->get();

        $logosCount = $assets->where('kind', 'logo')->count();
        $imagesCount = $assets->where('kind', 'image')->count();
        $videosCount = $assets->where('kind', 'video')->count();
        $audiosCount = $assets->where('kind', 'audio')->count();
        $activeSocialAccounts = $socialAccounts->where('status', 'active');
        $connectedPlatforms = $activeSocialAccounts->pluck('platform')->filter()->unique()->values();
        $fineTuning = $tenantFineTuningService->previewStats($tenantId);

        $setupChecks = collect([
            [
                'label' => 'Profilo azienda',
                'ready' => filled($profile?->business_name) && filled($profile?->industry) && filled($profile?->services),
                'hint' => 'Completa nome, settore e servizi principali.',
                'href' => route('profile.brand') . '#brand-profile-section',
            ],
            [
                'label' => 'Default contenuti',
                'ready' => filled($profile?->default_goal) && filled($profile?->default_tone),
                'hint' => 'Definisci obiettivo e tono base.',
                'href' => route('profile.brand') . '#brand-defaults-section',
            ],
            [
                'label' => 'Materiali brand',
                'ready' => $logosCount > 0 && $imagesCount >= 3,
                'hint' => 'Carica logo e almeno alcune immagini reali.',
                'href' => route('profile.brand') . '#brand-assets-section',
            ],
            [
                'label' => 'Variabili riutilizzabili',
                'ready' => $assetVariablesCount > 0,
                'hint' => 'Crea persone, luoghi o prodotti riutilizzabili.',
                'href' => route('profile.brand') . '#brand-variables-section',
            ],
            [
                'label' => 'Connessioni social',
                'ready' => $activeSocialAccounts->isNotEmpty(),
                'hint' => 'Collega almeno un account Meta.',
                'href' => route('settings'),
            ],
            [
                'label' => 'Fine-tuning testo',
                'ready' => !empty($fineTuning['active_model']),
                'hint' => 'Attiva un modello fine-tuned del tenant quando hai abbastanza esempi buoni.',
                'href' => route('settings') . '#fine-tuning',
            ],
        ])->values();

        $setupDone = $setupChecks->filter(fn ($item) => (bool) ($item['ready'] ?? false))->count();
        $setupRate = (int) round(($setupDone / max(1, $setupChecks->count())) * 100);
        $setupMissing = $setupChecks->filter(fn ($item) => !($item['ready'] ?? false))->values();

        return view('settings', [
            'profile' => $profile,
            'assets' => $assets,
            'assetVariablesCount' => $assetVariablesCount,
            'socialAccounts' => $socialAccounts,
            'logosCount' => $logosCount,
            'imagesCount' => $imagesCount,
            'videosCount' => $videosCount,
            'audiosCount' => $audiosCount,
            'activeSocialAccounts' => $activeSocialAccounts,
            'connectedPlatforms' => $connectedPlatforms,
            'setupChecks' => $setupChecks,
            'setupDone' => $setupDone,
            'setupRate' => $setupRate,
            'setupMissing' => $setupMissing,
            'fineTuning' => $fineTuning,
            'metaReady' => !empty(config('meta.app_id')) && !empty(config('meta.app_secret')),
            'metaScopes' => (array) config('meta.scopes', []),
        ]);
    }

    public function startFineTuning(Request $request, TenantFineTuningService $tenantFineTuningService): RedirectResponse
    {
        try {
            $tenantFineTuningService->startRunForTenant((int) $request->user()->tenant_id);

            return redirect()
                ->route('settings')
                ->with('status', 'Fine-tuning avviato: dataset creato, file caricati e job OpenAI in coda.');
        } catch (Throwable $e) {
            return redirect()
                ->route('settings')
                ->with('status', 'Impossibile avviare il fine-tuning: ' . $e->getMessage());
        }
    }

    public function syncFineTuning(Request $request, TenantFineTuningService $tenantFineTuningService): RedirectResponse
    {
        try {
            $run = $tenantFineTuningService->syncLatestRunForTenant((int) $request->user()->tenant_id);
            if (!$run) {
                return redirect()
                    ->route('settings')
                    ->with('status', 'Nessun run di fine-tuning presente per questo tenant.');
            }

            $status = (string) ($run->status ?? 'unknown');
            $model = trim((string) ($run->fine_tuned_model ?? ''));
            $message = 'Stato fine-tuning aggiornato: ' . $status . '.';
            if ($model !== '' && (bool) $run->is_active) {
                $message .= ' Modello attivo: ' . $model . '.';
            }

            return redirect()->route('settings')->with('status', $message);
        } catch (Throwable $e) {
            return redirect()
                ->route('settings')
                ->with('status', 'Impossibile aggiornare il fine-tuning: ' . $e->getMessage());
        }
    }
}