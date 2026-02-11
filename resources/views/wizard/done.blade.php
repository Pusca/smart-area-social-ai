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
        @else
            <p class="text-gray-600 mt-3">Brand salvato ✅ Ora puoi generare il piano.</p>
        @endif
    </div>

    {{-- ✅ SE NON ESISTE UN PIANO, MOSTRO IL BOTTONE "GENERA" --}}
    @if(!$plan)
        <div class="bg-white rounded-2xl shadow p-5 border mb-6">
            <div class="text-sm text-gray-600">
                Conferma e genera il piano editoriale (crea i contenuti e li mette in coda per la generazione AI).
            </div>

            {{-- piccolo riepilogo (opzionale) --}}
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
            </div>

            <div class="mt-3 text-xs text-gray-400">
                Nota: per far partire la coda tieni acceso <span class="font-mono">php artisan queue:work</span>
            </div>
        </div>
    @endif

    {{-- ✅ SE IL PIANO ESISTE, MOSTRO GLI ITEMS + IMMAGINI (come già facevi) --}}
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

                    <div class="mt-4 space-y-3">
                        <div>
                            <div class="text-xs font-semibold text-gray-500">Caption</div>
                            <div class="text-sm mt-1 whitespace-pre-line">
                                {{ $item->ai_caption ?: $item->caption }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500">Hashtags</div>
                            <div class="text-sm mt-1">
                                @php
                                    $tags = $item->ai_hashtags;

                                    // ai_hashtags può essere JSON string o array
                                    if (is_string($tags)) {
                                        $decoded = json_decode($tags, true);
                                        $tags = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', trim($tags));
                                    }
                                    if (!is_array($tags)) $tags = [];
                                @endphp

                                @if(count($tags))
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($tags as $t)
                                            @if(is_string($t) && trim($t) !== '')
                                                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 border">{{ trim($t) }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-gray-500 text-sm">—</span>
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500">CTA</div>
                            <div class="text-sm mt-1">
                                {{ $item->ai_cta ?? '—' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500">Immagine</div>

                            @if($item->ai_image_path)
                                <img class="mt-2 w-full rounded-xl border" src="{{ asset('storage/'.$item->ai_image_path) }}" alt="AI image">
                                <div class="text-xs text-gray-500 mt-2 break-words">
                                    <span class="font-semibold">Prompt:</span> {{ $item->ai_image_prompt ?? '—' }}
                                </div>
                            @else
                                <div class="text-sm text-gray-500 mt-1">In attesa di generazione immagine…</div>
                                <div class="text-xs text-gray-400 mt-1 break-words">
                                    Prompt: {{ $item->ai_image_prompt ?? '—' }}
                                </div>
                            @endif
                        </div>

                        <div class="pt-2 text-xs text-gray-400">
                            Generato: {{ $item->ai_generated_at ? \Illuminate\Support\Carbon::parse($item->ai_generated_at)->format('d/m H:i') : '—' }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
