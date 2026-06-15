<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsService
{
    private function baseUrl(): string
    {
        return rtrim((string) (config('elevenlabs.base_url') ?: 'https://api.elevenlabs.io'), '/');
    }

    private function apiKey(): string
    {
        $key = trim((string) (config('elevenlabs.api_key') ?: env('ELEVENLABS_API_KEY') ?: ''));
        if ($key === '') {
            throw new RuntimeException('Missing ELEVENLABS_API_KEY');
        }

        return $key;
    }

    public function isConfigured(): bool
    {
        return trim((string) (config('elevenlabs.api_key') ?: env('ELEVENLABS_API_KEY') ?: '')) !== '';
    }

    private function request(int $timeout, bool $asJson = true): PendingRequest
    {
        $req = Http::withHeaders([
            'xi-api-key' => $this->apiKey(),
        ])
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout((int) (config('elevenlabs.connect_timeout') ?: 15));

        return $asJson ? $req->asJson() : $req;
    }

    /**
     * @return array{voice_id:string,raw:array<string,mixed>}
     */
    public function createVoiceClone(string $name, string $sampleAbsolutePath, array $options = []): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Missing voice clone name.');
        }
        if (!is_file($sampleAbsolutePath)) {
            throw new RuntimeException('Missing voice sample file for ElevenLabs clone.');
        }

        $description = trim((string) ($options['description'] ?? config('elevenlabs.clone_description') ?: ''));
        $timeout = (int) (config('elevenlabs.timeout') ?: 90);
        $url = $this->baseUrl() . '/v1/voices/add';
        $mime = mime_content_type($sampleAbsolutePath) ?: 'audio/mpeg';
        $bytes = @file_get_contents($sampleAbsolutePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to read voice sample file.');
        }

        $req = $this->request($timeout, false)
            ->attach('files', $bytes, basename($sampleAbsolutePath), ['Content-Type' => $mime]);

        $payload = [
            'name' => $name,
        ];
        if ($description !== '') {
            $payload['description'] = $description;
        }

        $res = $req->post($url, $payload);
        if (!$res->successful()) {
            throw new RuntimeException("ElevenLabs create voice error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $data = $res->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid ElevenLabs create voice response payload.');
        }

        $voiceId = trim((string) ($data['voice_id'] ?? data_get($data, 'voice.voice_id', '')));
        if ($voiceId === '') {
            throw new RuntimeException('Missing ElevenLabs voice_id in clone response.');
        }

        return [
            'voice_id' => $voiceId,
            'raw' => $data,
        ];
    }

    public function generateSpeechMp3(string $text, string $voiceId, array $options = []): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $voiceId = trim($voiceId);
        if ($text === '') {
            throw new RuntimeException('Missing text for ElevenLabs speech generation.');
        }
        if ($voiceId === '') {
            throw new RuntimeException('Missing ElevenLabs voice id.');
        }

        $timeout = (int) (config('elevenlabs.timeout') ?: 90);
        $url = $this->baseUrl() . '/v1/text-to-speech/' . rawurlencode($voiceId);
        $modelId = trim((string) ($options['model_id'] ?? config('elevenlabs.model_id') ?: 'eleven_multilingual_v2'));
        $outputFormat = trim((string) ($options['output_format'] ?? config('elevenlabs.output_format') ?: 'mp3_44100_128'));

        $payload = [
            'text' => $text,
            'model_id' => $modelId,
            'output_format' => $outputFormat,
            'voice_settings' => [
                'stability' => (float) ($options['stability'] ?? config('elevenlabs.stability') ?: 0.45),
                'similarity_boost' => (float) ($options['similarity_boost'] ?? config('elevenlabs.similarity_boost') ?: 0.78),
                'style' => (float) ($options['style'] ?? config('elevenlabs.style') ?: 0.15),
                'use_speaker_boost' => (bool) ($options['use_speaker_boost'] ?? config('elevenlabs.use_speaker_boost') ?: true),
            ],
        ];

        $res = $this->request($timeout, true)->post($url, $payload);
        if (!$res->successful()) {
            throw new RuntimeException("ElevenLabs speech error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        $bytes = $res->body();
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('ElevenLabs speech empty response body.');
        }

        return $bytes;
    }
}