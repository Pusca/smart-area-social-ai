<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Formatta caption, CTA e hashtag nel modo corretto per ogni piattaforma.
 *
 * Ogni piattaforma ha regole diverse:
 *   - Instagram: 2200 char max, max 30 hashtag, blocco hashtag separato da 2 righe vuote
 *   - Facebook:  63206 char max, max 10 hashtag consigliati, inline o separato
 *   - LinkedIn:  3000 char max, max 5 hashtag, separati
 *   - TikTok:    2200 char max, max 10 hashtag, inline
 *
 * Usato da SocialPublishingService::buildCaption() per garantire che
 * ogni contenuto sia "pronto alla pubblicazione" senza editing manuale.
 */
class CaptionFormatterService
{
    /**
     * Regole per piattaforma.
     * hashtag_block_separator: separatore tra corpo+CTA e blocco hashtag.
     * cta_separator: separatore tra corpo caption e CTA.
     * hashtags_inline: true = hashtag mischiati nel corpo, false = blocco separato.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PLATFORM_RULES = [
        'instagram' => [
            'max_chars'              => 2200,
            'max_hashtags'           => 30,
            'hashtag_block_separator' => "\n\n",
            'cta_separator'          => "\n\n",
            'hashtags_inline'        => false,
        ],
        'facebook' => [
            'max_chars'              => 63206,
            'max_hashtags'           => 10,
            'hashtag_block_separator' => "\n\n",
            'cta_separator'          => "\n\n",
            'hashtags_inline'        => false,
        ],
        'linkedin' => [
            'max_chars'              => 3000,
            'max_hashtags'           => 5,
            'hashtag_block_separator' => "\n\n",
            'cta_separator'          => "\n\n",
            'hashtags_inline'        => false,
        ],
        'tiktok' => [
            'max_chars'              => 2200,
            'max_hashtags'           => 10,
            'hashtag_block_separator' => ' ',
            'cta_separator'          => "\n\n",
            'hashtags_inline'        => true,
        ],
    ];

    /**
     * Formatta caption + CTA + hashtag seguendo le regole della piattaforma.
     *
     * @param  array<int, string>  $hashtags
     */
    public function format(
        string $caption,
        string $cta,
        array  $hashtags,
        string $platform = 'instagram'
    ): string {
        $rules = self::PLATFORM_RULES[$platform] ?? self::PLATFORM_RULES['instagram'];

        // 1. Pulisce il corpo della caption (newline multipli, spazi superflui)
        $body = $this->cleanText($caption);

        // 2. Pulisce CTA
        $ctaClean = $this->cleanText($cta);

        // 3. Normalizza hashtag: aggiunge # se mancante, de-duplica, limita al max della piattaforma
        $hashtagBlock = $this->buildHashtagBlock(
            $hashtags,
            (int) $rules['max_hashtags'],
            (bool) $rules['hashtags_inline']
        );

        // 4. Assembla corpo + CTA
        $parts = array_filter([$body, $ctaClean], fn (string $p) => $p !== '');
        $mainText = implode((string) $rules['cta_separator'], $parts);

        // 5. Aggiunge il blocco hashtag con il separatore corretto per la piattaforma
        if ($hashtagBlock !== '') {
            $mainText .= (string) $rules['hashtag_block_separator'] . $hashtagBlock;
        }

        // 6. Tronca rispettando il limite della piattaforma.
        //    Strategia: preserva hashtag, tronca il corpo principale.
        $maxChars = (int) $rules['max_chars'];
        if (mb_strlen($mainText) > $maxChars) {
            $mainText = $this->truncatePreservingHashtags(
                body: $body,
                cta: $ctaClean,
                hashtagBlock: $hashtagBlock,
                maxChars: $maxChars,
                ctaSeparator: (string) $rules['cta_separator'],
                hashtagSeparator: (string) $rules['hashtag_block_separator']
            );
        }

        return trim($mainText);
    }

