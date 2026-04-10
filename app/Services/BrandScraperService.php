<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Estrae dati brand da un URL pubblico senza API esterne.
 *
 * Strategia: HTTP GET + parsing HTML con regex sulle meta tag.
 * Nessuna dipendenza da headless browser o librerie di scraping.
 *
 * Dati estratti (best-effort, mai garantiti):
 *   - business_name  → og:site_name, title, h1
 *   - description    → og:description, meta description
 *   - industry_hint  → parole chiave nel testo
 *   - tone_hint      → analisi del tono testuale
 *   - services       → liste, anchor text ripetuti
 *   - social_links   → link a profili social trovati nella pagina
 *
 * Usato nell'onboarding per pre-compilare il TenantProfile con un click.
 */
class BrandScraperService
{
    /**
     * Timeout HTTP in secondi. Breve per non bloccare l'UI.
     */
    private const HTTP_TIMEOUT = 10;

    /**
     * Lunghezza massima del testo estratto (evita JSON enormi in meta).
     */
    private const MAX_TEXT_LENGTH = 600;

    /**
     * Estrae i dati brand da un URL.
     *
     * @return array{
     *   success: bool,
     *   url: string,
     *   business_name: string,
     *   description: string,
     *   industry_hint: string,
     *   tone_hint: string,
     *   services: array<int,string>,
     *   social_links: array<string,string>,
     *   raw_title: string,
     *   error: string|null
     * }
     */
    public function scrapeFromUrl(string $url): array
    {
        $url = $this->normalizeUrl($url);

        $empty = $this->emptyResult($url);

        if ($url === '') {
            return array_merge($empty, ['error' => 'URL non valido.']);
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders([
                    // User-agent neutro per evitare blocchi da bot-protection
                    'User-Agent' => 'Mozilla/5.0 (compatible; BrandScraper/1.0)',
                    'Accept'     => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8',
                ])
                ->get($url);

            if (!$response->successful()) {
                return array_merge($empty, [
                    'error' => "Il sito ha risposto con codice {$response->status()}.",
                ]);
            }

            $html = $response->body();

            return [
                'success'        => true,
                'url'            => $url,
                'business_name'  => $this->extractBusinessName($html),
                'description'    => $this->extractDescription($html),
                'industry_hint'  => $this->guessIndustry($html),
                'tone_hint'      => $this->guessTone($html),
                'services'       => $this->extractServices($html),
                'social_links'   => $this->extractSocialLinks($html),
                'raw_title'      => $this->extractTag($html, 'title') ?? '',
                'error'          => null,
            ];

        } catch (\Throwable $e) {
            Log::warning('BrandScraperService: scraping failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return array_merge($empty, [
                'error' => 'Impossibile raggiungere il sito. Compila manualmente i campi.',
            ]);
        }
    }

    /**
     * Aggiunge https:// se mancante e normalizza il formato.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://' . $url;
        }

        // Validazione base: deve avere un TLD
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $url;
    }

    /**
     * Estrae il nome del business: og:site_name > og:title > <title> > primo <h1>.
     */
    private function extractBusinessName(string $html): string
    {
        // og:site_name è il più affidabile per il nome del brand
        $siteName = $this->extractMetaProperty($html, 'og:site_name');
        if ($siteName !== '') {
            return $this->clean($siteName);
        }

        // og:title spesso contiene "Brand - Tagline", prendiamo la parte prima di " - " o " | "
        $ogTitle = $this->extractMetaProperty($html, 'og:title');
        if ($ogTitle !== '') {
            return $this->clean(preg_split('/[\|\-–—]/', $ogTitle)[0] ?? $ogTitle);
        }

        // <title> come fallback
        $title = $this->extractTag($html, 'title');
        if ($title) {
            return $this->clean(preg_split('/[\|\-–—]/', $title)[0] ?? $title);
        }

        // Primo <h1>
        if (preg_match('/<h1[^>]*>\s*(.*?)\s*<\/h1>/si', $html, $m)) {
            return $this->clean(strip_tags($m[1]));
        }

        return '';
    }

    /**
     * Estrae la descrizione: og:description > meta description > primo <p> significativo.
     */
    private function extractDescription(string $html): string
    {
        $ogDesc = $this->extractMetaProperty($html, 'og:description');
        if ($ogDesc !== '') {
            return Str::limit($this->clean($ogDesc), self::MAX_TEXT_LENGTH, '…');
        }

        $metaDesc = $this->extractMetaName($html, 'description');
        if ($metaDesc !== '') {
            return Str::limit($this->clean($metaDesc), self::MAX_TEXT_LENGTH, '…');
        }

        // Primo paragrafo abbastanza lungo da essere una descrizione reale
        if (preg_match_all('/<p[^>]*>\s*(.*?)\s*<\/p>/si', $html, $matches)) {
            foreach ($matches[1] as $p) {
                $text = trim(strip_tags($p));
                if (mb_strlen($text) >= 60) {
                    return Str::limit($this->clean($text), self::MAX_TEXT_LENGTH, '…');
                }
            }
        }

        return '';
    }

    /**
     * Tenta di indovinare l'industry dalle parole chiave nel testo della pagina.
     * Restituisce la chiave canonica dell'industry o una stringa vuota.
     */
    private function guessIndustry(string $html): string
    {
        $text = strtolower(strip_tags($html));

        // Mappa keyword → industry (ordine: più specifico prima)
        $signals = [
            'healthcare'   => ['clinica', 'medico', 'dottore', 'dentista', 'fisioterapia', 'salute', 'benessere', 'farmacia', 'psicolog'],
            'food'         => ['ristorante', 'pizzeria', 'cucina', 'menù', 'menu', 'chef', 'food', 'gastronomia', 'pasticceria', 'bar', 'caffè'],
            'ecommerce'    => ['acquista', 'shop', 'carrello', 'spedizione', 'fashion', 'abbigliamento', 'negozio online', 'e-commerce'],
            'education'    => ['corso', 'formazione', 'coaching', 'academy', 'masterclass', 'webinar', 'certificazione', 'studenti'],
            'professional' => ['agenzia', 'studio', 'consulenza', 'servizi professionali', 'marketing', 'software', 'sviluppo'],
        ];

        $scores = [];
        foreach ($signals as $industry => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($text, $keyword);
            }
            if ($score > 0) {
                $scores[$industry] = $score;
            }
        }

        if (empty($scores)) {
            return '';
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    /**
     * Stima il tono del brand analizzando il testo della homepage.
     * Restituisce una stringa descrittiva (es. "formale e professionale").
     */
    private function guessTone(string $html): string
    {
        $text  = strip_tags($html);
        $lower = strtolower($text);

        $formal   = preg_match_all('/\b(lei|sua|siamo|offriamo|garantiamo|professionisti)\b/', $lower);
        $informal = preg_match_all('/\b(tu|tuo|noi|ci|insieme|community|amici)\b/', $lower);
        $warm     = preg_match_all('/\b(passione|amore|cuore|famiglia|storia|tradizione)\b/', $lower);
        $dynamic  = preg_match_all('/\b(innovazione|digitale|futuro|crescita|risultati|performance)\b/', $lower);

        if ($formal > $informal && $formal > $warm) {
            return 'professionale e formale';
        }
        if ($warm > $informal) {
            return 'caldo e autentico';
        }
        if ($dynamic > $formal) {
            return 'dinamico e orientato ai risultati';
        }
        if ($informal > $formal) {
            return 'diretto e conversazionale';
        }

        return 'professionale';
    }

    /**
     * Estrae potenziali servizi/prodotti: cerca liste <li>, heading <h2>/<h3>
     * con testo corto (probabile voce di menu o servizio).
     *
     * @return array<int, string>
     */
    private function extractServices(string $html): array
    {
        $services = [];

        // Elementi <li> brevi (max 60 char) — probabili servizi in lista
        if (preg_match_all('/<li[^>]*>\s*(.*?)\s*<\/li>/si', $html, $m)) {
            foreach ($m[1] as $li) {
                $text = trim(strip_tags($li));
                if ($text !== '' && mb_strlen($text) <= 60 && mb_strlen($text) >= 4) {
                    $services[] = $this->clean($text);
                }
            }
        }

        // Heading brevi (<h2>, <h3>) — spesso rappresentano sezioni di servizio
        if (preg_match_all('/<h[23][^>]*>\s*(.*?)\s*<\/h[23]>/si', $html, $m)) {
            foreach ($m[1] as $h) {
                $text = trim(strip_tags($h));
                if ($text !== '' && mb_strlen($text) <= 50 && mb_strlen($text) >= 4) {
                    $services[] = $this->clean($text);
                }
            }
        }

        // De-duplica, rimuove duplicati semantici, limita a 8
        return array_values(array_unique(array_slice($services, 0, 8)));
    }

    /**
     * Estrae link a profili social trovati nel markup della pagina.
     *
     * @return array<string, string>
     */
    private function extractSocialLinks(string $html): array
    {
        $patterns = [
            'instagram' => '/https?:\/\/(www\.)?instagram\.com\/[a-zA-Z0-9_\.]+\/?/i',
            'facebook'  => '/https?:\/\/(www\.)?facebook\.com\/[a-zA-Z0-9_\.]+\/?/i',
            'linkedin'  => '/https?:\/\/(www\.)?linkedin\.com\/(company|in)\/[a-zA-Z0-9_\-]+\/?/i',
            'tiktok'    => '/https?:\/\/(www\.)?tiktok\.com\/@[a-zA-Z0-9_\.]+\/?/i',
            'youtube'   => '/https?:\/\/(www\.)?youtube\.com\/@?[a-zA-Z0-9_\-]+\/?/i',
        ];

        $found = [];
        foreach ($patterns as $network => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $found[$network] = rtrim($m[0], '/');
            }
        }

        return $found;
    }

    // ─── HTML helpers ────────────────────────────────────────────────────────

    private function extractMetaProperty(string $html, string $property): string
    {
        if (preg_match('/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/<meta[^>]+content=["\'](.*?)["\'"][^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]*>/si', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function extractMetaName(string $html, string $name): string
    {
        if (preg_match('/<meta[^>]+name=["\']' . preg_quote($name, '/') . '["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function extractTag(string $html, string $tag): ?string
    {
        if (preg_match('/<' . $tag . '[^>]*>\s*(.*?)\s*<\/' . $tag . '>/si', $html, $m)) {
            return trim(strip_tags($m[1]));
        }

        return null;
    }

    /** Pulisce un testo: decode HTML entities, rimuove tag residui, normalizza spazi. */
    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Restituisce una struttura vuota con i campi attesi. */
    private function emptyResult(string $url): array
    {
        return [
            'success'       => false,
            'url'           => $url,
            'business_name' => '',
            'description'   => '',
            'industry_hint' => '',
            'tone_hint'     => '',
            'services'      => [],
            'social_links'  => [],
            'raw_title'     => '',
            'error'         => null,
        ];
    }
}
