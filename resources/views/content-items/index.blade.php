
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Galleria contenuti
            </h2>

            <div class="text-sm text-gray-500">
                {{ $items->total() }} elementi
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">

                    @foreach ($items as $item)
                        @php
                            $img = $item->ai_image_path ? asset('storage/' . $item->ai_image_path) : null;
                        @endphp

                        <a href="{{ route('content-items.show', $item) }}"
                           class="group border rounded-xl overflow-hidden hover:shadow-md transition bg-white">

                            <div class="aspect-square bg-gray-100 overflow-hidden">
                                @if($img)
                                    <img
                                        src="{{ $img }}"
                                        alt="AI image"
                                        class="w-full h-full object-cover group-hover:scale-[1.02] transition"
                                        loading="lazy"
                                    />
                                @else
                                    <div class="w-full h-full flex items-center justify-center text-gray-400 text-sm">
                                        Nessuna immagine
                                    </div>
                                @endif
                            </div>

                            <div class="p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600">
                                        {{ strtoupper($item->platform) }} • {{ $item->format }}
                                    </div>

                                    <div class="text-xs px-2 py-1 rounded-full
                                        {{ $item->ai_status === 'done' ? 'bg-green-100 text-green-700' : ($item->ai_status === 'error' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                                        AI: {{ $item->ai_status ?? 'n/a' }}
                                    </div>
                                </div>

                                <div class="mt-2 font-semibold text-gray-800 line-clamp-2">
                                    {{ $item->title }}
                                </div>

                                <div class="mt-2 flex flex-wrap gap-1 text-[11px]">
                                    @if($item->rubric)
                                        <span class="px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">
                                            {{ $item->rubric }}
                                        </span>
                                    @endif
                                    @if($item->series_key)
                                        <span class="px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">
                                            Serie {{ $item->episode_number ? 'Ep. '.$item->episode_number : '' }}
                                        </span>
                                    @endif
                                </div>

                                <div class="mt-1 text-sm text-gray-500">
                                    {{ optional($item->scheduled_at)->format('d/m/Y H:i') }}
                                </div>

                                @if($item->ai_error)
                                    <div class="mt-2 text-xs text-red-600 line-clamp-2">
                                        {{ $item->ai_error }}
                                    </div>
                                @endif
                            </div>
                        </a>
                    @endforeach

                </div>

                <div class="mt-6">
                    {{ $items->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
