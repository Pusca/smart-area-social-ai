<?php

namespace App\Services\Luma;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LumaClient
{
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('luma.api_key', '');
        $this->baseUrl = rtrim((string) config('luma.base_url', 'https://api.lumalabs.ai'), '/');
        $this->timeout = (int) config('luma.timeout', 60);
    }

    public function post(string $path, array $payload): array
    {
        $response = $this->client()->post($this->baseUrl . $path, $payload);
        $this->assertSuccess($response, 'POST ' . $path);
        return (array) $response->json();
    }

    public function get(string $path): array
    {
        $response = $this->client()->get($this->baseUrl . $path);
        $this->assertSuccess($response, 'GET ' . $path);
        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Luma API key not configured (LUMA_API_KEY).');
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout($this->timeout);
    }

    private function assertSuccess(Response $response, string $context): void
    {
        if ($response->failed()) {
            $body  = $response->body();
            $status = $response->status();
            throw new RuntimeException("Luma API error [{$status}] on {$context}: {$body}");
        }
    }
}
