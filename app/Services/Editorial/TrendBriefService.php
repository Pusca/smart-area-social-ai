<?php

namespace App\Services\Editorial;

use App\Models\TrendBrief;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TrendBriefService
{
    public function getBriefForTenant(int $tenantId): array
    {
        if (!config('editorial.trend.enabled', false)) {
            return ['enabled' => false, 'items' => []];
        }

        $ttlMinutes = (int) config('editorial.trend.ttl_minutes', 180);
        $cached = TrendBrief::query()->where('tenant_id', $tenantId)->first();
        if ($cached && $cached->fetched_at && $cached->fetched_at->gt(Carbon::now()->subMinutes($ttlMinutes))) {
            return is_array($cached->snapshot) ? $cached->snapshot : ['enabled' => true, 'items' => []];
        }

        $snapshot = $this->fetchSnapshot();

        TrendBrief::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'snapshot' => $snapshot,
                'fetched_at' => Carbon::now(),
            ]
        );

        return $snapshot;
    }

    private function fetchSnapshot(): array
    {
        $sources = (array) config('editorial.trend.sources', []);
        $items = [];

        foreach ($sources as $source) {
            if (!is_string($source) || $source === '') {
                continue;
            }

            try {
                $context = stream_context_create([
                    'http' => ['timeout' => 4, 'user_agent' => 'SocialAI-TrendFetcher/1.0'],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $xml = @file_get_contents($source, false, $context);
                if (!$xml) {
                    continue;
                }
                $feed = @simplexml_load_string($xml);
                if ($feed === false) {
                    continue;
                }

                $nodes = $feed->channel->item ?? $feed->entry ?? [];
                $count = 0;
                foreach ($nodes as $node) {
                    if ($count >= 3) {
                        break;
                    }
                    $title = trim((string) ($node->title ?? ''));
                    $link = trim((string) ($node->link ?? ''));
                    if ($title === '') {
                        continue;
                    }
                    $items[] = [
                        'title' => $title,
                        'link' => $link,
                        'source' => $source,
                    ];
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::debug('TrendBrief fetch failed', ['source' => $source, 'error' => $e->getMessage()]);
            }
        }

        return [
            'enabled' => true,
            'fetched_at' => Carbon::now()->toDateTimeString(),
            'items' => array_values(array_slice($items, 0, 8)),
        ];
    }
}

