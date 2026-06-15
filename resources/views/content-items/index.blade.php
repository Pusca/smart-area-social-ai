<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="ui-title-lg">Galleria contenuti</h2>
            <div class="text-sm text-muted">{{ $items->total() }} elementi</div>
        </div>
    </x-slot>

    <section class="ui-shell ui-page">
        <div class="ui-card p-4 sm:p-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($items as $item)
                    @php
                        $img = $item->ai_image_path ? asset('storage/' . $item->ai_image_path) : null;
                        $aiInfo = \App\Support\UiStatus::ai((string) $item->ai_status);
                    @endphp

                    <a href="{{ route('content-items.show', $item) }}" class="group overflow-hidden rounded-xl border border-app bg-surface transition hover:-translate-y-1 hover:shadow-card">
                        <div class="aspect-square overflow-hidden bg-surface-2">
                            @if($img)
                                <img src="{{ $img }}" alt="AI image" class="h-full w-full object-cover transition group-hover:scale-[1.03]" loading="lazy" />
                            @else
                                <div class="flex h-full w-full items-center justify-center text-sm text-muted">Nessuna immagine</div>
                            @endif
                        </div>

                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="rounded-full bg-surface-2 px-2 py-1 text-xs text-muted">
                                    {{ strtoupper($item->platform) }} - {{ $item->format }}
                                </div>

                                <div class="ui-pill {{ $aiInfo['pill'] }}">
                                    AI: {{ $aiInfo['label'] }}
                                </div>
                            </div>

                            <div class="mt-2 line-clamp-2 font-semibold text-text">
                                {{ $item->title }}
                            </div>

                            <div class="mt-2 flex flex-wrap gap-1 text-[11px]">
                                @if($item->rubric)
                                    <span class="rounded-full bg-surface-2 px-2 py-0.5 text-brand">{{ $item->rubric }}</span>
                                @endif
                                @if($item->series_key)
                                    <span class="rounded-full bg-surface-2 px-2 py-0.5 text-accent">Serie {{ $item->episode_number ? 'Ep. '.$item->episode_number : '' }}</span>
                                @endif
                            </div>

                            <div class="mt-1 text-sm text-muted">
                                {{ optional($item->scheduled_at)->format('d/m/Y H:i') }}
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $items->links() }}
            </div>
        </div>
    </section>
</x-app-layout>
