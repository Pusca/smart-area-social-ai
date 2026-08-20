<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Legge il sito di un'attività per l'onboarding: homepage + pagine interne
 * rilevanti (servizi, chi siamo, contatti...) + link ai canali social.
 *
 * Driver:
 * - "firecrawl" (se FIRECRAWL_API_KEY è configurata): scrape con JS rendering
 *   e markdown pulito via api.firecrawl.dev
 * - "native" (fallback, zero dipendenze): fetch HTTP + estrazione testo
 */
class SiteCrawler
{
    private const RELEVANT_PATH_PATTERN =
        '/chi-?siamo|about|servizi|services|prodott|products|menu|contatti|contact|prezzi|pricing|listino|storia|team|azienda|cosa-facciamo|trattamenti|offerta/i';

    private const SOCIAL_PATTERN =
        '~https?://(?:www\.)?(instagram\.com|facebook\.com|tiktok\.com|linkedin\.com|youtube\.com|threads\.net)/[^\s"\'<>)]+~i';

    /**
     * @return array{text: string, social_links: array<string, string>, pages_count: int}
     */
    public function crawl(string $url, int $maxPages = 8): array
    {
        $url = $this->normalizeUrl($url);

        $home = $this->fetchPage($url);
        if ($home === null) {
            throw new RuntimeException("Impossibile leggere il sito {$url}");
        }

        $pages = [$url => $home];

        $candidates = $this->prioritizeLinks($url, $home['links'], $maxPages - 1);
        foreach ($candidates as $link) {
            $page = $this->fetchPage($link);
            if ($page !== null) {
                $pages[$link] = $page;
            }
        }

        $socialLinks = [];
        $chunks = [];
        foreach ($pages as $pageUrl => $page) {
            $socialLinks += $this->socialLinks($page['raw']);
            if (trim($page['text']) !== '') {
                $chunks[] = "## Pagina: {$pageUrl}\n" . $page['text'];
            }
        }

        return [
            'text' => mb_substr(implode("\n\n", $chunks), 0, 60000),
            'social_links' => $socialLinks,
            'pages_count' => count($pages),
        ];
    }

    /**
     * @return array{text: string, links: list<string>, raw: string}|null
     */
    protected function fetchPage(string $url): ?array
    {
        try {
            if ($this->firecrawlKey() !== '') {
                return $this->fetchViaFirecrawl($url);
            }
            return $this->fetchNative($url);
        } catch (\Throwable $e) {
            Log::info('SiteCrawler: pagina saltata', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function fetchNative(string $url): ?array
    {
        $res = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SocialAI/1.0)'])
            ->get($url);

        if (!$res->successful()) {
            return null;
        }

        $html = $res->body();

        preg_match_all('/href\s*=\s*["\']([^"\'#]+)["\']/i', $html, $matches);

        return [
            'text' => $this->extractReadableText($html),
            'links' => array_values(array_unique($matches[1] ?? [])),
            'raw' => $html,
        ];
    }

    protected function fetchViaFirecrawl(string $url): ?array
    {
        $res = Http::withToken($this->firecrawlKey())
            ->timeout(60)
            ->retry(2, 500)
            ->post('https://api.firecrawl.dev/v2/scrape', [
                'url' => $url,
                'formats' => ['markdown', 'links'],
                'onlyMainContent' => true,
            ]);

        if (!$res->successful()) {
            Log::warning('SiteCrawler: Firecrawl scrape fallito, fallback nativo', [
                'url' => $url,
                'status' => $res->status(),
            ]);
            return $this->fetchNative($url);
        }

        $markdown = (string) data_get($res->json(), 'data.markdown', '');
        $links = array_map('strval', (array) data_get($res->json(), 'data.links', []));

        return [
            'text' => mb_substr(trim($markdown), 0, 14000),
            'links' => $links,
            // i link ai social spesso stanno nel footer: il markdown li conserva
            'raw' => $markdown . "\n" . implode("\n", $links),
        ];
    }

    /**
     * Ordina i link interni per rilevanza (servizi/chi siamo/contatti prima)
     * e ne restituisce al massimo $limit.
     *
     * @param list<string> $links
     * @return list<string>
     */
    protected function prioritizeLinks(string $baseUrl, array $links, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

        $internal = [];
        foreach ($links as $link) {
            $link = trim($link);
            if ($link === '' || str_starts_with($link, 'mailto:') || str_starts_with($link, 'tel:') || str_starts_with($link, 'javascript:')) {
                continue;
            }

            if (str_starts_with($link, '/')) {
                $link = "{$scheme}://{$host}{$link}";
            }

            $linkHost = parse_url($link, PHP_URL_HOST);
            if (!$linkHost || strcasecmp($linkHost, (string) $host) !== 0) {
                continue;
            }

            $path = (string) (parse_url($link, PHP_URL_PATH) ?: '/');
            if ($path === '/' || preg_match('/\.(pdf|jpe?g|png|webp|svg|zip|mp4|css|js)$/i', $path)) {
                continue;
            }

            $link = strtok($link, '?') ?: $link;
            $internal[rtrim($link, '/')] = preg_match(self::RELEVANT_PATH_PATTERN, $path) ? 1 : 0;
        }

        arsort($internal);

        return array_slice(array_keys($internal), 0, $limit);
    }

    /** @return array<string, string> piattaforma => url profilo */
    protected function socialLinks(string $raw): array
    {
        preg_match_all(self::SOCIAL_PATTERN, $raw, $matches);

        $out = [];
        foreach ($matches[0] ?? [] as $link) {
            // scarta i link di condivisione, tieni solo i profili
            if (preg_match('/sharer|share\.php|intent|\/share\b|\/plugins\//i', $link)) {
                continue;
            }
            $platform = str_replace(['.com', '.net'], '', strtolower((string) parse_url($link, PHP_URL_HOST)));
            $platform = str_replace('www.', '', $platform);
            $out[$platform] ??= rtrim($link, '/');
        }

        return $out;
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }

    protected function firecrawlKey(): string
    {
        return trim((string) config('services.firecrawl.key', ''));
    }

    protected function extractReadableText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, 14000);
    }
}
