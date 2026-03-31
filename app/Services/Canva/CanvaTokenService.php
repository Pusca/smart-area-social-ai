<?php

namespace App\Services\Canva;

use App\Models\CanvaConnection;
use App\Services\Canva\Exceptions\CanvaApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class CanvaTokenService
{
    public function __construct(
        private readonly CanvaApiClient $apiClient
    ) {
    }

    /**
     * @return array{state: string, code_verifier: string, code_challenge: string}
     */
    public function generatePkcePayload(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'state' => Str::random(48),
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
        ];
    }

    public function buildAuthorizationUrl(string $state, string $codeChallenge): string
    {
        $this->assertConfigured();

        $query = http_build_query([
            'client_id' => trim((string) config('canva.client_id')),
            'redirect_uri' => trim((string) config('canva.redirect_uri')),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('canva.scopes', [])),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return rtrim((string) config('canva.authorize_url'), '?') . '?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        $this->assertConfigured();

        return $this->apiClient->exchangeAuthorizationCode($code, $codeVerifier);
    }

    public function persistConnectionTokens(int $tenantId, int $userId, array $tokenPayload): CanvaConnection
    {
        $scopes = $this->normalizeScopes($tokenPayload);
        $expiresIn = max(0, (int) ($tokenPayload['expires_in'] ?? 0));
        $expiresAt = $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null;

        return CanvaConnection::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'user_id' => $userId,
                'access_token_encrypted' => trim((string) ($tokenPayload['access_token'] ?? '')),
                'refresh_token_encrypted' => trim((string) ($tokenPayload['refresh_token'] ?? '')) ?: null,
                'token_expires_at' => $expiresAt,
                'scopes' => $scopes,
                'status' => 'active',
                'last_error' => null,
            ]
        );
    }

    public function ensureFreshConnection(CanvaConnection $connection): CanvaConnection
    {
        if (trim((string) $connection->access_token_encrypted) === '') {
            throw new RuntimeException('Canva connection missing access token.');
        }

        $expiresAt = $connection->token_expires_at;
        $leeway = max(30, (int) config('canva.token_refresh_leeway_seconds', 300));

        if ($expiresAt instanceof Carbon && $expiresAt->lte(Carbon::now()->addSeconds($leeway))) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    public function refreshAccessToken(CanvaConnection $connection): CanvaConnection
    {
        $refreshToken = trim((string) $connection->refresh_token_encrypted);
        if ($refreshToken === '') {
            throw new RuntimeException('Canva connection missing refresh token.');
        }

        $tokenPayload = $this->apiClient->refreshAccessToken($refreshToken);
        $scopes = $this->normalizeScopes($tokenPayload, (array) ($connection->scopes ?? []));
        $expiresIn = max(0, (int) ($tokenPayload['expires_in'] ?? 0));

        $connection->access_token_encrypted = trim((string) ($tokenPayload['access_token'] ?? ''));
        $connection->refresh_token_encrypted = trim((string) ($tokenPayload['refresh_token'] ?? '')) ?: $refreshToken;
        $connection->token_expires_at = $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : $connection->token_expires_at;
        $connection->scopes = $scopes;
        $connection->status = 'active';
        $connection->last_error = null;
        $connection->save();

        return $connection->fresh();
    }

    /**
     * @template T
     * @param  callable(string):T  $callback
     * @return T
     */
    public function withAccessToken(CanvaConnection $connection, callable $callback)
    {
        $connection = $this->ensureFreshConnection($connection);
        $accessToken = trim((string) $connection->access_token_encrypted);

        try {
            return $callback($accessToken);
        } catch (CanvaApiException $e) {
            if ($e->statusCode !== 401 || trim((string) $connection->refresh_token_encrypted) === '') {
                throw $e;
            }

            $connection = $this->refreshAccessToken($connection);

            return $callback(trim((string) $connection->access_token_encrypted));
        }
    }

    private function assertConfigured(): void
    {
        if (
            trim((string) config('canva.client_id')) === ''
            || trim((string) config('canva.client_secret')) === ''
            || trim((string) config('canva.redirect_uri')) === ''
        ) {
            throw new RuntimeException('Canva integration is not configured.');
        }
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function normalizeScopes(array $tokenPayload, array $fallback = []): array
    {
        $scope = $tokenPayload['scope'] ?? $tokenPayload['scopes'] ?? $fallback;

        if (is_string($scope)) {
            return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scope) ?: [])));
        }

        return array_values(array_filter(array_map('strval', is_array($scope) ? $scope : [])));
    }
}
