@php
    /** @var \App\Models\BrandAsset $a */
    $aiDescription  = trim((string) ($a->ai_description ?? ''));
    $aiContext      = trim((string) ($a->ai_context ?? ''));
    $aiTags         = is_array($a->ai_tags) ? array_filter($a->ai_tags) : [];
    $hasAi          = $a->ai_analyzed_at !== null;
    $isPending      = !$hasAi && $a->isImage();
    $descUpdateUrl  = route('profile.brand.asset.description.update', $a->id);

    $contextLabels = [
        'product'  => 'Prodotto',
        'person'   => 'Persona',
        'location' => 'Location',
        'event'    => 'Evento',
        'logo'     => 'Logo',
        'abstract' => 'Astratto',
        'generic'  => 'Generico',
    ];
    $contextColors = [
        'product'  => 'bg-blue-100 text-blue-700',
        'person'   => 'bg-purple-100 text-purple-700',
        'location' => 'bg-green-100 text-green-700',
        'event'    => 'bg-yellow-100 text-yellow-700',
        'logo'     => 'bg-gray-100 text-gray-700',
        'abstract' => 'bg-pink-100 text-pink-700',
        'generic'  => 'bg-gray-100 text-gray-500',
    ];
    $ctxLabel = $contextLabels[$aiContext] ?? ucfirst($aiContext);
    $ctxColor = $contextColors[$aiContext] ?? 'bg-gray-100 text-gray-500';
@endphp

<article class="overflow-hidden rounded-xl border border-gray-200 bg-white" data-asset-id="{{ $a->id }}">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3 py-2">
        <label class="flex items-center gap-2 text-xs text-gray-600">
            <input type="checkbox" class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                   value="{{ $a->id }}"
                   data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
            Seleziona
        </label>
        @if($hasAi && $aiContext)
            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $ctxColor }}">{{ $ctxLabel }}</span>
        @elseif($isPending)
            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-600">In analisi…</span>
        @endif
    </div>

    {{-- Thumbnail --}}
    <div class="{{ $aspectClass }} overflow-hidden bg-gray-50">
        <img src="{{ asset('storage/' . ltrim($a->path, '/')) }}"
             class="h-full w-full object-{{ $objectFit }}"
             alt="{{ $a->original_name ?? 'asset' }}">
    </div>

    {{-- Filename --}}
    <div class="truncate px-3 py-1.5 text-[11px] text-gray-500">{{ $a->original_name ?? basename((string) $a->path) }}</div>

    {{-- AI Description block --}}
    <div class="px-3 pb-3">
        @if($hasAi)
            <div class="ai-description-block" data-update-url="{{ $descUpdateUrl }}">
                <textarea
                    class="ai-desc-textarea w-full resize-none rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs text-gray-700 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-300"
                    rows="3"
                    maxlength="500"
                    placeholder="Descrizione AI dell'immagine…"
                    data-original="{{ $aiDescription }}"
                >{{ $aiDescription }}</textarea>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <div class="flex flex-wrap gap-1">
                        @foreach(array_slice($aiTags, 0, 5) as $tag)
                            <span class="rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-600">{{ $tag }}</span>
                        @endforeach
                    </div>
                    <button type="button"
                            class="ai-desc-save hidden shrink-0 rounded-lg bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-indigo-700">
                        Salva
                    </button>
                </div>
                <p class="ai-desc-status mt-1 hidden text-[10px] text-green-600">Salvato</p>
            </div>
        @elseif($isPending)
            <p class="rounded-lg bg-amber-50 px-2 py-1.5 text-[11px] text-amber-600">
                L'analisi AI verrà completata a breve dopo il caricamento.
            </p>
        @endif
    </div>

    {{-- Delete --}}
    <div class="border-t border-gray-100 px-3 pb-3 pt-2">
        <form method="POST" action="{{ route('profile.brand.asset.destroy', $a->id) }}"
              onsubmit="return confirm('{{ $deleteConfirm }}');">
            @csrf
            @method('DELETE')
            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                Elimina
            </button>
        </form>
    </div>
</article>
