<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Contenuti</h1>
                    <p class="mt-1 text-sm text-gray-600">Lista dei contenuti del tenant.</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('calendar') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        Calendario
                    </a>
                    <a href="{{ route('posts.create') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
                        + Nuovo
                    </a>
                </div>
            </div>

            @if(session('status'))
                <div class="mt-4 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="mt-6 space-y-3">
                @forelse($items as $it)
                    @php
                        $when = $it->scheduled_at ? \Illuminate\Support\Carbon::parse($it->scheduled_at)->format('d/m H:i') : '—';
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

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-900">{{ strtoupper($it->platform) }}</span>
                                    <span class="text-xs text-gray-500">• {{ $it->format }}</span>
                                    <span class="text-xs text-gray-500">• {{ $when }}</span>
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $badge }}">
                                        {{ $it->status }}
                                    </span>
                                </div>

                                <div class="mt-2 text-base font-semibold text-gray-900 truncate">
                                    {{ $it->title ?: 'Senza titolo' }}
                                </div>

                                @if($it->caption)
                                    <div class="mt-1 text-sm text-gray-600 line-clamp-2">
                                        {{ $it->caption }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex shrink-0 gap-2">
                                <a href="{{ route('posts.edit', $it) }}"
                                   class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Modifica
                                </a>

                                <form method="POST" action="{{ route('posts.destroy', $it) }}" onsubmit="return confirm('Eliminare questo contenuto?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center rounded-xl border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                        Elimina
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-600">
                        Nessun contenuto ancora. Clicca “Nuovo” per crearne uno.
                    </div>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $items->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
