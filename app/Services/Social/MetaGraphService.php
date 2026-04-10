<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherInterface;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaGraphService implements SocialPublisherInterface
{
    public function loginUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
        ]);

        return 'https://www.facebook.com/' . $this->graphVersion() . '/dialog/oauth?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCodeForAccessToken(string $code): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->get($this->oauthUrl('/oauth/access_token'), [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Meta OAuth token exchange fallito: ' . $response->body());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            throw new RuntimeException('Meta OAuth token exchange senza access_token.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeLongLivedUserToken(string $shortLivedToken): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->get($this->oauthUrl('/oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'fb_exchange_token' => $shortLivedToken,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Meta long-lived token exchange fallito: ' . $response->body());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            throw new RuntimeException('Meta long-lived token exchange senza access_token.');
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchManagedDestinations(string $userAccessToken): array
    {
        $response = $this->request($userAccessToken)->get($this->graphUrl('/me/accounts'), [
            'fields' => 'id,name,username,access_token,instagram_business_account{id,username},tasks',
            'limit' => 200,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Meta fetch accounts fallito: ' . $response->body());
        }

        $data = (array) data_get($response->json(), 'data', []);
        $destinations = [];

        foreach ($data as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = trim((string) ($page['id'] ?? ''));
            $pageToken = trim((string) ($page['access_token'] ?? ''));
            if ($pageId === '' || $pageToken === '') {
                continue;
            }

            $destinations[] = [
                'provider' => 'meta',
                'platform' => 'facebook',
                'account_id' => $pageId,
                'account_name' => trim((string) ($page['name'] ?? 'Facebook Page')),
                'username' => trim((string) ($page['username'] ?? '')),
                'access_token' => $pageToken,
                'meta' => [
                    'page_id' => $pageId,
                    'tasks' => (array) ($page['tasks'] ?? []),
                    'source' => 'oauth_sync',
                ],
            ];

            $ig = is_array($page['instagram_business_account'] ?? null)
                ? $page['instagram_business_account']
                : null;

            if ($ig && !empty($ig['id'])) {
                $destinations[] = [
                    'provider' => 'meta',
                    'platform' => 'instagram',
                    'account_id' => trim((string) $ig['id']),
                    'account_name' => trim((string) ($page['name'] ?? 'Instagram Business')),
                    'username' => trim((string) ($ig['username'] ?? '')),
                    'access_token' => $pageToken,
                    'meta' => [
                        'page_id' => $pageId,
                        'page_name' => trim((string) ($page['name'] ?? '')),
                        'source' => 'oauth_sync',
                    ],
                ];
            }
        }

        return $destinations;
    }

    /**
     * Pubblica su Facebook o Instagram, scegliendo il metodo corretto
     * in base alla piattaforma e al tipo di media (image / video / carousel).
     *
     * @return array<string, mixed>
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array
    {
        $mediaType  = (string) ($publication->media_type ?? 'image');
        $isCarousel = $mediaType === 'carousel'
            || !empty(data_get($publication->payload, 'carousel_images'));

        return match ($account->platform) {
            'facebook' => $mediaType === 'video'
                ? $this->publishFacebookVideo($account, $publication)
                : $this->publishFacebookImage($account, $publication),

            'instagram' => match (true) {
                $isCarousel    => $this->publishInstagramCarousel($account, $publication),
                $mediaType === 'video' => $this->publishInstagramVideo($account, $publication),
                default        => $this->publishInstagramImage($account, $publication),
            },

            default => throw new RuntimeException('Piattaforma Meta non supportata: ' . $account->platform),
        };
    }

    /**
     * Pubblica un carosello Instagram (2–10 immagini/video).
     *
     * Flusso API:
     *   1. Per ogni immagine: POST /{ig-id}/media con is_carousel_item=true → child container id
     *   2. POST /{ig-id}/media con media_type=CAROUSEL, children=[id1,id2,...], caption
     *   3. POST /{ig-id}/media_publish con creation_id=carousel_container_id
     *
     * Le URL delle immagini vengono lette da payload.carousel_images[] o
     * da media_url (singola) come fallback.
     *
     * @return array<string, mixed>
     */
    private function publishInstagramCarousel(SocialAccount $account, SocialPublication $publication): array
    {
        // ── Raccoglie le URL delle slide ─────────────────────────────────────
        $carouselImages = (array) data_get($publication->payload, 'carousel_images', []);

        if (empty($carouselImages) && !empty($publication->media_url)) {
            // Fallback: trata media_url come unica slide (degrada a post singolo)
            $carouselImages = [$publication->media_url];
        }

        if (count($carouselImages) < 2) {
            // Instagram richiede almeno 2 slide per il carosello
            // Fallback a post singolo con la prima immagine disponibile
            $publication->media_url = (string) ($carouselImages[0] ?? $publication->media_url);

            return $this->publishInstagramImage($account, $publication);
        }

        // ── Step 1: crea un child container per ogni slide (max 10) ──────────
        $childIds = [];
        foreach (array_slice($carouselImages, 0, 10) as $imageUrl) {
            $container = $this->request($account->access_token)
                ->post($this->graphUrl('/' . $account->account_id . '/media'), [
                    'image_url'          => (string) $imageUrl,
                    'is_carousel_item'   => 'true',
                ]);

            if (!$container->successful()) {
                throw new RuntimeException(
                    'Instagram carousel child container fallito: ' . $container->body()
                );
            }

            $childId = trim((string) data_get($container->json(), 'id', ''));
            if ($childId === '') {
                throw new RuntimeException('Instagram carousel child container senza id.');
            }

            $childIds[] = $childId;
        }

        // ── Step 2: crea il container del carosello ───────────────────────────
        $carouselContainer = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/media'), [
                'media_type' => 'CAROUSEL',
                'children'   => implode(',', $childIds),
                'caption'    => (string) ($publication->caption ?? ''),
            ]);

        if (!$carouselContainer->successful()) {
            throw new RuntimeException(
                'Instagram carousel container fallito: ' . $carouselContainer->body()
            );
        }

        $carouselId = trim((string) data_get($carouselContainer->json(), 'id', ''));
        if ($carouselId === '') {
            throw new RuntimeException('Instagram carousel container senza id.');
        }

        // ── Step 3: pubblica il carosello ─────────────────────────────────────
        return $this->publishInstagramContainer($account, $carouselId);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishFacebookImage(SocialAccount $account, SocialPublication $publication): array
    {
        $response = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/photos'), [
                'url' => $publication->media_url,
                'message' => (string) ($publication->caption ?? ''),
                'published' => 'true',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Facebook image publish fallito: ' . $response->body());
        }

        $payload = $response->json();
        $id = trim((string) ($payload['post_id'] ?? $payload['id'] ?? ''));

        return [
            'remote_id' => $id,
            'remote_url' => $id !== '' ? 'https://www.facebook.com/' . $id : null,
            'meta' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishFacebookVideo(SocialAccount $account, SocialPublication $publication): array
    {
        $response = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/videos'), [
                'file_url' => $publication->media_url,
                'description' => (string) ($publication->caption ?? ''),
                'title' => (string) data_get($publication->payload, 'title', 'Video pubblicato da Social AI'),
                'published' => 'true',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Facebook video publish fallito: ' . $response->body());
        }

        $payload = $response->json();
        $id = trim((string) ($payload['id'] ?? ''));

        return [
            'remote_id' => $id,
            'remote_url' => $id !== '' ? 'https://www.facebook.com/' . $id : null,
            'meta' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishInstagramImage(SocialAccount $account, SocialPublication $publication): array
    {
        $container = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/media'), [
                'image_url' => $publication->media_url,
                'caption' => (string) ($publication->caption ?? ''),
            ]);

        if (!$container->successful()) {
            throw new RuntimeException('Instagram media container image fallito: ' . $container->body());
        }

        $containerId = trim((string) data_get($container->json(), 'id', ''));
        if ($containerId === '') {
            throw new RuntimeException('Instagram media container image senza id.');
        }

        return $this->publishInstagramContainer($account, $containerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishInstagramVideo(SocialAccount $account, SocialPublication $publication): array
    {
        $container = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/media'), [
                'media_type' => 'REELS',
                'video_url' => $publication->media_url,
                'caption' => (string) ($publication->caption ?? ''),
                'share_to_feed' => 'true',
            ]);

        if (!$container->successful()) {
            throw new RuntimeException('Instagram media container video fallito: ' . $container->body());
        }

        $containerId = trim((string) data_get($container->json(), 'id', ''));
        if ($containerId === '') {
            throw new RuntimeException('Instagram media container video senza id.');
        }

        $this->waitForInstagramContainer($account->access_token, $containerId);

        return $this->publishInstagramContainer($account, $containerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishInstagramContainer(SocialAccount $account, string $containerId): array
    {
        $publish = $this->request($account->access_token)
            ->post($this->graphUrl('/' . $account->account_id . '/media_publish'), [
                'creation_id' => $containerId,
            ]);

        if (!$publish->successful()) {
            throw new RuntimeException('Instagram media publish fallito: ' . $publish->body());
        }

        $payload = $publish->json();
        $id = trim((string) data_get($payload, 'id', ''));

        return [
            'remote_id' => $id,
            'remote_url' => $id !== '' ? 'https://www.instagram.com/p/' . $id . '/' : null,
            'meta' => is_array($payload) ? $payload : [],
        ];
    }

    private function waitForInstagramContainer(string $accessToken, string $containerId): void
    {
        $deadline = time() + max(10, (int) config('meta.instagram_container_poll_seconds', 120));
        $interval = max(2, (int) config('meta.instagram_container_poll_interval', 5));

        do {
            $response = $this->request($accessToken)
                ->get($this->graphUrl('/' . $containerId), [
                    'fields' => 'status_code',
                ]);

            if (!$response->successful()) {
                throw new RuntimeException('Instagram container polling fallito: ' . $response->body());
            }

            $status = strtoupper(trim((string) data_get($response->json(), 'status_code', 'FINISHED')));
            if (in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram container non pubblicabile: ' . $status);
            }

            if (time() >= $deadline) {
                throw new RuntimeException('Timeout preparazione container Instagram.');
            }

            sleep($interval);
        } while (true);
    }

    private function request(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson()->timeout(60);

        if (is_string($accessToken) && trim($accessToken) !== '') {
            $request = $request->withToken($accessToken);
        }

        return $request;
    }

    private function oauthUrl(string $path): string
    {
        return rtrim('https://graph.facebook.com', '/') . $path;
    }

    private function graphUrl(string $path): string
    {
        return rtrim((string) config('meta.graph_base_url', 'https://graph.facebook.com'), '/')
            . '/' . $this->graphVersion() . $path;
    }

    private function graphVersion(): string
    {
        return trim((string) config('meta.graph_version', 'v22.0'));
    }

    private function redirectUri(): string
    {
        $uri = trim((string) config('meta.redirect_uri', ''));
        if ($uri !== '') {
            return $uri;
        }

        return route('settings.social.meta.callback');
    }

    private function appId(): string
    {
        $value = trim((string) config('meta.app_id', ''));
        if ($value === '') {
            throw new RuntimeException('META_APP_ID non configurato.');
        }

        return $value;
    }

    private function appSecret(): string
    {
        $value = trim((string) config('meta.app_secret', ''));
        if ($value === '') {
            throw new RuntimeException('META_APP_SECRET non configurato.');
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return array_values((array) config('meta.scopes', []));
    }
}
