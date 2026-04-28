<?php

namespace App\Services\AI;

use App\Services\OpenAiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Analizza un'immagine brand tramite OpenAI Vision e produce:
 * - descrizione (cosa c'è nell'immagine)
 * - contesto marketing (product / person / location / event / generic)
 * - tag testuali (colori, oggetti, mood)
 *
 * Il risultato viene salvato su BrandAsset e diventa BASE STRATEGICA
 * per la generazione dei contenuti.
 */
class ImageUnderstandingService
{
    public function __construct(private readonly OpenAiService $openAi)
    {
    }

    /**
     * @return array{description:string,context:string,tags:array<int,string>}|null
     */
    public function analyze(string $storagePath): ?array
    {
        $absolutePath = Storage::disk('public')->path($storagePath);

        if (!is_file($absolutePath)) {
            Log::warning('ImageUnderstandingService: file not found', ['path' => $absolutePath]);
            return null;
        }

        $dataUri = $this->toDataUri($absolutePath);
        if ($dataUri === null) {
            return null;
        }

        try {
            return $this->callVision($dataUri);
        } catch (Throwable $e) {
            Log::warning('ImageUnderstandingService: vision failed', [
                'path'  => $storagePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{description:string,context:string,tags:array<int,string>}
     */
    private function callVision(string $dataUri): array
    {
        $model   = (string) (config('openai.vision_model') ?: config('openai.text_model') ?: 'gpt-4.1-mini');
        $timeout = (int) (config('openai.timeout') ?: 60);
        $url     = $this->openAi->url('/v1/responses');

        $schema = [
            'type'                 => 'object',
            'properties'           => [
                'description' => ['type' => 'string'],
                'context'     => [
                    'type' => 'string',
                    'enum' => ['product', 'person', 'location', 'event', 'logo', 'abstract', 'generic'],
                ],
                'tags'        => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required'             => ['description', 'context', 'tags'],
            'additionalProperties' => false,
        ];

        $res = \Illuminate\Support\Facades\Http::withToken((string) config('openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post($url, [
                'model' => $model,
                'input' => [
                    [
                        'role'    => 'system',
                        'content' => 'Sei un analista visivo per il marketing. Descrivi l\'immagine e fornisci contesto e tag utili per la creazione di contenuti social. Rispondi solo con JSON conforme allo schema.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'      => 'input_text',
                                'text'      => 'Analizza questa immagine brand e restituisci: description (cosa vedi, max 200 char), context (tipo di soggetto), tags (5-8 parole chiave in italiano).',
                            ],
                            [
                                'type'      => 'input_image',
                                'image_url' => $dataUri,
                            ],
                        ],
                    ],
                ],
                'text' => [
                    'format' => [
                        'type'   => 'json_schema',
                        'name'   => 'image_understanding',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if (!$res->successful()) {
            throw new \RuntimeException("ImageUnderstandingService vision error ({$res->status()})");
        }

        $data   = $res->json();
        $text   = $this->openAi->extractResponsesText($data);
        $parsed = json_decode($text, true);

        if (!is_array($parsed)) {
            throw new \RuntimeException('ImageUnderstandingService: invalid JSON response');
        }

        $tags = array_values(array_filter(array_map(
            fn ($t) => trim((string) $t),
            (array) ($parsed['tags'] ?? [])
        )));

        return [
            'description' => trim((string) ($parsed['description'] ?? '')),
            'context'     => trim((string) ($parsed['context'] ?? 'generic')),
            'tags'        => array_slice($tags, 0, 10),
        ];
    }

    private function toDataUri(string $absolutePath): ?string
    {
        $bytes = @file_get_contents($absolutePath);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $mime = mime_content_type($absolutePath) ?: 'image/jpeg';
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
