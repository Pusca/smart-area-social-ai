<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Piano editoriale
            </h2>
            <a href="{{ route('wizard.start') }}" class="text-sm text-indigo-600 hover:text-indigo-800">+ Nuovo piano</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('status'))
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if(!$plan)
                <div class="rounded-2xl border bg-white p-6 shadow-sm text-gray-600">
                    Nessun piano ancora creato.
                    <a class="underline" href="{{ route('wizard.start') }}">Crea il primo piano</a>
                </div>
            @else
                @php
                    $items = $plan->items;
                    $total = $items->count();
                    $done = $items->where('ai_status', \App\Enums\AiStatus::Done)->count();
                    $errors = $items->where('ai_status', \App\Enums\AiStatus::Error)->count();
                    $finished = $done + $errors;
                    $pct = $total > 0 ? (int) round($finished * 100 / $total) : 0;
                @endphp

                <div class="rounded-2xl border bg-white p-5 shadow-sm mb-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="font-semibold text-lg">{{ $plan->name }}</div>
                            <div class="text-sm text-gray-500">
                                dal {{ \Illuminate\Support\Carbon::parse($plan->start_date)->format('d/m/Y') }}
                                al {{ \Illuminate\Support\Carbon::parse($plan->end_date)->format('d/m/Y') }}
                                @if($profile) • {{ $profile->business_name }} @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <a href="{{ route('posts.index') }}" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-black">
                                Vai ai post
                            </a>
                            <form method="POST" action="{{ route('ai.plan.generate', $plan) }}"
                                  onsubmit="return confirm('Rigenerare tutti i contenuti di questo piano? I testi attuali verranno sovrascritti.');">
                                @csrf
                                <button type="submit" class="px-4 py-2 rounded-lg border bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Rigenera piano (AI)
                                </button>
                            </form>
                        </div>
                    </div>

                    @if($total > 0)
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-gray-600">Generazione: <b>{{ $finished }}/{{ $total }}</b> completati
                                    @if($errors > 0)<span class="text-red-600"> ({{ $errors }} in errore)</span>@endif
                                </span>
                                <span class="text-gray-500">{{ $pct }}%</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-2 rounded-full {{ $errors > 0 ? 'bg-amber-500' : 'bg-indigo-600' }}" style="width: {{ $pct }}%"></div>
                            </div>
                            @if($finished < $total)
                                <p class="mt-2 text-xs text-gray-400">
                                    La pagina si aggiorna da sola. In locale tieni acceso <span class="font-mono">php artisan queue:work</span>.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach($items as $item)
                        <div class="bg-white rounded-2xl shadow-sm p-5 border">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm text-gray-500">
                                        {{ ucfirst($item->platform) }} • {{ strtoupper($item->format) }}
                                        • {{ optional($item->scheduled_at)->format('d/m H:i') }}
                                    </div>
                                    <div class="mt-1 font-semibold text-lg">
                                        <a href="{{ route('posts.edit', $item) }}" class="hover:text-indigo-700">{{ $item->title }}</a>
                                    </div>
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
    </div>

    @include('partials.ai-status-poller')
</x-app-layout>