    /**
     * Restituisce le specifiche di aspect ratio consigliate per un dato format+platform.
     *
     * Usato per annotare i ContentItem con le dimensioni giuste prima della pubblicazione
     * e per guidare la generazione visuale verso l'output corretto per il placement.
     *
     * @return array<string, mixed>
     */
    public function placementAspectRatio(string $format, string $platform): array
    {
        $format   = strtolower(trim($format));
        $platform = strtolower(trim($platform));

        return match (true) {
            // Reel e Story: sempre verticale 9:16 su qualunque piattaforma
            in_array($format, ['reel', 'story', 'video'], true) => [
                'primary'    => '9:16',
                'width'      => 1080,
                'height'     => 1920,
                'placements' => ['reels', 'stories'],
                'format'     => $format,
                'platform'   => $platform,
            ],

            // Instagram Feed post: 4:5 (massimizza spazio in feed)
            $platform === 'instagram' && $format === 'post' => [
                'primary'    => '4:5',
                'width'      => 1080,
                'height'     => 1350,
                'placements' => ['feed'],
                'format'     => $format,
                'platform'   => $platform,
            ],

            // Instagram Carosello: quadrato 1:1
            $platform === 'instagram' && $format === 'carousel' => [
                'primary'    => '1:1',
                'width'      => 1080,
                'height'     => 1080,
                'placements' => ['feed_carousel'],
                'format'     => $format,
                'platform'   => $platform,
            ],

            // Facebook post: 1.91:1 ottimale per link preview e news feed
            $platform === 'facebook' => [
                'primary'    => '1.91:1',
                'width'      => 1200,
                'height'     => 628,
                'placements' => ['feed', 'news_feed'],
                'format'     => $format,
                'platform'   => $platform,
            ],

            // LinkedIn: quadrato 1:1 o landscape 1.91:1
            $platform === 'linkedin' => [
                'primary'    => '1:1',
                'width'      => 1080,
                'height'     => 1080,
                'placements' => ['feed'],
                'format'     => $format,
                'platform'   => $platform,
            ],

            // Default: 4:5 Instagram feed
            default => [
                'primary'    => '4:5',
                'width'      => 1080,
                'height'     => 1350,
                'placements' => ['feed'],
                'format'     => $format,
                'platform'   => $platform,
            ],
        };
    }

    /**
     * Pulisce un testo: normalizza newline multipli a max 2, trim per riga.
     */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? trim($text);
        $lines = array_map('trim', explode("\n", $text));

        return trim(implode("\n", $lines));
    }

    /**
     * Normalizza e limita gli hashtag, aggiunge # se mancante.
     *
     * @param  array<int, string>  $hashtags
     */
    private function buildHashtagBlock(array $hashtags, int $maxCount, bool $inline): string
    {
        $tags = collect($hashtags)
            ->map(fn (mixed $tag) => trim((string) $tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->map(fn (string $tag) => Str::startsWith($tag, '#') ? $tag : '#' . ltrim($tag, '#'))
            ->unique()
            ->take($maxCount)
            ->values()
            ->all();

        if (empty($tags)) {
            return '';
        }

        // Per TikTok (inline) e piattaforme che li vogliono nel corpo: stringa singola
        // Per Instagram/Facebook/LinkedIn: una riga con tutti gli hashtag separati da spazio
        return implode(' ', $tags);
    }

    /**
     * Tronca il contenuto rispettando il limite della piattaforma.
     * Preserva hashtag e CTA; tronca il corpo principale.
     */
    private function truncatePreservingHashtags(
        string $body,
        string $cta,
        string $hashtagBlock,
        int    $maxChars,
        string $ctaSeparator,
        string $hashtagSeparator
    ): string {
        // Calcola lo spazio occupato da CTA e hashtag
        $ctaPart      = $cta !== '' ? $ctaSeparator . $cta : '';
        $hashtagPart  = $hashtagBlock !== '' ? $hashtagSeparator . $hashtagBlock : '';
        $fixedLength  = mb_strlen($ctaPart) + mb_strlen($hashtagPart);

        // Spazio disponibile per il corpo
        $bodyMax = max(50, $maxChars - $fixedLength - 3); // -3 per ellissi

        $truncatedBody = mb_strlen($body) > $bodyMax
            ? mb_substr($body, 0, $bodyMax) . '…'
            : $body;

        return trim($truncatedBody . $ctaPart . $hashtagPart);
    }
}
