<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Calendario editoriale</h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Settimana: <span class="font-medium">{{ $weekStart->format('d/m') }}</span> → <span class="font-medium">{{ $weekEnd->format('d/m') }}</span>
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row gap-2">
                    <a href="{{ route('calendar', ['week' => $prevWeek]) }}"
                       class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        ← Prev
                    </a>

                    <a href="{{ route('calendar') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        Oggi
                    </a>

                    <a href="{{ route('calendar', ['week' => $nextWeek]) }}"
                       class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        Next →
                    </a>

                    <a href="{{ route('posts.create') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
                        + Nuovo contenuto
                    </a>
                </div>
            </div>

            <!-- Grid giorni -->
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-7">
                @foreach($byDay as $key => $day)
                    @php
                        $date = $day['date'];
                        $isToday = $date->isSameDay(now(config('app.timezone', 'Europe/Rome')));
                        $items = $day['items'];
                    @endphp

                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 {{ $isToday ? 'bg-gray-50' : 'bg-white' }}">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">
                                        {{ ucfirst($date->locale('it')->isoFormat('ddd')) }}
                                        <span class="font-normal text-gray-500">{{ $date->format('d') }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">{{ $date->locale('it')->isoFormat('MMMM') }}</div>
                                </div>

                                @if($isToday)
                                    <span class="inline-flex items-center rounded-full bg-gray-900 px-2 py-1 text-xs font-semibold text-white">
                                        Oggi
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="p-3 space-y-2 min-h-[140px]">
                            @if($items->count() === 0)
                                <div class="rounded-xl border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500">
                                    Nessun contenuto pianificato
                                </div>
                            @else
                                @foreach($items as $it)
                                    @php
                                        $time = $it->scheduled_at ? \Illuminate\Support\Carbon::parse($it->scheduled_at)->format('H:i') : '--:--';
                                        $badge = match($it->status) {
                                            'draft' => 'bg-gray-100 text-gray-700',
                                            'review' => 'bg-yellow-100 text-yellow-800',
                                            'approved' => 'bg-blue-100 text-blue-800',
                                            'scheduled' => 'bg-purple-100 text-purple-800',
                                            'published' => 'bg-green-100 text-green-800',
                                            'failed' => 'bg-red-100 text-red-800',
                                            default => 'bg-gray-100 text-gray-700',
                                        };
                                    @endphp

                                    <a href="{{ route('posts.edit', $it) }}"
                                       class="block rounded-2xl border border-gray-100 bg-white px-3 py-3 shadow-sm hover:border-gray-200 hover:shadow transition">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs font-semibold text-gray-900">{{ strtoupper($it->platform) }}</span>
                                                    <span class="text-xs text-gray-500">• {{ $it->format }}</span>
                                                </div>
                                                <div class="mt-1 text-sm font-semibold text-gray-900 truncate">
                                                    {{ $it->title ?: 'Senza titolo' }}
                                                </div>
                                                <div class="mt-1 text-xs text-gray-500">
                                                    Ore {{ $time }}
                                                </div>
                                            </div>

                                            <span class="shrink-0 inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $badge }}">
                                                {{ $it->status }}
                                            </span>
                                        </div>
                                    </a>
                                @endforeach
                            @endif
                        </div>

                        <div class="px-3 pb-3">
                            <a href="{{ route('posts.create') }}"
                               class="block text-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                + Aggiungi
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Footer tip -->
            <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm">
                Suggerimento: domani aggiungiamo la generazione AI del piano e il “drag & drop” per spostare i contenuti tra i giorni.
            </div>
        </div>
    </div>
</x-app-layout>
