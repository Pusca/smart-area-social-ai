<?php

namespace App\Services\Canva;

use App\Services\Canva\Exceptions\CanvaApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CanvaApiClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        return $this->oauthToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->oauthToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentUser(string $accessToken): array
    {
        return $this->requestJson('GET', '/users/me', $accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentUserProfile(string $accessToken): array
    {
        return $this->requestJson('GET', '/users/me/profile', $accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserCapabilities(string $accessToken): array
    {
        return $this->requestJson('GET', '/users/me/capabilities', $accessToken);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listBrandTemplates(string $accessToken, array $query = []): array
    {
        return $this->requestJson('GET', '/brand-templates', $accessToken, [
            'query' => $query,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBrandTemplateDataset(string $accessToken, string $templateId): array
    {
        return $this->requestJson('GET', '/brand-templates/' . rawurlencode($templateId) . '/dataset', $accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function createAssetUploadJob(string $accessToken, string $fileName, string $binary): array
    {
        return $this->requestBinary('POST', '/asset-uploads', $accessToken, $binary, [
            'Asset-Upload-Metadata' => json_encode([
                'name_base64' => base64_encode(Str::limit($fileName, 50, '')),
            ], JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssetUploadJob(string $accessToken, string $jobId): array
    {
        return $this->requestJson('GET', '/asset-uploads/' . rawurlencode($jobId), $accessToken);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createAutofillJob(string $accessToken, string $brandTemplateId, array $data, ?string $title = null): array
    {
        $payload = [
            'brand_template_id' => $brandTemplateId,
            'data' => $data,
        ];

        if ($title !== null && trim($title) !== '') {
            $payload['title'] = trim($title);
        }

        return $this->requestJson('POST', '/autofills', $accessToken, [
            'json' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutofillJob(string $accessToken, string $jobId): array
    {
        return $this->requestJson('GET', '/autofills/' . rawurlencode($jobId), $accessToken);
    }

    /**
     * @param  array<string, mixed>  $format
     * @return array<string, mixed>
     */
    public function createExportJob(string $accessToken, string $designId, array $format): array
    {
        return $this->requestJson('POST', '/exports', $accessToken, [
            'json' => [
                'design_id' => $designId,
                'format' => $format,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExportJob(string $accessToken, string $jobId): array
    {
        return $this->requestJson('GET', '/exports/' . rawurlencode($jobId), $accessToken);
    }

    public function downloadFile(string $downloadUrl): string
    {
        $response = Http::timeout(max(30, (int) config('canva.http_timeout_seconds', 30) * 2))
            ->get($downloadUrl);

        if (!$response->successful()) {
            throw new CanvaApiException('Canva download failed: ' . $response->body(), $response->status());
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function oauthToken(array $payload): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('canva.http_timeout_seconds', 30))
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post((string) config('canva.token_url'), $payload);

        return $this->decodeResponse($response, 'Canva OAuth');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, string $accessToken, array $options = []): array
    {
        $request = $this->baseRequest($accessToken);
        $response = match (strtoupper($method)) {
            'GET' => $request->get($this->apiUrl($path), $options['query'] ?? []),
            'POST' => $request->post($this->apiUrl($path), $options['json'] ?? []),
            default => throw new CanvaApiException('Unsupported Canva method: ' . $method),
        };

        return $this->decodeResponse($response, 'Canva API ' . $path);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function requestBinary(string $method, string $path, string $accessToken, string $binary, array $headers = []): array
    {
        if (strtoupper($method) !== 'POST') {
            throw new CanvaApiException('Unsupported Canva binary method: ' . $method);
        }

        $response = $this->baseRequest($accessToken)
            ->withHeaders($headers)
            ->withBody($binary, 'application/octet-stream')
            ->post($this->apiUrl($path));

        return $this->decodeResponse($response, 'Canva binary API ' . $path);
    }

    private function baseRequest(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('canva.http_timeout_seconds', 30))
            ->withToken($accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response, string $label): array
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if (!$response->successful()) {
            $message = trim((string) ($payload['message'] ?? $response->body() ?? 'Unknown Canva API error'));
            throw new CanvaApiException($label . ' failed: ' . $message, $response->status(), $payload);
        }

        return $payload;
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('canva.api_base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function redirectUri(): string
    {
        return trim((string) config('canva.redirect_uri'));
    }

    private function clientId(): string
    {
        return trim((string) config('canva.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('canva.client_secret'));
    }
}
