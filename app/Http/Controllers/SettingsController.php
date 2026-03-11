<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = (int) $request->user()->tenant_id;

        $socialAccounts = SocialAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_primary')
            ->orderBy('platform')
            ->orderBy('account_name')
            ->get();

        return view('settings', [
            'socialAccounts' => $socialAccounts,
            'metaReady' => !empty(config('meta.app_id')) && !empty(config('meta.app_secret')),
            'metaScopes' => (array) config('meta.scopes', []),
        ]);
    }
}
