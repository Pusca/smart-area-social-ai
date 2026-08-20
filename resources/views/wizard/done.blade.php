
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
                Attività: <span class="font-medium">{{ $profile->business_name }}</span>
                <span class="mx-2">•</span>
                <a class="underline" href="{{ route('profile.brand') }}">Modifica profilo</a>
            </p>
        @else
            <p class="text-gray-600 mt-3">
                Non hai ancora completato il profilo attività.
                <a class="underline" href="{{ route('profile.brand') }}">Vai al profilo</a>
            </p>
        @endif

        @if($plan)
            <p class="text-gray-600 mt-2">
                Piano: <span class="font-medium">{{ $plan->name }}</span>
                — dal {{ \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') }}
                al {{ \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') }}
            </p>
        @else
            <p class="text-gray-600 mt-2">Nessun piano ancora generato.</p>
        @endif
    </div>

    @php
        $itemsCount = $plan?->items?->count() ?? 0;
        $canGenerate = $profile && (! $plan || $itemsCount === 0);
    @endphp

    @if($canGenerate)
        <div class="bg-white rounded-2xl shadow p-5 border mb-6">
            <div class="text-sm text-gray-600">
                Conferma e genera i post del piano (li mette in coda per la generazione AI).
            </div>

            <div class="mt-3 text-xs text-gray-500">
                <div><b>Goal:</b> {{ $step1['goal'] ?? '—' }}</div>
                <div><b>Tone:</b> {{ $step1['tone'] ?? '—' }}</div>
                <div><b>Posts:</b> {{ $step1['posts_per_week'] ?? '—' }}</div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <form method="POST" action="{{ route('wizard.generate') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg bg-black text-white hover:bg-gray-900">
                        Genera Piano (AI)
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
                Nota: per far partire la coda tieni acceso <span class="font-mono">php artisan queue:work</span>
            </div>
        </div>
    @endif

    @if($plan && $itemsCount > 0)
        <div class="mb-4 flex gap-2">
            <a href="{{ route('posts.index') }}" class="px-4 py-2 rounded-lg bg-black text-white hover:bg-gray-900">
                Vai alla lista post
            </a>
            <a href="{{ route('wizard.start') }}" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">
                + Nuovo piano
            </a>
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

                        <x-ai-status-badge :status="$item->ai_status" :item-id="$item->id" />
                    </div>

                    @if($item->ai_status === \App\Enums\AiStatus::Error && $item->ai_error)
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

@include('partials.ai-status-poller')
@endsection



