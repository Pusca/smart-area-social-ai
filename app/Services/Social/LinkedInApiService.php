<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherInterface;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter di pubblicazione per LinkedIn.
 *
 * Supporta:
 *   - Post testuali (solo caption)
 *   - Post con immagine (upload asset + UGC post)
 *   - Post con video (upload asset + UGC post)
 *   - Pagine aziendali (organization URN) e profili personali (person URN)
 *
 * API di riferimento:
 *   - UGC Posts: POST https://api.linkedin.com/v2/ugcPosts
 *   - Asset Upload: POST https://api.linkedin.com/v2/assets?action=registerUpload
 *   - OAuth2: Authorization Code Flow con scope w_member_social / w_organization_social
 *
 * Flusso pubblicazione immagine:
 *   1. registerUpload → ottieni uploadUrl + assetUrn
 *   2. PUT {uploadUrl} con il file binario dell'immagine
 *   3. POST /v2/ugcPosts con shareMediaCategory=IMAGE e asset=urn:li:asset:{id}
 *
 * Flusso pubblicazione video:
 *   1. registerUpload con mediaType=video
 *   2. PUT {uploadUrl} con il file binario del video
 *   3. POST /v2/ugcPosts con shareMediaCategory=VIDEO
 */
class LinkedInApiService implements SocialPublisherInterface
{
    private const API_BASE = 'https://api.linkedin.com/v2';

    /**
     * Pubblica un contenuto su LinkedIn.
     * Sceglie il tipo di post in base a media_type del SocialPublication.
     *
     * @return array<string, mixed>
     */
    public function publish(SocialAccount $account, SocialPublication $publication): array
    {
        $mediaType = strtolower(trim((string) ($publication->media_type ?? 'text')));

        return match ($mediaType) {
            'image'  => $this->publishWithImage($account, $publication),
            'video'  => $this->publishWithVideo($account, $publication),
            default  => $this->publishTextPost($account, $publication),
        };
    }

    /**
     * Costruisce l'URL OAuth2 di autorizzazione LinkedIn.
     */
    public function loginUrl(string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'scope'         => implode(' ', $this->scopes()),
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
            ->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri(),
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('LinkedIn token exchange fallito: ' . $response->body());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            throw new RuntimeException('LinkedIn token exchange senza access_token.');
        }

