@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Wizard completato</h1>

        @if(session('status'))
            <div class="mt-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if($plan)
            <p class="text-gray-600 mt-3">
                Piano: <span class="font-medium">{{ $plan->name }}</span>
                — dal {{ \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') }}
                al {{ \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') }}
            </p>

            @php $itemsCount = $plan->items ? $plan->items->count() : 0; @endphp

            @if($itemsCount === 0)
                <p class="text-sm text-orange-700 mt-2">
                    ⚠️ Questo piano esiste ma non ha ancora post generati. Premi “Genera Piano (AI)”.
                </p>
            @endif
        @else
            <p class="text-gray-600 mt-3">Brand salvato ✅ Ora puoi generare il piano.</p>
        @endif
    </div>

    @php
        $canGenerate = (!$plan) || (($plan->items?->count() ?? 0) === 0);
    @endphp

    {{-- Bottone genera se non c'è piano o piano senza items --}}
    @if($canGenerate)
        <div class="bg-white rounded-2xl shadow p-5 border mb-6">
            <div class="text-sm text-gray-600">
                Conferma e genera il piano editoriale (crea i contenuti e li mette in coda per la generazione AI).
            </div>

            <div class="mt-3 text-xs text-gray-500">
                <div><b>Brand:</b> {{ $brand['business_name'] ?? '—' }}</div>
                <div><b>Goal:</b> {{ $step1['goal'] ?? ($brand['goal'] ?? '—') }}</div>
                <div><b>Tone:</b> {{ $step1['tone'] ?? ($brand['tone'] ?? '—') }}</div>
                <div><b>Posts/Week:</b> {{ $step1['posts_per_week'] ?? ($brand['posts_per_week'] ?? '—') }}</div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <form method="POST" action="{{ route('wizard.generate') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg bg-black text-white hover:bg-gray-900">
                        Genera Piano (AI)
                    </button>
                </form>

                <a href="{{ route('wizard.start') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">
                    Torna al Wizard
                </a>

                <a href="{{ route('posts.index') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">
                    Vai ai post
                </a>
            </div>

            <div class="mt-3 text-xs text-gray-400">
                Nota: per far partire la coda tieni acceso <span class="font-mono">php artisan queue:work</span>
            </div>
        </div>
    @endif

    {{-- Se piano esiste e ha items, mostra elenco --}}
    @if($plan && $plan->items && $plan->items->count())
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
                            @elseif($item->ai_status === 'queued' || $item->ai_status === 'pending') bg-yellow-100 text-yellow-700
                            @else bg-gray-100 text-gray-700 @endif
                        ">
                            AI: {{ $item->ai_status ?? '—' }}
                        </div>
                    </div>

                    @if($item->ai_status === 'error' && $item->ai_error)
                        <div class="mt-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl p-3">
                            <div class="font-medium">Errore AI</div>
                            <div class="mt-1">{{ $item->ai_error }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
