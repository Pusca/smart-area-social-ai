<?php

namespace Tests\Unit;

use App\Services\NanoBananaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NanoBananaServiceTest extends TestCase
{
    public function test_it_retries_with_image_only_when_gemini_returns_text_without_image(): void
    {
        config()->set('nanobanana.api_key', 'AIzaFakeKeyForTests');
        config()->set('nanobanana.base_url', 'https://generativelanguage.googleapis.com');
        config()->set('nanobanana.api_version', 'v1beta');
        config()->set('nanobanana.generate_endpoint', '/{version}/models/{model}:generateContent');
        config()->set('nanobanana.image_model', 'gemini-2.5-flash-image');
        config()->set('nanobanana.response_modalities', 'TEXT,IMAGE');
        config()->set('nanobanana.aspect_ratio', '4:5');

        $calls = 0;

        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $payload = $request->data();

            if ($calls === 1) {
                $this->assertSame(['TEXT', 'IMAGE'], data_get($payload, 'generationConfig.responseModalities'));

                return Http::response([
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => [
                            'parts' => [
                                ['text' => 'Posso descrivere il visual, ma non ho generato un immagine.'],
                            ],
                        ],
                    ]],
                ], 200);
            }

            $this->assertSame(['IMAGE'], data_get($payload, 'generationConfig.responseModalities'));

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => 'ZmFrZUJhc2U2NA==',
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200);
        });

        $result = app(NanoBananaService::class)->generateImageBase64('Crea un visual social per il ristorante.');

        $this->assertSame('ZmFrZUJhc2U2NA==', $result['b64']);
        Http::assertSentCount(2);
    }
}