        return $payload;
    }

    /**
     * Recupera il profilo del membro autenticato per ottenere il person URN.
     *
     * @return array<string, mixed>
     */
    public function fetchMemberProfile(string $accessToken): array
    {
        $response = $this->request($accessToken)
            ->get(self::API_BASE . '/me', [
                'projection' => '(id,localizedFirstName,localizedLastName)',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('LinkedIn fetchMemberProfile fallito: ' . $response->body());
        }

        $data = $response->json();

        return [
            'provider'     => 'linkedin',
            'platform'     => 'linkedin',
            'account_id'   => 'urn:li:person:' . trim((string) ($data['id'] ?? '')),
            'account_name' => trim(
                ($data['localizedFirstName'] ?? '') . ' ' . ($data['localizedLastName'] ?? '')
            ),
            'username'     => trim((string) ($data['id'] ?? '')),
            'meta'         => ['source' => 'oauth_sync', 'type' => 'person'],
        ];
    }

    /**
     * Recupera le pagine aziendali gestite dall'utente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrganizations(string $accessToken): array
    {
        $response = $this->request($accessToken)
            ->get(self::API_BASE . '/organizationAcls', [
                'q'           => 'roleAssignee',
                'role'        => 'ADMINISTRATOR',
                'projection'  => '(elements*(organization~(id,localizedName)))',
            ]);

        if (!$response->successful()) {
            // Non fatale: l'utente potrebbe non gestire pagine aziendali
            return [];
        }

        $organizations = [];
        $elements = (array) data_get($response->json(), 'elements', []);

        foreach ($elements as $element) {
            $org  = $element['organization~'] ?? [];
            $orgId = trim((string) ($org['id'] ?? ''));
            if ($orgId === '') {
                continue;
            }

            $organizations[] = [
                'provider'     => 'linkedin',
                'platform'     => 'linkedin',
                'account_id'   => 'urn:li:organization:' . $orgId,
                'account_name' => trim((string) ($org['localizedName'] ?? 'LinkedIn Page')),
                'username'     => 'organization:' . $orgId,
                'meta'         => ['source' => 'oauth_sync', 'type' => 'organization', 'org_id' => $orgId],
            ];
        }

        return $organizations;
    }

    // ─── Metodi di pubblicazione privati ─────────────────────────────────────

    /**
     * Pubblica un post solo testo su LinkedIn.
     *
     * @return array<string, mixed>
     */
    private function publishTextPost(SocialAccount $account, SocialPublication $publication): array
    {
        $authorUrn = $this->resolveAuthorUrn($account);
        $payload   = [
            'author'          => $authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'  => ['text' => (string) ($publication->caption ?? '')],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = $this->request($account->access_token)
            ->post(self::API_BASE . '/ugcPosts', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('LinkedIn text post fallito: ' . $response->body());
        }

        $postId = trim((string) ($response->header('x-restli-id') ?? ''));

        return [
            'remote_id'  => $postId,
            'remote_url' => $postId !== ''
                ? 'https://www.linkedin.com/feed/update/' . $postId . '/'
                : null,
            'meta' => $response->json() ?? [],
        ];
    }

    /**
     * Pubblica un post con immagine su LinkedIn.
     * Flusso: registerUpload → PUT immagine → UGC post con asset.
     *
     * @return array<string, mixed>
     */
    private function publishWithImage(SocialAccount $account, SocialPublication $publication): array
    {
        $authorUrn = $this->resolveAuthorUrn($account);
        $assetUrn  = $this->registerAndUploadMedia($account, $publication->media_url, 'IMAGE');

        $payload = [
            'author'         => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => (string) ($publication->caption ?? '')],
                    'shareMediaCategory' => 'IMAGE',
                    'media' => [
                        [
                            'status'      => 'READY',
                            'media'       => $assetUrn,
                            'description' => ['text' => (string) data_get($publication->payload, 'title', '')],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = $this->request($account->access_token)
            ->post(self::API_BASE . '/ugcPosts', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('LinkedIn image post fallito: ' . $response->body());
        }

        $postId = trim((string) ($response->header('x-restli-id') ?? ''));

        return [
            'remote_id'  => $postId,
            'remote_url' => $postId !== ''
                ? 'https://www.linkedin.com/feed/update/' . $postId . '/'
                : null,
            'meta' => $response->json() ?? [],
        ];
    }

    /**
     * Pubblica un post con video su LinkedIn.
     * Stessa logica di publishWithImage ma con shareMediaCategory VIDEO.
     *
     * @return array<string, mixed>
     */
    private function publishWithVideo(SocialAccount $account, SocialPublication $publication): array
    {
        $authorUrn = $this->resolveAuthorUrn($account);
        $assetUrn  = $this->registerAndUploadMedia($account, $publication->media_url, 'VIDEO');

        $payload = [
            'author'         => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => (string) ($publication->caption ?? '')],
                    'shareMediaCategory' => 'VIDEO',
                    'media' => [
                        [
                            'status' => 'READY',
                            'media'  => $assetUrn,
                            'title'  => ['text' => (string) data_get($publication->payload, 'title', 'Video')],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = $this->request($account->access_token)
            ->post(self::API_BASE . '/ugcPosts', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('LinkedIn video post fallito: ' . $response->body());
        }

        $postId = trim((string) ($response->header('x-restli-id') ?? ''));

        return [
            'remote_id'  => $postId,
            'remote_url' => $postId !== ''
                ? 'https://www.linkedin.com/feed/update/' . $postId . '/'
                : null,
            'meta' => $response->json() ?? [],
        ];
    }

    /**
     * Registra un upload su LinkedIn e carica il media tramite URL pubblico.
     *
     * Step 1: POST /v2/assets?action=registerUpload → upload URL + asset URN
     * Step 2: PUT {uploadUrl} con il file scaricato dall'URL del media
     *
     * @param  'IMAGE'|'VIDEO'  $mediaType
     * @return string asset URN (urn:li:digitalmediaAsset:...)
     */
    private function registerAndUploadMedia(SocialAccount $account, string $mediaUrl, string $mediaType): string
    {
        $authorUrn = $this->resolveAuthorUrn($account);

        // Step 1: registra l'upload
        $registerResponse = $this->request($account->access_token)
            ->post(self::API_BASE . '/assets?action=registerUpload', [
                'registerUploadRequest' => [
                    'recipes'             => ['urn:li:digitalmediaRecipe:feedshare-' . strtolower($mediaType)],
                    'owner'               => $authorUrn,
                    'serviceRelationships' => [
                        ['relationshipType' => 'OWNER', 'identifier' => 'urn:li:userGeneratedContent'],
                    ],
                ],
            ]);

        if (!$registerResponse->successful()) {
            throw new RuntimeException('LinkedIn registerUpload fallito: ' . $registerResponse->body());
        }

        $uploadUrl = trim((string) data_get(
            $registerResponse->json(),
            'value.uploadMechanism.com\.linkedin\.digitalmedia\.uploading\.MediaUploadHttpRequest.uploadUrl',
            ''
        ));
        $assetUrn = trim((string) data_get($registerResponse->json(), 'value.asset', ''));

        if ($uploadUrl === '' || $assetUrn === '') {
            throw new RuntimeException('LinkedIn registerUpload: uploadUrl o asset URN mancante.');
        }

        // Step 2: scarica il media dalla URL pubblica e caricalo su LinkedIn
        $mediaContent = Http::timeout(60)->get($mediaUrl)->body();

        $uploadResponse = Http::withHeaders([
            'Authorization'  => 'Bearer ' . $account->access_token,
            'Content-Type'   => 'application/octet-stream',
        ])->timeout(120)->withBody($mediaContent, 'application/octet-stream')->put($uploadUrl);

        if (!$uploadResponse->successful() && $uploadResponse->status() !== 201) {
            throw new RuntimeException(
                'LinkedIn media upload fallito (status ' . $uploadResponse->status() . '): ' . $uploadResponse->body()
            );
        }

        return $assetUrn;
    }

    /**
     * Risolve il URN dell'autore dell'account (person o organization).
     * Legge `meta.type` per distinguere i due tipi.
     *
     * Se account_id contiene già "urn:li:" lo usa direttamente,
     * altrimenti costruisce un person URN legacy.
     */
    private function resolveAuthorUrn(SocialAccount $account): string
    {
        $accountId = trim((string) ($account->account_id ?? ''));

        // account_id già in formato URN (impostato durante l'OAuth sync)
        if (str_starts_with($accountId, 'urn:li:')) {
            return $accountId;
        }

        // Fallback: costruisce person URN
        return 'urn:li:person:' . $accountId;
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'Authorization'             => 'Bearer ' . $accessToken,
                'LinkedIn-Version'          => trim((string) config('linkedin.api_version', '202401')),
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }

    private function clientId(): string
    {
        $value = trim((string) config('linkedin.client_id', ''));
        if ($value === '') {
            throw new RuntimeException('LINKEDIN_CLIENT_ID non configurato.');
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = trim((string) config('linkedin.client_secret', ''));
        if ($value === '') {
            throw new RuntimeException('LINKEDIN_CLIENT_SECRET non configurato.');
        }

        return $value;
    }

    private function redirectUri(): string
    {
        $uri = trim((string) config('linkedin.redirect_uri', ''));

        return $uri !== '' ? $uri : route('settings.social.linkedin.callback');
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return array_values((array) config('linkedin.scopes', [
            'openid', 'profile', 'w_member_social', 'w_organization_social',
        ]));
    }
}
