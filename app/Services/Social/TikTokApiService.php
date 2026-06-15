<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherInterface;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter di pubblicazione per TikTok.
 *
 * Usa il TikTok Content Posting API v2 (Direct Post).
 * La pubblicazione avviene in due fasi:
 *   1. Init → ottieni un upload_url e un publish_id
 *   2. Upload → PUT del video sull'upload_url
 *
 * API di riferimento:
 *   - Content Posting: https://developers.tiktok.com/doc/content-posting-api-get-started
 *   - OAuth2: Authorization Code con scope video.upload + video.publish
 *
 * Note:
 *   - TikTok supporta solo video (non immagini tramite Content Posting API)
 *   - La caption è limitata a 2200 caratteri con max 30 hashtag
 *   - Il video deve essere caricato entro 24h dall'init
 */
class TikTokApiService implements SocialPublisherInterface
{
    private const API_BASE = 'https://open.tiktokapis.com/v2';

    /**
     * Pubblica un video su TikTok.
     *
     * Ignora media_type per image (TikTok Content Posting API gestisce solo video).
     * Per contenuti immagine stampa un warning nei meta e non esegue la publish.
     *
     * @return array<string, mixed>
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array
    {
        $mediaType = strtolower(trim((string) ($publication->media_type ?? 'video')));

        if ($mediaType === 'image') {
            // TikTok Content Posting API non supporta immagini statiche
            throw new RuntimeException(
                'TikTok Content Posting API supporta solo video. '
                . 'Per post immagine usa TikTok Business API (non ancora integrata).'
            );
        }

        return $this->publishVideo($account, $publication);
    }

    /**
     * Costruisce l'URL OAuth2 di autorizzazione TikTok.
     */
    public function loginUrl(string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize?' . http_build_query([
            'client_key'    => $this->clientKey(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(',', $this->scopes()),
            'state'         => $state,
        ]);
    }

    /**
     * Scambia il codice OAuth2 con un access token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key'    => $this->clientKey(),
                'client_secret' => $this->clientSecret(),
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri(),
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('TikTok token exchange fallito: ' . $response->body());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['data']['access_token'])) {
            throw new RuntimeException('TikTok token exchange senza access_token.');
        }

        return $payload['data'];
    }

    /**
     * Recupera le info utente TikTok per costruire il SocialAccount.
     *
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $response = $this->request($accessToken)
            ->post(self::API_BASE . '/user/info/', [
                'fields' => 'open_id,union_id,display_name,avatar_url',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('TikTok fetchUserInfo fallito: ' . $response->body());
        }

        $data = (array) data_get($response->json(), 'data.user', []);

        return [
            'provider'     => 'tiktok',
            'platform'     => 'tiktok',
            'account_id'   => trim((string) ($data['open_id'] ?? '')),
            'account_name' => trim((string) ($data['display_name'] ?? 'TikTok User')),
            'username'     => trim((string) ($data['display_name'] ?? '')),
            'meta'         => [
                'source'    => 'oauth_sync',
                'union_id'  => $data['union_id'] ?? null,
                'avatar'    => $data['avatar_url'] ?? null,
            ],
        ];
    }

    // ─── Pubblicazione video ──────────────────────────────────────────────────

    /**
     * Pubblica un video su TikTok tramite Direct Post (FILE_UPLOAD).
     *
     * Flusso:
     *   1. POST /v2/post/publish/video/init/ → upload_url + publish_id
     *   2. GET  {media_url} → scarica il video
     *   3. PUT  {upload_url} con il file binario
     *
     * @return array<string, mixed>
     */
    private function publishVideo(SocialAccount $account, SocialPublication $publication): array
    {
        $caption = $this->buildTikTokCaption((string) ($publication->caption ?? ''));

        // ── Step 1: init upload ───────────────────────────────────────────────
        $initResponse = $this->request($account->access_token)
            ->post(self::API_BASE . '/post/publish/video/init/', [
                'post_info' => [
                    'title'              => $caption,
                    'privacy_level'      => config('tiktok.default_privacy_level', 'PUBLIC_TO_EVERYONE'),
                    'disable_duet'       => (bool) config('tiktok.disable_duet', false),
                    'disable_comment'    => (bool) config('tiktok.disable_comment', false),
                    'disable_stitch'     => (bool) config('tiktok.disable_stitch', false),
                ],
                'source_info' => [
                    'source'         => 'FILE_UPLOAD',
                    'video_size'     => $this->getRemoteFileSize($publication->media_url),
                    'chunk_size'     => $this->getRemoteFileSize($publication->media_url),
                    'total_chunk_count' => 1,
                ],
            ]);

        if (!$initResponse->successful()) {
            throw new RuntimeException('TikTok post init fallito: ' . $initResponse->body());
        }

        $uploadUrl = trim((string) data_get($initResponse->json(), 'data.upload_url', ''));
        $publishId = trim((string) data_get($initResponse->json(), 'data.publish_id', ''));

        if ($uploadUrl === '' || $publishId === '') {
            throw new RuntimeException('TikTok post init: upload_url o publish_id mancante.');
        }

        // ── Step 2: scarica e carica il video ─────────────────────────────────
        $videoContent = Http::timeout(120)->get($publication->media_url)->body();
        $videoSize    = strlen($videoContent);

        $uploadResponse = Http::withHeaders([
            'Content-Type'        => 'video/mp4',
            'Content-Length'      => (string) $videoSize,
            'Content-Range'       => "bytes 0-{$videoSize}/{$videoSize}",
        ])->timeout(180)->withBody($videoContent, 'video/mp4')->put($uploadUrl);

        // TikTok restituisce 2xx senza body per l'upload riuscito
        if (!$uploadResponse->successful()) {
            throw new RuntimeException(
                'TikTok video upload fallito (status ' . $uploadResponse->status() . ').'
            );
        }

        return [
            'remote_id'  => $publishId,
            'remote_url' => null, // TikTok non restituisce l'URL del post al momento della publish
            'meta'       => [
                'publish_id' => $publishId,
                'status'     => 'processing',
            ],
        ];
    }

    /**
     * Riduce la caption a 2200 caratteri (limite TikTok).
     * Preserva gli hashtag in fondo.
     */
    private function buildTikTokCaption(string $caption): string
    {
        if (mb_strlen($caption) <= 2200) {
            return $caption;
        }

        return mb_substr($caption, 0, 2197) . '…';
    }

    /**
     * Ottiene la dimensione di un file remoto tramite HTTP HEAD request.
     * Necessario per TikTok init che richiede video_size esplicito.
     * Fallback a 0 se il server non restituisce Content-Length.
     */
    private function getRemoteFileSize(string $url): int
    {
        try {
            $response = Http::timeout(10)->head($url);
            return (int) ($response->header('Content-Length') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ]);
    }

    private function clientKey(): string
    {
        $value = trim((string) config('tiktok.client_key', ''));
        if ($value === '') {
            throw new RuntimeException('TIKTOK_CLIENT_KEY non configurato.');
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = trim((string) config('tiktok.client_secret', ''));
        if ($value === '') {
            throw new RuntimeException('TIKTOK_CLIENT_SECRET non configurato.');
        }

        return $value;
    }

    private function redirectUri(): string
    {
        $uri = trim((string) config('tiktok.redirect_uri', ''));

        return $uri !== '' ? $uri : route('settings.social.tiktok.callback');
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return array_values((array) config('tiktok.scopes', ['video.upload', 'video.publish']));
    }
}
