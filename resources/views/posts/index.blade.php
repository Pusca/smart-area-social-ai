@extends('layouts.app')

@section('content')
@php
    // Robust: accetta vari nomi dal controller senza esplodere
    $items = $items ?? ($contentItems ?? ($posts ?? collect()));
    if (!($items instanceof \Illuminate\Support\Collection) && !($items instanceof \Illuminate\Contracts\Pagination\Paginator)) {
        $items = collect($items);
    }
@endphp

<div class="max-w-6xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">Posts</h1>
            <p class="text-gray-600 mt-1">Gestisci i contenuti. Puoi anche eliminare quelli di test per non fare confusione.</p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('posts.create') }}"
               class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                + Nuovo post
            </a>
            <a href="{{ route('wizard.start') }}"
               class="inline-flex items-center px-4 py-2 rounded-xl bg-white border text-sm font-semibold hover:bg-gray-50">
                Apri Wizard
            </a>
        </div>
    </div>

    @if(session('status'))
        <div class="mb-4 p-3 rounded-xl bg-green-50 border border-green-200 text-green-800 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-800 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if($items instanceof \Illuminate\Contracts\Pagination\Paginator || $items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
        <div class="mb-4">
            {{ $items->links() }}
        </div>
    @endif

    @if($items && count($items))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($items as $item)
                <div class="bg-white rounded-2xl shadow p-5 border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-xs text-gray-500">
                                {{ ucfirst($item->platform ?? '-') }} • {{ strtoupper($item->format ?? '-') }}
                                @if(!empty($item->scheduled_at))
                                    • {{ \Illuminate\Support\Carbon::parse($item->scheduled_at)->format('d/m H:i') }}
                                @endif
                            </div>
                            <div class="mt-1 font-semibold text-lg">
                                {{ $item->title ?? ('Post #' . ($item->id ?? '')) }}
                            </div>
                        </div>

                        <div class="text-xs px-2 py-1 rounded-full
                            @if(($item->ai_status ?? null) === 'done') bg-green-100 text-green-700
                            @elseif(($item->ai_status ?? null) === 'error') bg-red-100 text-red-700
                            @elseif(in_array(($item->ai_status ?? null), ['queued','pending'])) bg-yellow-100 text-yellow-700
                            @else bg-gray-100 text-gray-700 @endif
                        ">
                            AI: {{ $item->ai_status ?? '—' }}
                        </div>
                    </div>

                    @if(($item->ai_status ?? null) === 'error' && !empty($item->ai_error))
                        <div class="mt-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl p-3">
                            <div class="font-medium">Errore AI</div>
                            <div class="mt-1 break-words">{{ $item->ai_error }}</div>
                        </div>
                    @endif

                    <div class="mt-4 space-y-3">
                        <div>
                            <div class="text-xs font-semibold text-gray-500">Caption</div>
                            <div class="text-sm mt-1 whitespace-pre-line">
                                {{ $item->ai_caption ?? ($item->caption ?? '—') }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500">Immagine</div>
                            @if(!empty($item->ai_image_path))
                                <img class="mt-2 w-full rounded-xl border" src="{{ asset('storage/'.$item->ai_image_path) }}" alt="AI image">
                            @else
                                <div class="text-sm text-gray-500 mt-1">—</div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between gap-2">
                        <div class="flex gap-2">
                            <a href="{{ route('posts.edit', $item) }}"
                               class="inline-flex items-center px-3 py-2 rounded-xl bg-white border text-sm font-semibold hover:bg-gray-50">
                                Modifica
                            </a>

                            {{-- (Opzionale) rigenera AI per singolo item se vuoi usarlo subito --}}
                            @if(\Illuminate\Support\Facades\Route::has('ai.content.generate'))
                                <form action="{{ route('ai.content.generate', $item) }}" method="POST">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 border border-indigo-100 text-sm font-semibold hover:bg-indigo-100">
                                        Rigenera AI
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- ELIMINA --}}
                        <form action="{{ route('posts.destroy', $item) }}" method="POST"
                              onsubmit="return confirm('Confermi eliminazione definitiva di questo post?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-2 rounded-xl bg-red-50 text-red-700 border border-red-100 text-sm font-semibold hover:bg-red-100">
                                Elimina
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

        @if($items instanceof \Illuminate\Contracts\Pagination\Paginator || $items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            <div class="mt-6">
                {{ $items->links() }}
            </div>
        @endif
    @else
        <div class="bg-white rounded-2xl shadow p-6 border text-gray-600">
            Nessun post trovato.
        </div>
    @endif
</div>
@endsection
