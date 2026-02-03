<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Modifica Post
            </h2>

            <a href="{{ route('posts.index') }}"
               class="text-sm text-indigo-600 hover:text-indigo-800">
                ← Torna ai post
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-red-800">
                    <div class="font-semibold mb-1">Controlla questi campi:</div>
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="text-sm text-gray-500 mb-4">
                    <span class="font-medium text-gray-700">{{ strtoupper($contentItem->platform) }}</span>
                    • {{ strtoupper($contentItem->format) }}
                    • {{ optional($contentItem->scheduled_at)->format('d/m H:i') ?? '—' }}
                    • <span class="font-semibold text-gray-700">AI:</span> {{ $contentItem->ai_status ?? '—' }}
                </div>

                {{-- AZIONI AI --}}
                <div class="flex flex-wrap gap-2 mb-5">
                    <form method="POST" action="{{ route('ai.content.generate', $contentItem) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg bg-indigo-600 px-3 py-2 text-white text-sm font-semibold hover:bg-indigo-700">
                            Rigenera AI (testo + immagine)
                        </button>
                    </form>

                    <form method="POST" action="{{ route('ai.content.generateImage', $contentItem) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg bg-gray-900 px-3 py-2 text-white text-sm font-semibold hover:bg-black">
                            Rigenera solo immagine
                        </button>
                    </form>
                </div>

                {{-- ERRORI IMMAGINE (se presenti in ai_meta) --}}
                @php
                    $meta = is_array($contentItem->ai_meta) ? $contentItem->ai_meta : [];
                    $imageError = $meta['image_error'] ?? null;
                @endphp

                @if($imageError)
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900">
                        <div class="font-semibold">Errore immagine (best-effort)</div>
                        <div class="text-sm break-all mt-1">{{ $imageError }}</div>
                    </div>
                @endif

                <form method="POST" action="{{ route('posts.update', $contentItem) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Titolo</label>
                        <input type="text" name="title"
                               value="{{ old('title', $contentItem->title) }}"
                               class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Piattaforma</label>
                            <input type="text" name="platform"
                                   value="{{ old('platform', $contentItem->platform) }}"
                                   class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Formato</label>
                            <input type="text" name="format"
                                   value="{{ old('format', $contentItem->format) }}"
                                   class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data/Ora</label>
                        <input type="datetime-local" name="scheduled_at"
                               value="{{ old('scheduled_at', optional($contentItem->scheduled_at)->format('Y-m-d\TH:i')) }}"
                               class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Caption (AI)</label>
                        <textarea name="ai_caption" rows="6"
                                  class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('ai_caption', $contentItem->ai_caption) }}</textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Prompt immagine (AI)</label>
                        <textarea name="ai_image_prompt" rows="3"
                                  class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('ai_image_prompt', $contentItem->ai_image_prompt) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">
                            Se è vuoto, la rigenerazione immagine userà un prompt “fallback” automatico.
                        </p>
                    </div>

                    {{-- ✅ DIV IMMAGINE --}}
                    <div class="rounded-xl border bg-gray-50 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm font-semibold text-gray-800">Immagine</div>
                            <div class="text-xs text-gray-500">
                                {{ $contentItem->ai_image_path ? 'Generata' : '—' }}
                            </div>
                        </div>

                        @if($contentItem->ai_image_path)
                            <div class="overflow-hidden rounded-xl border bg-white">
                                <img
                                    src="{{ asset('storage/' . ltrim($contentItem->ai_image_path, '/')) }}"
                                    alt="AI image"
                                    class="w-full h-auto block">
                            </div>
                            <div class="mt-2 text-xs text-gray-600 break-all">
                                Path: <span class="font-mono">{{ $contentItem->ai_image_path }}</span>
                            </div>
                        @else
                            <div class="text-sm text-gray-600">
                                Nessuna immagine salvata. Premi <b>Rigenera solo immagine</b>.
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit"
                                class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-white text-sm font-semibold hover:bg-indigo-700">
                            Salva modifiche
                        </button>

                        <a href="{{ route('posts.index') }}"
                           class="text-sm text-gray-600 hover:text-gray-900">
                            Annulla
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
