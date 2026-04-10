<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherInterface;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter di pubblicazione per Google Business Profile (ex Google My Business).
 *
 * Pubblica "Local Posts" sulla scheda Google My Business del tenant,
 * visibili direttamente nella ricerca Google e Google Maps.
 *
 * API di riferimento:
 *   - Local Posts: https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts
 *   - OAuth2: Authorization Code con scope https://www.googleapis.com/auth/business.manage
 *
 * Tipi di Local Post supportati:
 *   - STANDARD    → post generico con testo e immagine
 *   - EVENT       → evento con titolo, data inizio/fine (non implementato: richiede dati aggiuntivi)
 *   - OFFER       → offerta promozionale (non implementato: richiede coupon code)
 *
 * Flusso pubblicazione:
 *   1. Il tenant autentica il proprio Google Business con OAuth2
 *   2. SocialAccount.account_id = locationName (accounts/{accountId}/locations/{locationId})
 *   3. POST /{locationName}/localPosts con summary + optional media
 *
 * Prerequisito: il tenant deve avere verificata la propria location su Google Business.
 */
class GoogleBusinessApiService implements SocialPublisherInterface
{
    private const API_BASE = 'https://mybusiness.googleapis.com/v4';

    /**
     * Pubblica un local post sulla scheda Google My Business.
     *
     * @return array<string, mixed>
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array
    {
        $locationName = $this->resolveLocationName($account);
        $mediaType    = strtolower(trim((string) ($publication->media_type ?? '')));

        $postBody = [
            'languageCode' => trim((string) config('google_business.language_code', 'it')),
            'summary'      => $this->truncateSummary((string) ($publication->caption ?? '')),
            'topicType'    => 'STANDARD',
        ];

        // Allega media se disponibile
        if (!empty($publication->media_url)) {
            $postBody['media'][] = [
                'mediaFormat' => $mediaType === 'video' ? 'VIDEO' : 'PHOTO',
                'sourceUrl'   => $publication->media_url,
            ];
        }

        // Allega call-to-action se configurata nel payload
        $ctaType    = data_get($publication->payload, 'google_cta_type', '');
        $ctaUrl     = data_get($publication->payload, 'google_cta_url', '');
        if (!empty($ctaType) && !empty($ctaUrl)) {
            $postBody['callToAction'] = [
                'actionType' => strtoupper((string) $ctaType),
                'url'        => (string) $ctaUrl,
            ];
        }

        $response = $this->request($account->access_token)
            ->post(self::API_BASE . '/' . $locationName . '/localPosts', $postBody);

        if (!$response->successful()) {
            throw new RuntimeException('Google Business post fallito: ' . $response->body());
        }

        $data = (array) $response->json();
        $name = trim((string) ($data['name'] ?? ''));

        return [
            'remote_id'  => $name,
            'remote_url' => trim((string) ($data['searchUrl'] ?? '')) ?: null,
            'meta'       => $data,
        ];
    }

    /**
     * Costruisce l'URL di autorizzazione OAuth2 Google.
     */
    public function loginUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $this->redirectUri(),
            'response_type'         => 'code',
            'scope'                 => implode(' ', $this->scopes()),
            'state'                 => $state,
            'access_type'           => 'offline',   // richiede refresh_token
            'prompt'                => 'consent',   // forza consent per ottenere refresh_token
        ]);
    }

    /**
     * Scambia il codice OAuth2 con un access + refresh token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://oauth2.googleapis.com/token', [
                'code'          => $code,
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'grant_type'    => 'authorization_code',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Google token exchange fallito: ' . $response->body());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            throw new RuntimeException('Google token exchange senza access_token.');
        }

        return $payload;
    }

    /**
     * Rinnova l'access token usando il refresh token.
     * Da chiamare quando `token_expires_at` è scaduto.
     *
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://oauth2.googleapis.com/token', [
                'refresh_token' => $refreshToken,
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type'    => 'refresh_token',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Google token refresh fallito: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Lista gli account Google Business accessibili dall'utente.
     * Ogni account può avere più locations (sedi fisiche).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAccounts(string $accessToken): array
    {
        $response = $this->request($accessToken)
            ->get(self::API_BASE . '/accounts');

        if (!$response->successful()) {
            throw new RuntimeException('Google Business fetchAccounts fallito: ' . $response->body());
        }

        return (array) data_get($response->json(), 'accounts', []);
    }

    /**
     * Lista le locations (sedi) di un account Google Business.
     * Ogni location è il "punto di pubblicazione" dei Local Posts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchLocations(string $accessToken, string $accountName): array
    {
        $response = $this->request($accessToken)
            ->get(self::API_BASE . '/' . $accountName . '/locations', [
                'readMask' => 'name,title,websiteUri,regularHours,phoneNumbers',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Google Business fetchLocations fallito: ' . $response->body());
        }

        $locations = (array) data_get($response->json(), 'locations', []);
        $destinations = [];

        foreach ($locations as $loc) {
            $locationName = trim((string) ($loc['name'] ?? ''));
            if ($locationName === '') {
                continue;
            }

            $destinations[] = [
                'provider'     => 'google',
                'platform'     => 'google_business',
                'account_id'   => $locationName,
                'account_name' => trim((string) ($loc['title'] ?? 'Google Business Location')),
                'username'     => $locationName,
                'meta'         => [
                    'source'        => 'oauth_sync',
                    'account_name'  => $accountName,
                    'website'       => $loc['websiteUri'] ?? null,
                ],
            ];
        }

        return $destinations;
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Risolve il locationName dall'account.
     * Google My Business usa il formato "accounts/{id}/locations/{id}" come identificatore.
     */
    private function resolveLocationName(SocialAccount $account): string
    {
        $locationName = trim((string) ($account->account_id ?? ''));
        if ($locationName === '') {
            throw new RuntimeException('Google Business: locationName mancante nell\'account.');
        }

        return $locationName;
    }

    /**
     * La summary di un Local Post è limitata a 1500 caratteri su Google Business.
     */
    private function truncateSummary(string $text): string
    {
        if (mb_strlen($text) <= 1500) {
            return $text;
        }

        return mb_substr($text, 0, 1497) . '…';
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ]);
    }

    private function clientId(): string
    {
        $value = trim((string) config('google_business.client_id', ''));
        if ($value === '') {
            throw new RuntimeException('GOOGLE_BUSINESS_CLIENT_ID non configurato.');
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = trim((string) config('google_business.client_secret', ''));
        if ($value === '') {
            throw new RuntimeException('GOOGLE_BUSINESS_CLIENT_SECRET non configurato.');
        }

        return $value;
    }

    private function redirectUri(): string
    {
        $uri = trim((string) config('google_business.redirect_uri', ''));

        return $uri !== '' ? $uri : route('settings.social.google.callback');
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return array_values((array) config('google_business.scopes', [
            'https://www.googleapis.com/auth/business.manage',
        ]));
    }
}
