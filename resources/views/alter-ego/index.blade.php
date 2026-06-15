@extends('layouts.app')

@section('content')
@php
    $archetypeLabels = [
        'thought_leader' => 'Thought Leader',
        'educator'       => 'Educatore',
        'storyteller'    => 'Storyteller',
        'entertainer'    => 'Entertainer',
        'provocateur'    => 'Provocatore',
        'connector'      => 'Connector',
    ];
    $archetypeColors = [
        'thought_leader' => 'bg-indigo-100 text-indigo-700',
        'educator'       => 'bg-cyan-100 text-cyan-700',
        'storyteller'    => 'bg-amber-100 text-amber-700',
        'entertainer'    => 'bg-pink-100 text-pink-700',
        'provocateur'    => 'bg-rose-100 text-rose-700',
        'connector'      => 'bg-emerald-100 text-emerald-700',
    ];
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-950">Alter Ego Digitale</h1>
            <p class="mt-1 text-sm text-gray-500">La tua voce narrativa persistente applicata a ogni contenuto generato.</p>
        </div>
        <a href="{{ route('alter-ego.create') }}" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Crea alter ego
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if(session('info'))
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
            {{ session('info') }}
        </div>
    @endif

    @if($alterEgos->isEmpty())
        {{-- Empty state --}}
        <div class="flex min-h-[40vh] flex-col items-center justify-center rounded-[32px] border border-app bg-app/40 px-6 py-16 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-cyan-100 to-indigo-100">
                <svg class="h-8 w-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zm-4 7a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
            <h2 class="mt-5 text-xl font-semibold text-gray-950">Nessun alter ego ancora</h2>
            <p class="mt-2 max-w-sm text-sm text-gray-500">Crea il tuo primo alter ego digitale: definisci voce, temi e stile narrativo. L'AI lo userà come persona reale per ogni contenuto.</p>
            <a href="{{ route('alter-ego.create') }}" class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                Inizia ora
            </a>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($alterEgos as $ego)
            @php
                $archetypeKey   = (string) ($ego->archetype ?? 'thought_leader');
                $archetypeLabel = $archetypeLabels[$archetypeKey] ?? ucfirst($archetypeKey);
                $archetypeColor = $archetypeColors[$archetypeKey] ?? 'bg-gray-100 text-gray-600';
                $topicsOwned    = array_filter((array) ($ego->topics_owned ?? []));
            @endphp
            <div class="group relative overflow-hidden rounded-[28px] border border-app bg-white shadow-sm transition hover:shadow-md">
                {{-- Top bar --}}
                <div class="h-1 bg-gradient-to-r from-cyan-400 via-indigo-500 to-emerald-400"></div>

                <div class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $archetypeColor }}">
                                    {{ $archetypeLabel }}
                                </span>
                                @if($ego->is_default)
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                        Default
                                    </span>
                                @endif
                            </div>
                            <h3 class="mt-2 text-base font-semibold text-gray-950">{{ $ego->name }}</h3>
                            @if($ego->tone)
                                <p class="mt-0.5 text-xs text-gray-500">{{ \App\Services\AlterEgoService::toneLabel($ego->tone) }} · {{ \App\Services\AlterEgoService::vocabularyLabel($ego->vocabulary_level ?? 'misto') }}</p>
                            @endif
                        </div>
                    </div>

                    @if(!empty($topicsOwned))
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach(array_slice($topicsOwned, 0, 4) as $topic)
                                <span class="rounded-full border border-app bg-app/50 px-2.5 py-0.5 text-xs text-gray-600">{{ $topic }}</span>
                            @endforeach
                            @if(count($topicsOwned) > 4)
                                <span class="rounded-full border border-app bg-app/50 px-2.5 py-0.5 text-xs text-gray-400">+{{ count($topicsOwned) - 4 }}</span>
                            @endif
                        </div>
                    @endif

                    @if($ego->unique_perspective)
                        <p class="mt-3 line-clamp-2 text-xs text-gray-500">{{ $ego->unique_perspective }}</p>
                    @endif

                    {{-- Stats mini --}}
                    @php
                        $egoStats    = $stats[$ego->id] ?? ['post_count' => 0, 'avg_score' => null];
                        $egoPostCnt  = (int) $egoStats['post_count'];
                        $egoAvgScore = $egoStats['avg_score'];
                    @endphp
                    @if($egoPostCnt > 0)
                    <div class="mt-3 flex items-center gap-3 text-xs text-gray-500">
                        <span>{{ $egoPostCnt }} post</span>
                        @if($egoAvgScore !== null)
                            @php $scoreBadge = \App\Services\AlterEgoConsistencyService::scoreBadgeClass($egoAvgScore); @endphp
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $scoreBadge }}">
                                aderenza {{ $egoAvgScore }}/100
                            </span>
                        @endif
                    </div>
                    @endif

                    {{-- Azioni --}}
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-app pt-4">
                        <a href="{{ route('alter-ego.edit', $ego) }}" class="inline-flex items-center rounded-xl border border-app bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-brand hover:text-brand">
                            Modifica
                        </a>

                        @if($egoPostCnt > 0)
                        <a href="{{ route('alter-ego.analytics', $ego) }}" class="inline-flex items-center gap-1 rounded-xl border border-app bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-indigo-300 hover:text-indigo-600">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            Analytics
                        </a>
                        @endif

                        @unless($ego->is_default)
                            <button
                                type="button"
                                class="inline-flex items-center rounded-xl border border-app bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-amber-400 hover:text-amber-600"
                                onclick="setAlterEgoDefault({{ $ego->id }}, this)"
                            >
                                Imposta default
                            </button>
                        @endunless

                        <form method="POST" action="{{ route('alter-ego.destroy', $ego) }}" class="ml-auto" onsubmit="return confirm('Eliminare questo alter ego?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center rounded-xl px-3 py-1.5 text-xs font-semibold text-red-500 transition hover:bg-red-50">
                                Elimina
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif

    {{-- Libreria Template --}}
    <div>
        <div class="mb-3 flex items-baseline justify-between">
            <div>
                <h2 class="text-base font-semibold text-gray-950">Libreria template</h2>
                <p class="text-xs text-gray-500 mt-0.5">Parti da un profilo predefinito e personalizzalo in pochi minuti.</p>
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $tplColors = [
                    'thought_leader' => 'bg-indigo-100 text-indigo-700',
                    'educator'       => 'bg-emerald-100 text-emerald-700',
                    'storyteller'    => 'bg-amber-100 text-amber-700',
                    'entertainer'    => 'bg-pink-100 text-pink-700',
                    'provocateur'    => 'bg-rose-100 text-rose-700',
                    'connector'      => 'bg-cyan-100 text-cyan-700',
                ];
                $tplArchLabels = [
                    'thought_leader' => 'Thought Leader',
                    'educator'       => 'Educatore',
                    'storyteller'    => 'Storyteller',
                    'entertainer'    => 'Entertainer',
                    'provocateur'    => 'Provocatore',
                    'connector'      => 'Connector',
                ];
            @endphp
            @foreach($templates as $tpl)
            @php
                $tplColor = $tplColors[$tpl['archetype_key']] ?? 'bg-gray-100 text-gray-700';
                $tplArch  = $tplArchLabels[$tpl['archetype_key']] ?? ucfirst($tpl['archetype_key']);
            @endphp
            <div class="rounded-2xl border border-app bg-app/30 p-4 flex flex-col gap-3">
                <div>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tplColor }}">{{ $tplArch }}</span>
                        <span class="text-[10px] text-gray-400">{{ $tpl['industry'] }}</span>
                    </div>
                    <p class="text-sm font-semibold text-gray-900">{{ $tpl['label'] }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $tpl['description'] }}</p>
                    <p class="mt-2 text-xs italic text-gray-400">{{ $tpl['preview'] }}</p>
                </div>
                <form method="POST" action="{{ route('alter-ego.marketplace.import') }}" class="mt-auto">
                    @csrf
                    <input type="hidden" name="template_id" value="{{ $tpl['id'] }}">
                    <button type="submit" class="w-full rounded-xl border border-app bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-indigo-300 hover:text-indigo-600">
                        Usa template
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Spiegazione feature --}}
    <div class="rounded-[28px] border border-app bg-app/30 p-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Come funziona</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm font-semibold text-gray-900">Voce persistente</p>
                <p class="mt-1 text-xs text-gray-500">L'alter ego viene iniettato nel sistema AI ad ogni generazione: tono, frasi e temi diventano regole operative reali.</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-900">Per singolo contenuto</p>
                <p class="mt-1 text-xs text-gray-500">Scegli quale alter ego usare quando crei un post. Se ne hai uno default, viene applicato automaticamente.</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-900">Multi-alter ego</p>
                <p class="mt-1 text-xs text-gray-500">Puoi avere più alter ego — uno per ogni brand, progetto o tipo di comunicazione che gestisci.</p>
            </div>
        </div>
    </div>

</section>

<script>
async function setAlterEgoDefault(id, btn) {
    btn.disabled = true;
    btn.textContent = '...';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch(`/alter-ego/${id}/default`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        if (res.ok) {
            window.location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = 'Imposta default';
        }
    } catch {
        btn.disabled = false;
        btn.textContent = 'Imposta default';
    }
}
</script>
@endsection
