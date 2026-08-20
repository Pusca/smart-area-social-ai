@extends('layouts.app')

@section('content')
@php
    // Le query sono già filtrate sul tenant dell'utente dal global scope BelongsToTenant
    $u = auth()->user();

    $latestPlan = \App\Models\ContentPlan::latest('id')->first();

    $totalItems = \App\Models\ContentItem::count();
    $queuedItems = \App\Models\ContentItem::whereIn('ai_status', \App\Enums\AiStatus::busyValues())->count();
    $doneItems = \App\Models\ContentItem::where('ai_status', \App\Enums\AiStatus::Done->value)->count();
    $errorItems = \App\Models\ContentItem::where('ai_status', \App\Enums\AiStatus::Error->value)->count();

    $recentItems = \App\Models\ContentItem::query()
        ->orderByRaw("CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END")
        ->orderBy('scheduled_at')
        ->orderByDesc('id')
        ->limit(8)
        ->get();
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    {{-- Header --}}
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Dashboard</h1>
            <p class="text-sm text-gray-600 mt-1">
                Benvenuto, <span class="font-medium">{{ $u?->name }}</span> 👋
                <span class="mx-2">•</span>
                App: <span class="font-semibold">Social AI</span>
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('wizard.start') }}"
               class="inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold bg-black text-white hover:bg-gray-900">
                + Nuovo Piano
            </a>
            <a href="{{ route('posts.create') }}"
               class="inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold border bg-white hover:bg-gray-50">
                + Nuovo Post
            </a>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm text-gray-500">Post totali</div>
            <div class="mt-2 text-3xl font-bold">{{ $totalItems }}</div>
            <div class="mt-2 text-xs text-gray-400">Nel tuo tenant</div>
        </div>

        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm text-gray-500">In coda / pending</div>
            <div class="mt-2 text-3xl font-bold">{{ $queuedItems }}</div>
            <div class="mt-2 text-xs text-gray-400">AI non completata</div>
        </div>

        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm text-gray-500">Completati</div>
            <div class="mt-2 text-3xl font-bold">{{ $doneItems }}</div>
            <div class="mt-2 text-xs text-gray-400">AI: done</div>
        </div>

        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm text-gray-500">Errori</div>
            <div class="mt-2 text-3xl font-bold">{{ $errorItems }}</div>
            <div class="mt-2 text-xs text-gray-400">AI: error</div>
        </div>
    </div>

    {{-- Grid principale --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Azioni rapide --}}
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Azioni rapide</h2>
                <span class="text-xs text-gray-400">Social AI</span>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                <a href="{{ route('wizard.start') }}" class="rounded-xl border p-4 hover:bg-gray-50">
                    <div class="text-sm font-semibold">Wizard Piano</div>
                    <div class="text-xs text-gray-500 mt-1">Crea piano editoriale + coda AI</div>
                </a>

                <a href="{{ route('posts.index') }}" class="rounded-xl border p-4 hover:bg-gray-50">
                    <div class="text-sm font-semibold">Lista Post</div>
                    <div class="text-xs text-gray-500 mt-1">Gestisci e modifica contenuti</div>
                </a>

                <a href="{{ route('calendar') }}" class="rounded-xl border p-4 hover:bg-gray-50">
                    <div class="text-sm font-semibold">Calendario</div>
                    <div class="text-xs text-gray-500 mt-1">Vista planning e scheduling</div>
                </a>

                <a href="{{ route('content-items.index') }}" class="rounded-xl border p-4 hover:bg-gray-50">
                    <div class="text-sm font-semibold">Galleria contenuti</div>
                    <div class="text-xs text-gray-500 mt-1">Anteprima post con immagini AI</div>
                </a>

                <a href="{{ route('profile.brand') }}" class="rounded-xl border p-4 hover:bg-gray-50">
                    <div class="text-sm font-semibold">Profilo attività</div>
                    <div class="text-xs text-gray-500 mt-1">Il contesto che guida l'AI: più è ricco, meglio scrive</div>
                </a>
            </div>

            <div class="mt-4 text-xs text-gray-400">
                Suggerimento: se vuoi far macinare la coda: <span class="font-mono">php artisan queue:work</span>
            </div>
        </div>

        {{-- Ultimo piano --}}
        <div class="lg:col-span-2 rounded-2xl border bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold">Ultimo piano</h2>

                @if($latestPlan)
                    <form method="POST" action="{{ route('ai.plan.generate', $latestPlan->id) }}">
                        @csrf
                        <button class="rounded-xl px-4 py-2 text-sm font-semibold bg-black text-white hover:bg-gray-900">
                            Genera AI Piano
                        </button>
                    </form>
                @endif
            </div>

            @if(!$latestPlan)
                <div class="mt-4 rounded-xl border border-dashed p-6 text-center">
                    <div class="text-sm text-gray-600">Nessun piano trovato.</div>
                    <a href="{{ route('wizard.start') }}"
                       class="inline-flex mt-3 rounded-xl px-4 py-2 text-sm font-semibold bg-black text-white hover:bg-gray-900">
                        Crea il primo piano
                    </a>
                </div>
            @else
                <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <div class="text-sm text-gray-500">Nome piano</div>
                        <div class="text-base font-semibold">{{ $latestPlan->name }}</div>
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ \Illuminate\Support\Carbon::parse($latestPlan->start_date)->format('d/m/Y') }}
                        → {{ \Illuminate\Support\Carbon::parse($latestPlan->end_date)->format('d/m/Y') }}
                    </div>
                </div>

                <div class="mt-5">
                    <div class="text-sm font-semibold">Ultimi post</div>

                    <div class="mt-3 divide-y rounded-xl border">
                        @forelse($recentItems as $item)
                            <div class="p-4 flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="text-xs text-gray-500">
                                        {{ ucfirst($item->platform) }} • {{ strtoupper($item->format) }}
                                        @if($item->scheduled_at)
                                            • {{ optional($item->scheduled_at)->format('d/m H:i') }}
                                        @endif
                                    </div>
                                    <div class="mt-1 font-semibold truncate">{{ $item->title }}</div>
                                    <div class="mt-1 text-xs text-gray-500 truncate">
                                        {{ $item->ai_caption ?: $item->caption ?: '—' }}
                                    </div>
                                </div>

                                <x-ai-status-badge class="shrink-0" :status="$item->ai_status" :item-id="$item->id" />
                            </div>
                        @empty
                            <div class="p-6 text-center text-sm text-gray-600">
                                Nessun post trovato.
                                <div class="mt-3">
                                    <a href="{{ route('wizard.start') }}"
                                       class="inline-flex rounded-xl px-4 py-2 text-sm font-semibold bg-black text-white hover:bg-gray-900">
                                        Crea piano
                                    </a>
                                </div>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-4 flex gap-2">
                        <a href="{{ route('posts.index') }}" class="rounded-xl px-4 py-2 text-sm font-semibold border bg-white hover:bg-gray-50">
                            Vai ai post
                        </a>
                        <a href="{{ route('calendar') }}" class="rounded-xl px-4 py-2 text-sm font-semibold border bg-white hover:bg-gray-50">
                            Vai al calendario
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@include('partials.ai-status-poller')
@endsection
