@php
    $url = \Storage::disk('public')->exists($asset->path) ? \Storage::disk('public')->url($asset->path) : null;
    $roleBadgeColors = [
        'canonical'  => 'background:#0A2D6F;color:#fff;',
        'reference'  => 'background:rgba(100,116,139,0.15);color:#475569;',
        'negative'   => 'background:rgba(220,38,38,0.12);color:#dc2626;',
    ];
    $roleLabels = ['canonical' => 'Canonico', 'reference' => 'Riferimento', 'negative' => 'Negativo'];
@endphp
<div class="group relative overflow-hidden rounded-2xl border" style="border-color:rgba(10,45,111,0.12);" x-data>
    @if($url)
        <img src="{{ $url }}" alt="{{ $asset->original_name }}" class="h-32 w-full object-cover transition duration-300 group-hover:scale-105">
    @else
        <div class="flex h-32 w-full items-center justify-center" style="background:rgba(239,245,255,0.8);">
            <svg class="h-8 w-8" style="color:#cbd5e1;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
    @endif

    {{-- Role badge --}}
    <div class="absolute left-2 top-2">
        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" style="{{ $roleBadgeColors[$role] ?? '' }}">
            {{ $roleLabels[$role] ?? $role }}
        </span>
    </div>

    {{-- Remove button --}}
    <button
        @click="$dispatch('detach-media', { id: {{ $asset->id }} })"
        onclick="if(confirm('Rimuovere questo media?')) { fetch('{{ route("identity-assets.media.detach", request()->route("identityAsset")) }}', { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ brand_asset_id: {{ $asset->id }} }) }).then(() => window.location.reload()); }"
        class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-full opacity-0 transition group-hover:opacity-100"
        style="background:rgba(220,38,38,0.9);"
        title="Rimuovi"
    >
        <svg class="h-3 w-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>

    @if($asset->original_name)
    <div class="px-2 py-1.5">
        <p class="truncate text-[11px]" style="color:#64748b;">{{ $asset->original_name }}</p>
    </div>
    @endif
</div>
