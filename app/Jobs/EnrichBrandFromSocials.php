<?php

namespace App\Jobs;

use App\Models\TenantProfile;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Arricchisce il profilo brand con i post Instagram REALI del cliente
 * (via Apify Instagram Profile Scraper): le caption vere sono la miglior
 * evidenza possibile per example_posts e brand_voice.
 *
 * Best-effort: qualsiasi errore viene loggato e non blocca l'onboarding.
 * Compila solo campi vuoti, come il resto dell'onboarding.
 */
class EnrichBrandFromSocials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $tenantId)
    {
    }

    public function handle(OpenAiService $openAi): void
    {
        $token = trim((string) config('services.apify.token', ''));
        if ($token === '') {
            return;
        }

        // Job in coda: nessun utente autenticato, filtro manuale per tenant
        $profile = TenantProfile::where('tenant_id', $this->tenantId)->first();
        $instagramUrl = (string) data_get($profile?->social_links, 'instagram', '');
        if (!$profile || $instagramUrl === '') {
            return;
        }

        $username = $this->usernameFromUrl($instagramUrl);
        if ($username === '') {
            return;
        }

        try {
            $captions = $this->fetchRecentCaptions($token, $username);
            if ($captions === []) {
                Log::info('EnrichBrandFromSocials: nessuna caption trovata', ['username' => $username]);
                return;
            }

            if (trim((string) $profile->example_posts) === '') {
                $profile->example_posts = Str::limit(
                    implode("\n\n---\n\n", array_slice($captions, 0, 8)),
                    6000,
                    ''
                );
            }

            if (trim((string) $profile->brand_voice) === '') {
                $voice = $openAi->describeBrandVoiceFromPosts($captions);
                if ($voice !== '') {
                    $profile->brand_voice = $voice;
                }
            }

            $profile->save();
        } catch (\Throwable $e) {
            Log::warning('EnrichBrandFromSocials fallito (best-effort)', [
                'tenant_id' => $this->tenantId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    protected function fetchRecentCaptions(string $token, string $username): array
    {
        $res = Http::timeout(240)
            ->post(
                'https://api.apify.com/v2/acts/apify~instagram-profile-scraper/run-sync-get-dataset-items?token=' . $token,
                ['usernames' => [$username]]
            );

        if (!$res->successful()) {
            throw new \RuntimeException("Apify error ({$res->status()})");
        }

        $item = collect($res->json())->first();

        return collect(data_get($item, 'latestPosts', []))
            ->pluck('caption')
            ->filter(fn ($c) => is_string($c) && trim($c) !== '')
            ->map(fn ($c) => trim($c))
            ->take(12)
            ->values()
            ->all();
    }

    protected function usernameFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $first = explode('/', $path)[0] ?? '';

        // scarta path che non sono profili
        if (in_array(strtolower($first), ['p', 'reel', 'reels', 'stories', 'explore', ''], true)) {
            return '';
        }

        return $first;
    }
}
