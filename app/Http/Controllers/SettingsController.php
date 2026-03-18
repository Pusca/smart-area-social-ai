<?php

namespace App\Http\Controllers;

use App\Models\AssetVariable;
use App\Models\BrandAsset;
use App\Models\SocialAccount;
use App\Models\TenantProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
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
        $activeSocialAccounts = $socialAccounts->where('status', 'active');
        $connectedPlatforms = $activeSocialAccounts->pluck('platform')->filter()->unique()->values();

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
                'label' => 'Materiali visual',
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
            'activeSocialAccounts' => $activeSocialAccounts,
            'connectedPlatforms' => $connectedPlatforms,
            'setupChecks' => $setupChecks,
            'setupDone' => $setupDone,
            'setupRate' => $setupRate,
            'setupMissing' => $setupMissing,
            'metaReady' => !empty(config('meta.app_id')) && !empty(config('meta.app_secret')),
            'metaScopes' => (array) config('meta.scopes', []),
        ]);
    }
}
