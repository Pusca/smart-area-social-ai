<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ $item->title }}
                </h2>
                <div class="text-sm text-gray-500 mt-1">
                    {{ strtoupper($item->platform) }} • {{ $item->format }} •
                    {{ optional($item->scheduled_at)->format('d/m/Y H:i') }}
                </div>
            </div>

            <a href="{{ route('content-items.index') }}"
               class="px-3 py-2 rounded-lg border bg-white hover:bg-gray-50 text-sm">
                ← Torna alla lista
            </a>
        </div>
    </x-slot>

    @php
        $img = $item->ai_image_path ? asset('storage/' . $item->ai_image_path) : null;
        $hashtags = $item->ai_hashtags;

        // Normalizza hashtags: può essere stringa JSON o array
        if (is_string($hashtags)) {
            $decoded = json_decode($hashtags, true);
            $hashtags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($hashtags)) $hashtags = [];
    @endphp

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="bg-white shadow-sm rounded-2xl overflow-hidden border">
                    <div class="bg-gray-100">
                        @if($img)
                            <img src="{{ $img }}" alt="AI image" class="w-full h-auto object-cover" />
                        @else
                            <div class="w-full aspect-square flex items-center justify-center text-gray-400">
                                Nessuna immagine generata
                            </div>
                        @endif
                    </div>

                    <div class="p-4 border-t">
                        <div class="text-sm text-gray-500">
                            Path: <span class="font-mono">{{ $item->ai_image_path ?? '-' }}</span>
                        </div>

                        @if($item->ai_status !== 'done')
                            <div class="mt-2 text-sm text-yellow-700 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                Stato AI: <b>{{ $item->ai_status ?? 'n/a' }}</b>
                            </div>
                        @endif

                        @if($item->ai_error)
                            <div class="mt-2 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-3">
                                Errore: <b>{{ $item->ai_error }}</b>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-white shadow-sm rounded-2xl border p-5">
                        <div class="text-sm text-gray-500 mb-2">Caption</div>
                        <div class="whitespace-pre-line text-gray-800">
                            {{ $item->ai_caption ?? '—' }}
                        </div>
                    </div>

                    <div class="bg-white shadow-sm rounded-2xl border p-5">
                        <div class="text-sm text-gray-500 mb-2">Hashtags</div>

                        @if(count($hashtags))
                            <div class="flex flex-wrap gap-2">
                                @foreach($hashtags as $h)
                                    <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700 text-sm">{{ $h }}</span>
                                @endforeach
                            </div>
                        @else
                            <div class="text-gray-400">—</div>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm rounded-2xl border p-5">
                        <div class="text-sm text-gray-500 mb-2">CTA</div>
                        <div class="text-gray-800">{{ $item->ai_cta ?? '—' }}</div>
                    </div>

                    <div class="bg-white shadow-sm rounded-2xl border p-5">
                        <div class="text-sm text-gray-500 mb-2">Image prompt</div>
                        <div class="text-gray-800">{{ $item->ai_image_prompt ?? '—' }}</div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</x-app-layout>
