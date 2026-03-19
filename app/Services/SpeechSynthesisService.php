<?php

namespace App\Services;

use App\Models\AssetVariable;
use App\Support\SpeechProviderResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SpeechSynthesisService
{
    public function __construct(
        private readonly OpenAiService $openAi,
        private readonly ElevenLabsService $elevenLabs
    ) {
    }

    /**
     * @param  array<string, mixed>  $voiceContext
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>|null
     */
    public function synthesizeWithVoiceContext(
        string $text,
        ?AssetVariable $variable = null,
        array $voiceContext = [],
        array $providerMatrix = []
    ): ?array {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $voiceId = trim((string) data_get($voiceContext, 'provider_voice_id', ''));
        $samplePath = trim((string) data_get($voiceContext, 'asset_path', ''));
        $sampleName = trim((string) data_get($voiceContext, 'asset_name', ''));
        $preferredProvider = trim((string) data_get($voiceContext, 'provider', ''));
        $cloneProviderDefault = strtolower(trim((string) config('generation.voice_clone_provider_default', 'elevenlabs')));
        $provider = SpeechProviderResolver::resolve(
            $preferredProvider !== ''
                ? $preferredProvider
                : (($voiceId !== '' || $samplePath !== '') ? $cloneProviderDefault : (string) data_get($providerMatrix, 'speech.provider', '')),
            SpeechProviderResolver::default()
        );

        if ($provider !== 'elevenlabs' || !$this->elevenLabs->isConfigured()) {
            return null;
        }

        if ($voiceId === '' && $samplePath !== '' && Storage::disk('public')->exists($samplePath)) {
            $clone = $this->elevenLabs->createVoiceClone(
                name: $this->voiceCloneName($variable, $voiceContext),
                sampleAbsolutePath: Storage::disk('public')->path($samplePath),
                options: [
                    'description' => $this->voiceCloneDescription($variable, $voiceContext),
                ]
            );
            $voiceId = (string) ($clone['voice_id'] ?? '');

            if ($voiceId !== '' && $variable instanceof AssetVariable) {
                $profile = is_array($variable->profile) ? $variable->profile : [];
                data_set($profile, 'voice_reference.provider', 'elevenlabs');
                data_set($profile, 'voice_reference.provider_voice_id', $voiceId);
                data_set($profile, 'voice_reference.status', 'ready');
                data_set($profile, 'voice_reference.sample_path', $samplePath);
                data_set($profile, 'voice_reference.sample_name', $sampleName);

                $variable->forceFill([
                    'voice_provider' => 'elevenlabs',
                    'voice_provider_voice_id' => $voiceId,
                    'voice_status' => 'ready',
                    'profile' => $profile,
                ])->save();
            }
        }

        if ($voiceId === '') {
            return null;
        }

        $bytes = $this->elevenLabs->generateSpeechMp3($text, $voiceId);

        return [
            'bytes' => $bytes,
            'provider' => 'elevenlabs',
            'voice_id' => $voiceId,
            'extension' => 'mp3',
            'source' => 'persona_voice_clone',
            'label' => trim((string) data_get($voiceContext, 'label', 'Voce persona')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function synthesizeWithDefaultVoice(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $bytes = $this->openAi->generateSpeechMp3($text);

        return [
            'bytes' => $bytes,
            'provider' => 'openai',
            'voice_id' => null,
            'extension' => 'mp3',
            'source' => 'openai_tts',
            'label' => 'Voce sintetica standard',
        ];
    }

    /**
     * @param  array<string, mixed>  $voiceContext
     */
    private function voiceCloneName(?AssetVariable $variable, array $voiceContext): string
    {
        $name = trim((string) ($variable?->name ?? data_get($voiceContext, 'name', '')));
        if ($name === '') {
            $name = 'Voce Brand Center';
        }

        return Str::limit($name . ' - Brand Center', 60, '');
    }

    /**
     * @param  array<string, mixed>  $voiceContext
     */
    private function voiceCloneDescription(?AssetVariable $variable, array $voiceContext): string
    {
        $base = trim((string) data_get($voiceContext, 'description', ''));
        if ($base !== '') {
            return Str::limit($base, 200, '');
        }

        $parts = array_filter([
            $variable?->description,
            (string) data_get($voiceContext, 'label', ''),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        return Str::limit(implode(' | ', $parts), 200, '');
    }
}