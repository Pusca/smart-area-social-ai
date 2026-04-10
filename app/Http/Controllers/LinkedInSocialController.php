<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\Social\LinkedInApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestisce il flusso OAuth2 LinkedIn e la connessione degli account social.
 *
 * Flusso:
 *   1. redirectToLinkedIn()  → reindirizza l'utente alla pagina di autorizzazione LinkedIn
 *   2. handleLinkedInCallback() → riceve il codice, scambia il token,
 *                                 recupera profilo + pagine aziendali,
 *                                 salva/aggiorna i SocialAccount
 */
class LinkedInSocialController extends Controller
{
    public function __construct(
        private readonly LinkedInApiService $linkedInApi,
        private readonly WorkspaceNotificationService $workspaceNotifications
    ) {
    }

    /**
     * Avvia il flusso OAuth2: genera lo state CSRF e reindirizza a LinkedIn.
     */
    public function redirectToLinkedIn(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('linkedin_oauth_state', $state);

        return redirect()->away($this->linkedInApi->loginUrl($state));
    }

    /**
     * Riceve il callback OAuth2 da LinkedIn.
     * Scambia il codice con il token, sincronizza gli account e salva su DB.
     */
    public function handleLinkedInCallback(Request $request)
    {
        // ── Verifica CSRF state ───────────────────────────────────────────────
        $expectedState = (string) $request->session()->pull('linkedin_oauth_state', '');
        $incomingState = (string) $request->query('state', '');

        if ($expectedState === '' || !hash_equals($expectedState, $incomingState)) {
            abort(403, 'State OAuth LinkedIn non valido.');
        }

        // ── Verifica errori OAuth ─────────────────────────────────────────────
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()->route('settings')
                ->with('status', 'LinkedIn OAuth non completato: ' . $request->query('error_description', $error));
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return redirect()->route('settings')->with('status', 'LinkedIn OAuth non ha restituito un codice valido.');
        }

        // ── Scambia il codice con il token ────────────────────────────────────
        try {
            $tokenPayload = $this->linkedInApi->exchangeCodeForToken($code);
        } catch (\Throwable $e) {
            return redirect()->route('settings')->with('status', 'LinkedIn token exchange fallito: ' . $e->getMessage());
        }

        $accessToken = trim((string) ($tokenPayload['access_token'] ?? ''));
        $expiresIn   = isset($tokenPayload['expires_in']) ? (int) $tokenPayload['expires_in'] : null;
        $expiresAt   = $expiresIn && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null;

        $tenantId = (int) $request->user()->tenant_id;
        $userId   = (int) $request->user()->id;

        // ── Raccoglie destinations: profilo personale + pagine aziendali ──────
        $destinations = [];

        try {
            $profile = $this->linkedInApi->fetchMemberProfile($accessToken);
            $destinations[] = array_merge($profile, ['access_token' => $accessToken]);
        } catch (\Throwable $e) {
            return redirect()->route('settings')
                ->with('status', 'LinkedIn fetchMemberProfile fallito: ' . $e->getMessage());
        }

        try {
            $organizations = $this->linkedInApi->fetchOrganizations($accessToken);
            foreach ($organizations as $org) {
                $destinations[] = array_merge($org, ['access_token' => $accessToken]);
            }
        } catch (\Throwable) {
            // Non fatale: l'utente potrebbe non gestire pagine aziendali
        }

        // ── Salva/aggiorna i SocialAccount sul DB ─────────────────────────────
        DB::transaction(function () use ($tenantId, $userId, $destinations, $expiresAt): void {
            $seenAccountIds = [];

            foreach ($destinations as $dest) {
                $accountId = trim((string) ($dest['account_id'] ?? ''));
                if ($accountId === '') {
                    continue;
                }

                $seenAccountIds[] = $accountId;

                $existingPrimary = SocialAccount::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', 'linkedin')
                    ->where('platform', 'linkedin')
                    ->where('is_primary', true)
                    ->exists();

                SocialAccount::query()->updateOrCreate(
                    [
                        'tenant_id'  => $tenantId,
                        'provider'   => 'linkedin',
                        'platform'   => 'linkedin',
                        'account_id' => $accountId,
                    ],
                    [
                        'user_id'         => $userId,
                        'status'          => 'active',
                        'is_primary'      => !$existingPrimary,
                        'account_name'    => trim((string) ($dest['account_name'] ?? '')),
                        'username'        => trim((string) ($dest['username'] ?? '')),
                        'access_token'    => trim((string) ($dest['access_token'] ?? '')),
                        'refresh_token'   => null,
                        'token_expires_at' => $expiresAt,
                        'connected_at'    => Carbon::now(),
                        'last_synced_at'  => Carbon::now(),
                        'last_error'      => null,
                        'meta'            => (array) ($dest['meta'] ?? []),
                    ]
                );
            }

            // Disconnette account non più presenti nel sync
            SocialAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'linkedin')
                ->whereNotIn('account_id', $seenAccountIds)
                ->update([
                    'status'     => 'disconnected',
                    'is_primary' => false,
                    'last_error' => 'Account non più restituito dal sync LinkedIn.',
                ]);
        });

        $this->workspaceNotifications->notifyTenant(
            $tenantId,
            'LinkedIn connesso',
            'I tuoi account LinkedIn sono stati sincronizzati correttamente.',
            [
                'level'        => 'success',
                'icon'         => 'linkedin-connected',
                'action_url'   => route('settings'),
                'action_label' => 'Apri impostazioni',
                'context_type' => 'linkedin_connection',
            ]
        );

        return redirect()->route('settings')->with('status', 'Connessione LinkedIn completata e account sincronizzati.');
    }
}
