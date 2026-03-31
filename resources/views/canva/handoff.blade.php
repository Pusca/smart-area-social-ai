<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="ui-title-lg">Manual Canva finishing</h2>
                <div class="mt-1 text-sm text-muted">Payload preparato da Social AI per completare il design in Canva senza perdere strategia o contesto.</div>
            </div>
            <a href="{{ route('content-items.show', $canvaDesign->contentItem) }}" class="ui-btn-secondary">Torna al contenuto</a>
        </div>
    </x-slot>

    @php
        $payload = is_array(data_get($canvaDesign->generation_payload_json, 'design_payload')) ? (array) data_get($canvaDesign->generation_payload_json, 'design_payload') : [];
        $assetBundle = is_array(data_get($canvaDesign->generation_payload_json, 'asset_bundle')) ? (array) data_get($canvaDesign->generation_payload_json, 'asset_bundle') : [];
        $slides = collect((array) ($payload['slides'] ?? []))->filter(fn ($row) => is_array($row))->values();
    @endphp

    <section class="ui-shell ui-page">
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <div class="ui-card p-5">
                <div class="mb-2 text-sm text-muted">Azioni</div>
                <div class="flex flex-wrap gap-2">
                    @if(!empty($canvaDesign->canva_edit_url))
                        <a href="{{ $canvaDesign->canva_edit_url }}" target="_blank" rel="noreferrer" class="ui-btn-primary">
                            Apri Canva
                        </a>
                    @endif
                </div>

                <form method="POST" action="{{ route('canva.designs.link', $canvaDesign) }}" class="mt-4 rounded-lg border border-gray-200 px-4 py-4">
                    @csrf
                    <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">Collega il design creato in Canva</label>
                    <p class="mt-1 text-sm text-gray-600">Dopo aver rifinito il layout, incolla URL o design ID per riattivare l export dentro Social AI.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <input type="text" name="design_url_or_id" placeholder="https://www.canva.com/design/... oppure design ID" class="min-w-[260px] flex-1 rounded-xl border-gray-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                        <button type="submit" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Salva design
                        </button>
                    </div>
                </form>
            </div>

            <div class="space-y-4">
                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Design payload</div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Headline</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ $payload['headline'] ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Subheadline</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ $payload['subheadline'] ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">CTA</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ $payload['cta'] ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Brand claim</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ $payload['brand_claim'] ?? '-' }}</div>
                        </div>
                    </div>

                    <div class="mt-3 rounded-lg border border-gray-200 px-3 py-3">
                        <div class="text-xs text-muted">Body</div>
                        <div class="mt-1 whitespace-pre-line text-sm text-text">{{ $payload['body'] ?? '-' }}</div>
                    </div>
                </div>

                <div class="ui-card p-5">
                    <div class="mb-2 text-sm text-muted">Asset bundle</div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Primary image</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($assetBundle, 'primary_image.source_path', '-') }}</div>
                        </div>
                        <div class="rounded-lg bg-surface-2 px-3 py-2">
                            <div class="text-xs text-muted">Logo</div>
                            <div class="mt-1 text-sm font-semibold text-text">{{ data_get($assetBundle, 'logo.source_path', '-') }}</div>
                        </div>
                    </div>
                </div>

                @if($slides->isNotEmpty())
                    <div class="ui-card p-5">
                        <div class="mb-2 text-sm text-muted">Slides</div>
                        <div class="space-y-2">
                            @foreach($slides as $index => $slide)
                                <div class="rounded-lg border border-gray-200 px-3 py-3">
                                    <div class="text-xs text-muted">Slide {{ $index + 1 }}</div>
                                    <div class="mt-1 text-sm font-semibold text-text">{{ $slide['headline'] ?? '-' }}</div>
                                    <div class="mt-1 text-sm text-text">{{ $slide['body'] ?? '-' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-app-layout>
