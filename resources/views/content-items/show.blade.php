<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                @php
                    $headerAiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                    $headerPublicationInfo = \App\Support\UiStatus::publication((string) $item->status);
                @endphp
                <h2 class="ui-title-lg">{{ $item->title }}</h2>
                <div class="mt-1 text-sm text-muted">
                    {{ strtoupper($item->platform) }} - {{ $item->format }} -
                    {{ optional($item->scheduled_at)->format('d/m/Y H:i') }}
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $headerAiInfo['badge'] }}">
                        AI {{ $headerAiInfo['label'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $headerPublicationInfo['badge'] }}">
                        {{ $headerPublicationInfo['label'] }}
                    </span>
                </div>
            </div>

            <a href="{{ route('content-items.index') }}" class="ui-btn-secondary">Torna alla lista</a>
        </div>
    </x-slot>

    @php
        $img = $item->ai_image_path ? asset('storage/' . $item->ai_image_path) : null;
        $hashtags = $item->ai_hashtags;
        $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
        $video = null;
        $metaVideoPath = trim((string) data_get($item->ai_meta, 'video_generation.video_path', ''));
        if ($metaVideoPath !== '') {
            $video = asset('storage/' . ltrim($metaVideoPath, '/'));
        }
        $assetsRaw = $item->assets ?? [];
        $assetsList = is_string($assetsRaw) ? (json_decode($assetsRaw, true) ?: []) : (is_array($assetsRaw) ? $assetsRaw : []);
        if ($video === null) {
            foreach ($assetsList as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $assetPath = trim((string) ($asset['path'] ?? ''));
                if ($assetPath === '') {
                    continue;
                }
                $assetType = strtolower(trim((string) ($asset['type'] ?? '')));
                $ext = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
                if (str_contains($assetType, 'video') || in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi'], true)) {
                    $video = asset('storage/' . ltrim($assetPath, '/'));
                    break;
                }
            }
        }

        if (is_string($hashtags)) {
            $decoded = json_decode($hashtags, true);
            $hashtags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($hashtags)) $hashtags = [];
    @endphp

    <section class="ui-shell ui-page">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="ui-card overflow-hidden">
                <div class="bg-surface-2">
                    @if($video)
                        <video class="h-auto w-full object-cover" controls preload="none">
                            <source src="{{ $video }}" type="video/mp4" />
                        </video>
                    @elseif($img)
                        <img src="{{ $img }}" alt="AI image" class="h-auto w-full object-cover" />
                    @else
                        <div class="flex aspect-square w-full items-center justify-center text-muted">Nessun visual generato</div>
                    @endif
                </div>

                <div class="border-t border-app p-4">
                    <div class="text-sm text-muted">
                        File visual: <span class="font-mono">{{ $item->ai_image_path ?? '-' }}</span>
                    </div>

                    <div class="mt-2 rounded-lg border p-3 text-sm {{ $aiInfo['badge'] }}">
                        <p class="font-semibold">Stato AI: {{ $aiInfo['label'] }}</p>
                        <p class="mt-1 text-xs opacity-90">{{ $aiInfo['description'] }}</p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Caption</div>
                    <div class="whitespace-pre-line text-text">{{ $item->ai_caption ?? '-' }}</div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Hashtags</div>
                    @if(count($hashtags))
                        <div class="flex flex-wrap gap-2">
                            @foreach($hashtags as $h)
                                <span class="rounded-full bg-surface-2 px-2 py-1 text-sm text-text">{{ $h }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="text-muted">-</div>
                    @endif
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">CTA</div>
                    <div class="text-text">{{ $item->ai_cta ?? '-' }}</div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Image prompt</div>
                    <div class="text-text">{{ $item->ai_image_prompt ?? '-' }}</div>
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
