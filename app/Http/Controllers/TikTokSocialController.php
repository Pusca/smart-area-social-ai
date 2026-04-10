<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\Social\TikTokApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestisce il flusso OAuth2 TikTok e la connessione degli account social.
 *
 * TikTok usa un flusso OAuth2 leggermente diverso:
 *   - Il parametro app id si chiama `client_key` (non client_id)
 *   - Il token endpoint risponde con data.access_token (non direttamente)
 *   - L'URL di autorizzazione usa `https://www.tiktok.com/v2/auth/authorize`
 */
class TikTokSocialController extends Controller
{
    public function __construct(
        private readonly TikTokApiService $tikTokApi,
        private readonly WorkspaceNotificationService $workspaceNotifications
    ) {
    }

    /**
     * Avvia il flusso OAuth2 TikTok.
     */
    public function redirectToTikTok(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('tiktok_oauth_state', $state);

        return redirect()->away($this->tikTokApi->loginUrl($state));
    }

    /**
     * Riceve il callback OAuth2 da TikTok.
     */
    public function handleTikTokCallback(Request $request)
    {
        // ── Verifica CSRF state ───────────────────────────────────────────────
        $expectedState = (string) $request->session()->pull('tiktok_oauth_state', '');
        $incomingState = (string) $request->query('state', '');

        if ($expectedState === '' || !hash_equals($expectedState, $incomingState)) {
            abort(403, 'State OAuth TikTok non valido.');
        }

        // ── Verifica errori OAuth ─────────────────────────────────────────────
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()->route('settings')
                ->with('status', 'TikTok OAuth non completato: ' . $request->query('error_description', $error));
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return redirect()->route('settings')->with('status', 'TikTok OAuth non ha restituito un codice valido.');
        }

        // ── Scambia il codice con il token ────────────────────────────────────
        try {
            $tokenPayload = $this->tikTokApi->exchangeCodeForToken($code);
        } catch (\Throwable $e) {
            return redirect()->route('settings')
                ->with('status', 'TikTok token exchange fallito: ' . $e->getMessage());
        }

        $accessToken  = trim((string) ($tokenPayload['access_token'] ?? ''));
        $refreshToken = trim((string) ($tokenPayload['refresh_token'] ?? ''));
        $expiresIn    = isset($tokenPayload['expires_in']) ? (int) $tokenPayload['expires_in'] : null;
        $expiresAt    = $expiresIn && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null;

        // ── Recupera info utente ──────────────────────────────────────────────
        try {
            $userInfo = $this->tikTokApi->fetchUserInfo($accessToken);
        } catch (\Throwable $e) {
            return redirect()->route('settings')
                ->with('status', 'TikTok fetchUserInfo fallito: ' . $e->getMessage());
        }

        $tenantId = (int) $request->user()->tenant_id;
        $userId   = (int) $request->user()->id;
        $accountId = trim((string) ($userInfo['account_id'] ?? ''));

        if ($accountId === '') {
            return redirect()->route('settings')->with('status', 'TikTok: open_id mancante.');
        }

        // ── Salva/aggiorna il SocialAccount ───────────────────────────────────
        DB::transaction(function () use (
            $tenantId, $userId, $accountId, $accessToken, $refreshToken, $expiresAt, $userInfo
        ): void {
            $existingPrimary = SocialAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'tiktok')
                ->where('platform', 'tiktok')
                ->where('is_primary', true)
                ->exists();

            SocialAccount::query()->updateOrCreate(
                [
                    'tenant_id'  => $tenantId,
                    'provider'   => 'tiktok',
                    'platform'   => 'tiktok',
                    'account_id' => $accountId,
                ],
                [
                    'user_id'          => $userId,
                    'status'           => 'active',
                    'is_primary'       => !$existingPrimary,
                    'account_name'     => $userInfo['account_name'] ?? 'TikTok User',
                    'username'         => $userInfo['username'] ?? $accountId,
                    'access_token'     => $accessToken,
                    'refresh_token'    => $refreshToken ?: null,
                    'token_expires_at' => $expiresAt,
                    'connected_at'     => Carbon::now(),
                    'last_synced_at'   => Carbon::now(),
                    'last_error'       => null,
                    'meta'             => (array) ($userInfo['meta'] ?? []),
                ]
            );
        });

        $this->workspaceNotifications->notifyTenant(
            $tenantId,
            'TikTok connesso',
            'Il tuo account TikTok è stato collegato correttamente.',
            [
                'level'        => 'success',
                'icon'         => 'tiktok-connected',
                'action_url'   => route('settings'),
                'action_label' => 'Apri impostazioni',
                'context_type' => 'tiktok_connection',
            ]
        );

        return redirect()->route('settings')->with('status', 'Connessione TikTok completata.');
    }
}
