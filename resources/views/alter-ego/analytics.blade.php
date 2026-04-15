@extends('layouts.app')

@section('content')
@php
    $avgScore   = $stats['avg_score'];
    $scoredCount = (int) $stats['scored_count'];
    $postCount   = (int) $stats['post_count'];
    $timeline    = (array) $stats['scores_timeline'];
    $recentItems = $stats['recent_items'];

    $badgeClass = fn (int $s) => \App\Services\AlterEgoConsistencyService::scoreBadgeClass($s);
    $badgeLabel = fn (int $s) => \App\Services\AlterEgoConsistencyService::scoreLabel($s);

    $archetypeColors = [
        'thought_leader' => 'bg-indigo-100 text-indigo-700',
        'educator'       => 'bg-emerald-100 text-emerald-700',
        'storyteller'    => 'bg-amber-100 text-amber-700',
        'entertainer'    => 'bg-pink-100 text-pink-700',
        'provocateur'    => 'bg-red-100 text-red-700',
        'connector'      => 'bg-cyan-100 text-cyan-700',
    ];
    $archetypeColor = $archetypeColors[$alterEgo->archetype ?? ''] ?? 'bg-gray-100 text-gray-700';

    $dimensions = [
        'avg_tone'  => ['label' => 'Tono',       'color' => 'bg-indigo-400'],
        'avg_vocab' => ['label' => 'Vocabolario', 'color' => 'bg-emerald-400'],
        'avg_style' => ['label' => 'Stile frasi', 'color' => 'bg-amber-400'],
    ];
@endphp

<section class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('alter-ego.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Alter ego
            </a>
            <h1 class="mt-2 text-2xl font-semibold text-gray-950">Analytics: {{ $alterEgo->name }}</h1>
            <div class="mt-1 flex items-center gap-2">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $archetypeColor }}">
                    {{ \App\Services\AlterEgoService::archetypeLabel($alterEgo->archetype ?? '') }}
                </span>
                <span class="text-xs text-gray-400">{{ \App\Services\AlterEgoService::toneLabel($alterEgo->tone ?? '') }}</span>
            </div>
        </div>
        <a href="{{ route('alter-ego.edit', $alterEgo) }}" class="inline-flex items-center gap-1.5 rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 shadow-sm transition">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            Modifica
        </a>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm text-center">
            <p class="text-2xl font-bold text-gray-950">{{ $postCount }}</p>
            <p class="mt-0.5 text-xs text-gray-500">Post generati</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm text-center">
            <p class="text-2xl font-bold text-gray-950">{{ $scoredCount }}</p>
            <p class="mt-0.5 text-xs text-gray-500">Con score aderenza</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm text-center">
            @if($avgScore !== null)
                <p class="text-2xl font-bold text-gray-950">{{ $avgScore }}<span class="text-sm font-normal text-gray-400">/100</span></p>
                <p class="mt-0.5 text-xs text-gray-500">Score medio</p>
            @else
                <p class="text-2xl font-bold text-gray-400">—</p>
                <p class="mt-0.5 text-xs text-gray-400">Score medio</p>
            @endif
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm text-center">
            @if($avgScore !== null)
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badgeClass($avgScore) }}">{{ $badgeLabel($avgScore) }}</span>
            @else
                <p class="text-sm text-gray-400">Nessun dato</p>
            @endif
            <p class="mt-1 text-xs text-gray-500">Stato voce</p>
        </div>
    </div>

    {{-- Dimensioni medie --}}
    @if($scoredCount > 0)
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-800 mb-4">Punteggio medio per dimensione</h2>
        <div class="space-y-3">
            @foreach($dimensions as $key => $dim)
                @php $val = $stats[$key] ?? null; @endphp
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-medium text-gray-600">{{ $dim['label'] }}</span>
                        <span class="text-xs font-bold text-gray-800">{{ $val !== null ? $val . '%' : '—' }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full {{ $dim['color'] }} transition-[width] duration-500"
                             style="width: {{ $val ?? 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Timeline punteggi --}}
    @if(!empty($timeline))
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-800 mb-4">Andamento aderenza voce (ultimi {{ count($timeline) }} post)</h2>
        <div class="flex items-end gap-1 h-24">
            @foreach($timeline as $point)
                @php
                    $s   = (int) ($point['score'] ?? 0);
                    $pct = max(4, $s);
                    $col = $s >= 80 ? 'bg-emerald-400' : ($s >= 60 ? 'bg-amber-400' : 'bg-red-400');
                @endphp
                <div class="group relative flex-1 flex flex-col items-center justify-end">
                    <div class="{{ $col }} w-full rounded-t-sm transition-all duration-300"
                         style="height: {{ $pct }}%"
                         title="{{ $s }}/100 — {{ $point['created_at'] ?? '' }}"></div>
                    <span class="mt-1 text-[9px] text-gray-400 hidden group-hover:block absolute -bottom-5 whitespace-nowrap">
                        {{ $s }}
                    </span>
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex justify-between text-[10px] text-gray-400">
            <span>Più vecchio</span>
            <span>Più recente</span>
        </div>
    </div>
    @endif

    {{-- Post recenti --}}
    @if($recentItems->isNotEmpty())
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-800">Post recenti con questo alter ego</h2>
        </div>
        <ul class="divide-y divide-gray-100">
            @foreach($recentItems->take(10) as $item)
                @php
                    $meta      = is_array($item->ai_meta) ? $item->ai_meta : [];
                    $aeScore   = (int) data_get($meta, 'alter_ego_consistency.score', 0);
                    $caption   = trim((string) ($item->ai_caption ?? ''));
                @endphp
                <li class="flex items-start gap-4 px-5 py-3.5">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-800 truncate">
                            {{ $caption !== '' ? \Illuminate\Support\Str::limit($caption, 120) : '(nessun testo)' }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            {{ strtoupper((string) $item->platform) }} · {{ $item->created_at?->format('d/m/Y') }}
                        </p>
                    </div>
                    @if($aeScore > 0)
                        <span class="shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badgeClass($aeScore) }}">
                            {{ $aeScore }}
                        </span>
                    @else
                        <span class="shrink-0 text-xs text-gray-300">—</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    @else
        <div class="rounded-2xl border border-dashed border-gray-200 bg-gray-50 p-8 text-center">
            <p class="text-sm text-gray-500">Nessun post generato con questo alter ego ancora.</p>
            <a href="{{ route('posts.create') }}" class="mt-3 inline-flex items-center gap-1.5 rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition">
                Crea il primo post
            </a>
        </div>
    @endif

</section>
@endsection
