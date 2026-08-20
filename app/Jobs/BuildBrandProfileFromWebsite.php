<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantProfile;
use App\Services\OpenAiService;
use App\Services\SiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Onboarding "solo URL": crawla il sito del cliente (multi-pagina), estrae i
 * link ai canali social e fa compilare il profilo attività all'AI.
 *
 * Compila SOLO i campi vuoti: non sovrascrive mai ciò che l'utente ha già
 * scritto. Lo stato per il polling della UI vive in cache.
 */
class BuildBrandProfileFromWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public int $tenantId,
        public string $website,
    ) {
    }

    public static function cacheKey(int $tenantId): string
    {
        return "brand-prefill:{$tenantId}";
    }

    public function handle(SiteCrawler $crawler, OpenAiService $openAi): void
    {
        $key = self::cacheKey($this->tenantId);
        Cache::put($key, ['status' => 'running'], now()->addMinutes(15));

        try {
            $site = $crawler->crawl($this->website);

            if (mb_strlen(trim($site['text'])) < 200) {
                throw new \RuntimeException('Il sito contiene troppo poco testo per compilare il profilo.');
            }

            $extracted = $openAi->extractBrandProfile($this->website, $site['text']);

            // I job in coda non hanno utente autenticato: il global scope
            // BelongsToTenant non si applica, filtriamo a mano per tenant.
            $profile = TenantProfile::where('tenant_id', $this->tenantId)->first()
                ?? new TenantProfile(['tenant_id' => $this->tenantId]);

            $filled = 0;
            foreach (['business_name', 'industry', 'services', 'target', 'cta', 'brand_voice', 'notes'] as $field) {
                $value = trim((string) ($extracted[$field] ?? ''));
                if ($value !== '' && trim((string) $profile->{$field}) === '') {
                    $profile->{$field} = $value;
                    $filled++;
                }
            }

            if (trim((string) $profile->business_name) === '') {
                $profile->business_name = Tenant::find($this->tenantId)?->name ?? 'La mia attività';
            }

            $profile->website = $this->website;
            $profile->social_links = $site['social_links'] + ($profile->social_links ?? []);
            $profile->save();

            Cache::put($key, [
                'status' => 'done',
                'filled' => $filled,
                'pages' => $site['pages_count'],
                'social' => array_keys($site['social_links']),
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            Cache::put($key, [
                'status' => 'error',
                'error' => Str::limit($e->getMessage(), 200),
            ], now()->addMinutes(15));

            throw $e;
        }
    }
}
