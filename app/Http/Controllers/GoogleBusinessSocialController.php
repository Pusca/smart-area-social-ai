<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\Social\GoogleBusinessApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestisce il flusso OAuth2 Google Business Profile e la connessione degli account.
 *
 * Google richiede `access_type=offline` e `prompt=consent` per ottenere
 * il refresh_token (necessario per rinnovare l'accesso automaticamente).
 *
 * Ogni "location" Google Business è un account pubblicabile separato:
 * uno stesso utente Google può gestire più sedi fisiche.
 */
class GoogleBusinessSocialController extends Controller
{
    public function __construct(
        private readonly GoogleBusinessApiService $googleBusinessApi,
        private readonly WorkspaceNotificationService $workspaceNotifications
    ) {
    }

    /**
     * Avvia il flusso OAuth2 Google.
     */
    public function redirectToGoogle(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('google_business_oauth_state', $state);

        return redirect()->away($this->googleBusinessApi->loginUrl($state));
    }

    /**
     * Riceve il callback OAuth2 da Google.
     * Sincronizza tutti gli account Google Business accessibili.
     */
    public function handleGoogleCallback(Request $request)
    {
        // ── Verifica CSRF state ───────────────────────────────────────────────
        $expectedState = (string) $request->session()->pull('google_business_oauth_state', '');
        $incomingState = (string) $request->query('state', '');

        if ($expectedState === '' || !hash_equals($expectedState, $incomingState)) {
            abort(403, 'State OAuth Google non valido.');
        }

        // ── Verifica errori OAuth ─────────────────────────────────────────────
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()->route('settings')
                ->with('status', 'Google OAuth non completato: ' . $error);
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return redirect()->route('settings')
                ->with('status', 'Google OAuth non ha restituito un codice valido.');
        }

        // ── Scambia il codice con access + refresh token ──────────────────────
        try {
            $tokenPayload = $this->googleBusinessApi->exchangeCodeForToken($code);
        } catch (\Throwable $e) {
            return redirect()->route('settings')
                ->with('status', 'Google token exchange fallito: ' . $e->getMessage());
        }

        $accessToken  = trim((string) ($tokenPayload['access_token'] ?? ''));
        $refreshToken = trim((string) ($tokenPayload['refresh_token'] ?? ''));
        $expiresIn    = isset($tokenPayload['expires_in']) ? (int) $tokenPayload['expires_in'] : null;
        $expiresAt    = $expiresIn && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null;

        // ── Recupera gli account Google Business ──────────────────────────────
        try {
            $googleAccounts = $this->googleBusinessApi->fetchAccounts($accessToken);
        } catch (\Throwable $e) {
            return redirect()->route('settings')
                ->with('status', 'Google fetchAccounts fallito: ' . $e->getMessage());
        }

        $destinations = [];

        foreach ($googleAccounts as $googleAccount) {
            $accountName = trim((string) ($googleAccount['name'] ?? ''));
            if ($accountName === '') {
                continue;
            }

            try {
                $locations = $this->googleBusinessApi->fetchLocations($accessToken, $accountName);
                foreach ($locations as $location) {
                    $destinations[] = array_merge($location, [
                        'access_token'  => $accessToken,
                        'refresh_token' => $refreshToken,
                    ]);
                }
            } catch (\Throwable) {
                // Se una location fallisce, continua con le altre
            }
        }

        if (empty($destinations)) {
            return redirect()->route('settings')
                ->with('status', 'Google Business: nessuna location trovata. Verifica che tu abbia accesso a una sede su Google Business Profile.');
        }

        $tenantId = (int) $request->user()->tenant_id;
        $userId   = (int) $request->user()->id;

        // ── Salva/aggiorna i SocialAccount ────────────────────────────────────
        DB::transaction(function () use (
            $tenantId, $userId, $destinations, $expiresAt
        ): void {
            $seenAccountIds = [];

            foreach ($destinations as $dest) {
                $accountId = trim((string) ($dest['account_id'] ?? ''));
                if ($accountId === '') {
                    continue;
                }

                $seenAccountIds[] = $accountId;

                $existingPrimary = SocialAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'google')
                    ->where('platform', 'google_business')
                    ->where('is_primary', true)
                    ->exists();

                SocialAccount::query()->updateOrCreate(
                    [
                        'tenant_id'  => $tenantId,
                        'provider'   => 'google',
                        'platform'   => 'google_business',
                        'account_id' => $accountId,
                    ],
                    [
                        'user_id'          => $userId,
                        'status'           => 'active',
                        'is_primary'       => !$existingPrimary,
                        'account_name'     => $dest['account_name'] ?? 'Google Business',
                        'username'         => $dest['username'] ?? $accountId,
                        'access_token'     => $dest['access_token'] ?? '',
                        // Preserva il refresh_token esistente se non ne arriva uno nuovo
                        'refresh_token'    => ($dest['refresh_token'] ?? '') ?: null,
                        'token_expires_at' => $expiresAt,
                        'connected_at'     => Carbon::now(),
                        'last_synced_at'   => Carbon::now(),
                        'last_error'       => null,
                        'meta'             => (array) ($dest['meta'] ?? []),
                    ]
                );
            }

            // Disconnette locations non più accessibili
            SocialAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'google')
                ->whereNotIn('account_id', $seenAccountIds)
                ->update([
                    'status'     => 'disconnected',
                    'is_primary' => false,
                    'last_error' => 'Location non più restituita dal sync Google Business.',
                ]);
        });

        $this->workspaceNotifications->notifyTenant(
            $tenantId,
            'Google Business connesso',
            'Le tue sedi Google Business sono state sincronizzate correttamente.',
            [
                'level'        => 'success',
                'icon'         => 'google-connected',
                'action_url'   => route('settings'),
                'action_label' => 'Apri impostazioni',
                'context_type' => 'google_connection',
            ]
        );

        $count = count($destinations);

        return redirect()->route('settings')
            ->with('status', "Google Business connesso: {$count} " . ($count === 1 ? 'sede sincronizzata' : 'sedi sincronizzate') . '.');
    }
}
