@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Piano editoriale</h1>

        @if(session('status'))
            <div class="mt-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if($profile)
            <p class="text-gray-600 mt-3">
                Attivita: <span class="font-medium">{{ $profile->business_name }}</span>
                <span class="mx-2">•</span>
                <a class="underline" href="{{ route('profile.brand') }}">Modifica profilo</a>
            </p>
        @endif

        @if($plan)
            <p class="text-gray-600 mt-2">
                Piano: <span class="font-medium">{{ $plan->name }}</span>
                - dal {{ \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') }}
                al {{ \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') }}
            </p>
        @endif
    </div>

    @if($canGenerate)
        <div class="bg-white rounded-2xl shadow p-5 border mb-6">
            <div class="text-sm text-gray-600">
                Generazione piano professionale: una strategia unica + post coerenti in coda AI.
            </div>

            <div class="mt-3 text-xs text-gray-500 grid grid-cols-1 sm:grid-cols-3 gap-2">
                <div><b>Goal:</b> {{ $step1['goal'] ?? '—' }}</div>
                <div><b>Tono:</b> {{ $step1['tone'] ?? '—' }}</div>
                <div><b>Post:</b> {{ $step1['posts_per_week'] ?? '—' }}</div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('wizard.generate') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg bg-black text-white hover:bg-gray-900">
                        Genera Piano
                    </button>
                </form>

                <a href="{{ route('wizard.start') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">
                    Modifica dati piano
                </a>

                <a href="{{ route('posts.index') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">
                    Vai ai post
                </a>
            </div>

            <div class="mt-3 text-xs text-gray-400">
                Generazione in background attiva: puoi navigare liberamente nell'app durante l'elaborazione.
            </div>
        </div>
    @endif

    @if($strategy)
        <div class="bg-white rounded-2xl shadow p-5 border mb-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Strategia del piano</h2>
                <a href="{{ route('posts.index') }}" class="text-sm underline">Vai ai post</a>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <div class="text-sm font-semibold text-gray-700 mb-2">Pilastri</div>
                    <div class="space-y-2">
                        @foreach(($strategy['pillars'] ?? []) as $pillar)
                            <div class="rounded-xl border border-gray-200 p-3">
                                <div class="text-sm font-semibold">{{ $pillar['name'] ?? 'Pilastro' }}</div>
                                <div class="text-xs text-gray-600 mt-1">Obiettivo: {{ $pillar['objective'] ?? '—' }}</div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ implode(', ', array_slice($pillar['topics'] ?? [], 0, 4)) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="text-sm font-semibold text-gray-700 mb-2">Sequenze / micro-campagne</div>
                    <div class="space-y-2">
                        @foreach(array_slice($strategy['campaigns'] ?? [], 0, 3) as $campaign)
                            <div class="rounded-xl border border-gray-200 p-3">
                                <div class="text-sm font-semibold">{{ $campaign['name'] ?? 'Campagna' }}</div>
                                <div class="mt-2 text-xs text-gray-600 space-y-1">
                                    @foreach(($campaign['steps'] ?? []) as $step)
                                        <div>
                                            Step {{ $step['step'] ?? '?' }}:
                                            {{ $step['angle'] ?? ($step['hook'] ?? '—') }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($plan && ($itemStats['total'] ?? 0) > 0)
        <div id="wizard-progress-line" class="mb-4 text-sm text-gray-600">
            Contenuti: {{ $itemStats['total'] ?? 0 }}
            • completati {{ $itemStats['done'] ?? 0 }}
            • in coda {{ $itemStats['queued'] ?? 0 }}
            • errori {{ $itemStats['error'] ?? 0 }}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($plan->items as $item)
                <div class="bg-white rounded-2xl shadow p-5 border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm text-gray-500">
                                {{ ucfirst($item->platform) }} • {{ strtoupper($item->format) }}
                                • {{ optional($item->scheduled_at)->format('d/m H:i') }}
                            </div>
                            <div class="mt-1 font-semibold text-lg">{{ $item->title }}</div>
                        </div>

                        <div class="text-xs px-2 py-1 rounded-full
                            @if($item->ai_status === 'done') bg-green-100 text-green-700
                            @elseif($item->ai_status === 'error') bg-red-100 text-red-700
                            @elseif(in_array($item->ai_status, ['queued','pending'])) bg-yellow-100 text-yellow-700
                            @else bg-gray-100 text-gray-700 @endif
                        ">
                            AI: {{ $item->ai_status ?? '—' }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if($plan)
<script>
(function () {
    const line = document.getElementById('wizard-progress-line');
    if (!line) return;
    const url = '{{ route('wizard.progress.plan', $plan) }}';
    const doneLabel = 'completati';
    const queuedLabel = 'in coda';
    const errorLabel = 'errori';
    const totalLabel = 'Contenuti';

    const poll = async () => {
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            const c = data.counts || {};
            line.textContent = `${totalLabel}: ${c.total ?? 0} • ${doneLabel} ${c.done ?? 0} • ${queuedLabel} ${(c.queued ?? 0) + (c.pending ?? 0)} • ${errorLabel} ${c.error ?? 0}`;
        } catch (e) {
            // noop
        }
    };

    poll();
    setInterval(poll, 5000);
})();
</script>
@endif
@endsection


