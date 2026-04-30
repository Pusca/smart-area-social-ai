@extends('layouts.app')

@section('content')
@php
    $typeLabels = [
        'person'       => 'Persona',
        'product'      => 'Prodotto',
        'location'     => 'Location',
        'brand_object' => 'Brand Object',
    ];
    $typeBadge = [
        'person'       => 'ui-badge-blue',
        'product'      => 'ui-badge-green',
        'location'     => 'ui-badge-yellow',
        'brand_object' => 'ui-badge-purple',
    ];
    $typeIcons = [
        'person'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zm-4 7a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
        'product'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>',
        'location'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'brand_object' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>',
    ];
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="ui-kicker mb-1">Identità visive</p>
            <h1 class="ui-h1">Identity Asset</h1>
            <p class="ui-subtitle mt-1">Soggetti visivi con identità lockata: persone, prodotti, luoghi e oggetti brand riconoscibili in ogni contenuto generato.</p>
        </div>
        <a href="{{ route('identity-assets.create') }}" class="ui-btn-primary gap-2">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nuova identità
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    @if($identities->isEmpty())
        {{-- Empty state --}}
        <div class="flex min-h-[40vh] flex-col items-center justify-center rounded-[2rem] border border-dashed px-6 py-16 text-center" style="border-color:rgba(10,45,111,0.14);background:rgba(239,245,255,0.4);">
            <div class="flex h-16 w-16 items-center justify-center rounded-full" style="background:linear-gradient(135deg,#0A2D6F,#1498F3);">
                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h2 class="mt-5 text-xl font-semibold" style="color:#0A2D6F;">Nessuna identità ancora</h2>
            <p class="mt-2 max-w-sm text-sm" style="color:#64748b;">Crea il tuo primo Identity Asset: definisci le caratteristiche visive invarianti di una persona, prodotto o luogo. L'AI le rispetterà in ogni contenuto generato.</p>
            <a href="{{ route('identity-assets.create') }}" class="ui-btn-primary mt-6 gap-2">
                Crea identità
            </a>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($identities as $identity)
            @php
                $canonical = $identity->canonicalMedia->first();
                $canonicalUrl = $canonical ? \Storage::disk('public')->url($canonical->path) : null;
                $badge = $typeBadge[$identity->type] ?? 'ui-badge-gray';
                $label = $typeLabels[$identity->type] ?? $identity->type;
                $icon = $typeIcons[$identity->type] ?? $typeIcons['person'];
            @endphp
            <div class="ui-card group flex flex-col gap-0 overflow-hidden p-0">
                {{-- Thumbnail / Placeholder --}}
                <div class="relative h-40 overflow-hidden" style="background:linear-gradient(135deg,#071D4E,#0A2D6F);">
                    @if($canonicalUrl)
                        <img src="{{ $canonicalUrl }}" alt="{{ $identity->name }}" class="h-full w-full object-cover opacity-90 transition duration-300 group-hover:scale-105">
                    @else
                        <div class="flex h-full w-full items-center justify-center">
                            <svg class="h-12 w-12 text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icon !!}</svg>
                        </div>
                    @endif
                    {{-- Lock badge --}}
                    @if($identity->locked_visual_identity)
                    <div class="absolute right-3 top-3 flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold text-white" style="background:rgba(0,0,0,0.45);backdrop-filter:blur(6px);">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Locked
                    </div>
                    @endif
                    {{-- Type badge --}}
                    <div class="absolute bottom-3 left-3">
                        <span class="ui-chip {{ $badge }}">{{ $label }}</span>
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div>
                        <h3 class="font-semibold" style="color:#0A2D6F;">{{ $identity->name }}</h3>
                        @if($identity->description)
                            <p class="mt-0.5 line-clamp-2 text-xs" style="color:#64748b;">{{ $identity->description }}</p>
                        @endif
                    </div>

                    {{-- Stats --}}
                    <div class="flex items-center gap-3 text-xs" style="color:#64748b;">
                        <span>{{ $identity->asset_variables_count }} variabili</span>
                        <span>·</span>
                        <span>{{ $identity->alter_egos_count }} alter ego</span>
                        @if($identity->identity_prompt_version > 0)
                            <span>·</span>
                            <span>v{{ $identity->identity_prompt_version }}</span>
                        @endif
                    </div>

                    <div class="mt-auto flex items-center gap-2">
                        <a href="{{ route('identity-assets.show', $identity) }}" class="ui-btn-primary flex-1 justify-center text-xs py-2">
                            Gestisci
                        </a>
                        <a href="{{ route('identity-assets.edit', $identity) }}" class="ui-btn-secondary px-3 py-2 text-xs">
                            Modifica
                        </a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif

</section>
@endsection
