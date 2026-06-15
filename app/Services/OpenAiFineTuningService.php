<?php

namespace App\Services;

use RuntimeException;

class OpenAiFineTuningService extends OpenAiService
{
    /**
     * @return array<string, mixed>
     */
    public function uploadDatasetFile(string $absolutePath, ?string $filename = null): array
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Fine-tuning dataset file not found.');
        }

        $timeout = (int) (config('openai.fine_tuning_timeout') ?: config('openai.timeout') ?: 60);
        $url = $this->url('/v1/files');
        $name = trim((string) ($filename ?: basename($absolutePath)));
        $stream = fopen($absolutePath, 'r');
        if ($stream === false) {
            throw new RuntimeException('Unable to open fine-tuning dataset file.');
        }

        try {
            $res = $this->request($timeout, false)
                ->attach('file', $stream, $name)
                ->post($url, [
                    'purpose' => 'fine-tune',
                ]);
        } finally {
            fclose($stream);
        }

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI file upload error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        return (array) ($res->json() ?: []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createFineTuningJob(array $payload): array
    {
        $timeout = (int) (config('openai.fine_tuning_timeout') ?: config('openai.timeout') ?: 60);
        $url = $this->url('/v1/fine_tuning/jobs');
        $res = $this->request($timeout, true)->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI fine-tuning create error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        return (array) ($res->json() ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveFineTuningJob(string $jobId): array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            throw new RuntimeException('Missing fine-tuning job id.');
        }

        $timeout = (int) (config('openai.fine_tuning_timeout') ?: config('openai.timeout') ?: 60);
        $url = $this->url('/v1/fine_tuning/jobs/' . rawurlencode($jobId));
        $res = $this->request($timeout, true)->get($url);

        if (!$res->successful()) {
            throw new RuntimeException("OpenAI fine-tuning retrieve error ({$res->status()}) URL={$url} BODY=" . $res->body());
        }

        return (array) ($res->json() ?: []);
    }
}