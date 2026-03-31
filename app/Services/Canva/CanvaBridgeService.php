<?php

namespace App\Services\Canva;

use App\Models\CanvaConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CanvaBridgeService
{
    public function __construct(
        private readonly CanvaApiClient $apiClient,
        private readonly CanvaTokenService $tokenService
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('social_manager.features.canva_integration_v1', true)
            && (bool) config('canva.enabled', true);
    }

    public function connectionForTenant(int $tenantId): ?CanvaConnection
    {
        return CanvaConnection::query()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function requireActiveConnection(int $tenantId): CanvaConnection
    {
        $connection = $this->connectionForTenant($tenantId);
        if (!$connection || $connection->status !== 'active') {
            throw new RuntimeException('Canva is not connected for this tenant.');
        }

        return $connection;
    }

    public function completeOauthConnection(int $tenantId, int $userId, array $tokenPayload): CanvaConnection
    {
        $connection = $this->tokenService->persistConnectionTokens($tenantId, $userId, $tokenPayload);

        try {
            return $this->syncConnectionMetadata($connection);
        } catch (\Throwable $e) {
            Log::warning('canva.oauth.sync_failed', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            $connection->last_error = $e->getMessage();
            $connection->status = 'active';
            $connection->save();

            return $connection->fresh();
        }
    }

    public function syncConnectionMetadata(CanvaConnection $connection): CanvaConnection
    {
        $result = $this->tokenService->withAccessToken($connection, function (string $accessToken): array {
            $user = $this->apiClient->getCurrentUser($accessToken);
            $capabilities = $this->apiClient->getUserCapabilities($accessToken);
            $profile = [];

            try {
                $profile = $this->apiClient->getCurrentUserProfile($accessToken);
            } catch (\Throwable) {
                $profile = [];
            }

            return [
                'user' => $user,
                'capabilities' => $capabilities,
                'profile' => $profile,
            ];
        });

        $connection->canva_user_id = trim((string) data_get($result, 'user.team_user.user_id', $connection->canva_user_id));
        $connection->canva_team_id = trim((string) data_get($result, 'user.team_user.team_id', $connection->canva_team_id));
        $connection->canva_display_name = trim((string) data_get($result, 'profile.profile.display_name', data_get($result, 'profile.profile.name', $connection->canva_display_name)));
        $connection->capabilities = array_values(array_filter(array_map('strval', (array) data_get($result, 'capabilities.capabilities', []))));
        $connection->status = 'active';
        $connection->last_synced_at = Carbon::now();
        $connection->last_error = null;
        $connection->meta = array_merge((array) ($connection->meta ?? []), [
            'oauth_synced_at' => Carbon::now()->toDateTimeString(),
            'profile' => (array) data_get($result, 'profile.profile', []),
        ]);
        $connection->save();

        return $connection->fresh();
    }

    public function disconnect(int $tenantId): void
    {
        $connection = $this->connectionForTenant($tenantId);
        if (!$connection) {
            return;
        }

        $connection->status = 'disconnected';
        $connection->access_token_encrypted = null;
        $connection->refresh_token_encrypted = null;
        $connection->token_expires_at = null;
        $connection->last_error = 'Disconnected manually.';
        $connection->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionSummary(int $tenantId): array
    {
        $connection = $this->connectionForTenant($tenantId);
        $capabilities = array_values(array_filter(array_map('strval', (array) ($connection?->capabilities ?? []))));
        $scopes = array_values(array_filter(array_map('strval', (array) ($connection?->scopes ?? []))));
        $catalog = (array) data_get($connection?->meta, 'catalog_preview', []);

        return [
            'enabled' => $this->isEnabled(),
            'configured' => trim((string) config('canva.client_id')) !== ''
                && trim((string) config('canva.client_secret')) !== ''
                && trim((string) config('canva.redirect_uri')) !== '',
            'connection' => $connection,
            'connected' => (bool) ($connection && $connection->status === 'active'),
            'autofill_available' => in_array('autofill', $capabilities, true),
            'templates_available' => in_array('brand_template', $capabilities, true),
            'export_available' => in_array('design:content:read', $scopes, true),
            'capabilities' => $capabilities,
            'scopes' => $scopes,
            'catalog_preview' => (array) ($catalog['items'] ?? []),
            'catalog_refreshed_at' => data_get($catalog, 'refreshed_at'),
        ];
    }
}
