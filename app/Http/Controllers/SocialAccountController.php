<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\Social\MetaGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    public function redirectToMeta(Request $request, MetaGraphService $metaGraphService)
    {
        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return redirect()->away($metaGraphService->loginUrl($state));
    }

    public function handleMetaCallback(
        Request $request,
        MetaGraphService $metaGraphService,
        WorkspaceNotificationService $workspaceNotifications
    )
    {
        $expectedState = (string) $request->session()->pull('meta_oauth_state', '');
        $incomingState = (string) $request->query('state', '');

        if ($expectedState === '' || !hash_equals($expectedState, $incomingState)) {
            abort(403, 'State OAuth non valido.');
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return redirect()->route('settings')->with('status', 'Meta OAuth non ha restituito un codice valido.');
        }

        $tokenPayload = $metaGraphService->exchangeCodeForAccessToken($code);
        $accessToken = trim((string) ($tokenPayload['access_token'] ?? ''));
        $expiresIn = isset($tokenPayload['expires_in']) ? (int) $tokenPayload['expires_in'] : null;

        try {
            $longLived = $metaGraphService->exchangeLongLivedUserToken($accessToken);
            $accessToken = trim((string) ($longLived['access_token'] ?? $accessToken));
            $expiresIn = isset($longLived['expires_in']) ? (int) $longLived['expires_in'] : $expiresIn;
        } catch (\Throwable) {
            // fallback al token scambiato con il code
        }

        $destinations = $metaGraphService->fetchManagedDestinations($accessToken);
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;
        $expiresAt = $expiresIn && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null;

        DB::transaction(function () use ($tenantId, $userId, $destinations, $accessToken, $expiresAt): void {
            $seenKeys = [];

            foreach ($destinations as $destination) {
                if (!is_array($destination)) {
                    continue;
                }

                $platform = trim((string) ($destination['platform'] ?? ''));
                $accountId = trim((string) ($destination['account_id'] ?? ''));
                if ($platform === '' || $accountId === '') {
                    continue;
                }

                $seenKeys[] = $platform . ':' . $accountId;

                $currentAccount = SocialAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'meta')
                    ->where('platform', $platform)
                    ->where('account_id', $accountId)
                    ->first();

                $existingPrimary = SocialAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'meta')
                    ->where('platform', $platform)
                    ->where('is_primary', true)
                    ->exists();

                SocialAccount::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'provider' => 'meta',
                        'platform' => $platform,
                        'account_id' => $accountId,
                    ],
                    [
                        'user_id' => $userId,
                        'status' => 'active',
                        'is_primary' => (bool) ($currentAccount?->is_primary ?? !$existingPrimary),
                        'account_name' => trim((string) ($destination['account_name'] ?? '')),
                        'username' => trim((string) ($destination['username'] ?? '')),
                        'access_token' => trim((string) ($destination['access_token'] ?? $accessToken)),
                        'refresh_token' => null,
                        'token_expires_at' => $expiresAt,
                        'connected_at' => Carbon::now(),
                        'last_synced_at' => Carbon::now(),
                        'last_error' => null,
                        'meta' => (array) ($destination['meta'] ?? []),
                    ]
                );
            }

            $accounts = SocialAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'meta')
                ->get();

            foreach ($accounts as $account) {
                $key = $account->platform . ':' . $account->account_id;
                if (!in_array($key, $seenKeys, true)) {
                    $account->status = 'disconnected';
                    $account->is_primary = false;
                    $account->last_error = 'Account non piu restituito dal sync Meta.';
                    $account->save();
                }
            }

            foreach (['facebook', 'instagram'] as $platform) {
                $hasPrimary = SocialAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'meta')
                    ->where('platform', $platform)
                    ->where('status', 'active')
                    ->where('is_primary', true)
                    ->exists();

                if (!$hasPrimary) {
                    SocialAccount::query()
                        ->where('tenant_id', $tenantId)
                        ->where('provider', 'meta')
                        ->where('platform', $platform)
                        ->where('status', 'active')
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update(['is_primary' => true]);
                }
            }
        });

        $workspaceNotifications->notifyTenant(
            $tenantId,
            'Connessione Meta aggiornata',
            'Gli account Facebook e Instagram sono stati sincronizzati correttamente.',
            [
                'level' => 'success',
                'icon' => 'meta-connected',
                'action_url' => route('settings'),
                'action_label' => 'Apri impostazioni',
                'context_type' => 'meta_connection',
            ]
        );

        return redirect()->route('settings')->with('status', 'Connessione Meta completata e account sincronizzati.');
    }

    public function disconnect(
        Request $request,
        SocialAccount $socialAccount,
        WorkspaceNotificationService $workspaceNotifications
    )
    {
        if ((int) $socialAccount->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }

        $socialAccount->status = 'disconnected';
        $socialAccount->is_primary = false;
        $socialAccount->access_token = null;
        $socialAccount->refresh_token = null;
        $socialAccount->token_expires_at = null;
        $socialAccount->last_error = 'Disconnesso manualmente.';
        $socialAccount->save();

        $workspaceNotifications->notifyTenant(
            (int) $socialAccount->tenant_id,
            'Account social disconnesso',
            strtoupper((string) $socialAccount->platform) . ' e stato scollegato manualmente.',
            [
                'level' => 'warning',
                'icon' => 'meta-disconnected',
                'action_url' => route('settings'),
                'action_label' => 'Apri impostazioni',
                'context_type' => 'social_account',
                'context_id' => (int) $socialAccount->id,
            ]
        );

        return redirect()->route('settings')->with('status', 'Account social disconnesso.');
    }
}
