@extends('layouts.app')

@section('content')
    @php
        $tz = config('app.timezone', 'Europe/Rome');
        $today = now($tz);
        $todayKey = $today->format('Y-m-d');

        $daysTotal = max(1, count($byDay));
        $daysWithItems = collect($byDay)->filter(fn ($d) => $d['items']->count() > 0)->count();
        $coverageRate = (int) round(($daysWithItems / $daysTotal) * 100);

        $totalWeek = (int) ($stats['total'] ?? 0);
        $publishedWeek = (int) ($stats['published'] ?? 0);
        $scheduledWeek = (int) ($stats['scheduled'] ?? 0);
        $pendingWeek = max(0, $totalWeek - $publishedWeek);
        $publishedRate = $totalWeek > 0 ? (int) round(($publishedWeek / $totalWeek) * 100) : 0;

        $flatItems = collect($byDay)->flatMap(fn ($d) => $d['items'])->sortBy('scheduled_at')->values();
        $todayItems = collect($byDay[$todayKey]['items'] ?? []);
        $todayPending = $todayItems->where('status', '!=', 'published')->count();

        $nextItem = $flatItems->first(function ($it) use ($today) {
            return $it->scheduled_at && $it->scheduled_at->gte($today);
        });

        $aiQueued = $flatItems->whereIn('ai_status', ['queued', 'pending'])->count();
        $aiDone = $flatItems->where('ai_status', 'done')->count();
        $aiError = $flatItems->where('ai_status', 'error')->count();
        $connectedPlatforms = collect($connectedPlatforms ?? [])->filter()->values();
        $uiAi = fn ($status) => \App\Support\UiStatus::ai((string) $status);
        $uiPublication = fn ($status) => \App\Support\UiStatus::publication((string) $status);
    @endphp

    <section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pianifica</div>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Calendario editoriale</h1>
                    <p class="mt-3 max-w-2xl text-sm text-gray-600">
                        Settimana {{ $weekStart->format('d/m') }} - {{ $weekEnd->format('d/m') }}: avanzamento pubblicazioni, copertura giorni e stato AI.
                    </p>

                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                            {{ $totalWeek }} contenuti in settimana
                        </span>
                        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                            {{ $publishedWeek }} pubblicati
                        </span>
                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                            {{ $pendingWeek }} da completare
                        </span>
                        <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700">
                            Meta {{ $connectedPlatforms->isNotEmpty() ? $connectedPlatforms->implode(' + ') : 'non collegato' }}
                        </span>
                    </div>
                </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('wizard.start') }}" class="ui-btn-primary justify-center">
                        Nuovo piano editoriale
                    </a>
                    <a href="{{ route('posts.create') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri area crea
                    </a>
                    <a href="{{ route('calendar', ['date' => $prevDate]) }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Settimana precedente
                    </a>
                    <a href="{{ route('calendar') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Torna a oggi
                        </a>
                    <a href="{{ route('calendar', ['date' => $nextDate]) }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Settimana successiva
                    </a>
                </div>
            </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Totale settimana</p>
                    <span class="text-xs font-semibold text-indigo-700">{{ $totalWeek }}</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $totalWeek }}</p>
            </article>

            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pubblicazione</p>
                    <span class="text-xs font-semibold text-emerald-700">{{ $publishedRate }}%</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $publishedWeek }} / {{ $totalWeek }}</p>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full bg-emerald-600" style="width: {{ $publishedRate }}%"></div>
                </div>
            </article>

            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Copertura giorni</p>
                    <span class="text-xs font-semibold text-cyan-700">{{ $coverageRate }}%</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $daysWithItems }} / {{ $daysTotal }}</p>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full bg-cyan-600" style="width: {{ $coverageRate }}%"></div>
                </div>
            </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">AI in settimana</p>
                <span class="text-xs font-semibold {{ $aiError > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ $aiError }} da verificare</span>
            </div>
            <p class="mt-2 text-sm text-gray-700">{{ $aiDone }} completati - {{ $aiQueued }} in lavorazione</p>
        </article>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Vista giorni</h2>
                            <p class="mt-1 text-sm text-gray-600">Dettaglio per giorno con stato contenuti e link modifica.</p>
                        </div>
                        <a href="{{ route('posts.index') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Apri libreria
                        </a>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach($byDay as $day)
                            @php
                                $date = $day['date'];
                                $isToday = $date->isSameDay(now($tz));
                                $items = $day['items'];
                            @endphp

                            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                <header class="border-b border-gray-200 px-4 py-3 {{ $isToday ? 'bg-indigo-50' : 'bg-gray-50' }}">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-semibold capitalize text-gray-900">{{ $date->locale('it')->isoFormat('dddd') }}</p>
                                            <p class="text-xs text-gray-600">{{ $date->format('d') }} {{ $date->locale('it')->isoFormat('MMMM') }}</p>
                                        </div>
                                        @if($isToday)
                                            <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                                Oggi
                                            </span>
                                        @endif
                                    </div>
                                </header>

                                <div class="min-h-[240px] space-y-2 p-3">
                                    @forelse($items as $it)
                                        @php
                                            $time = $it->scheduled_at ? $it->scheduled_at->format('H:i') : '--:--';
                                            $mediaPreview = is_array($it->media_preview ?? null) ? $it->media_preview : [];
                                            $previewImagePath = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                                            $isVideo = (bool) ($mediaPreview['is_video'] ?? false);
                                            $videoPath = trim((string) ($mediaPreview['video_path'] ?? ''));
                                            $videoUrl = $videoPath !== '' ? asset('storage/' . ltrim($videoPath, '/')) : '';
                                            $publicationInfo = $uiPublication($it->status);
                                            $aiInfo = $uiAi($it->ai_status);
                                        @endphp

                                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="text-[11px] font-semibold uppercase text-gray-500">{{ strtoupper((string) $it->platform) }} - {{ $time }}</p>
                                                    <p class="mt-1 truncate text-sm font-semibold text-gray-900">{{ $it->title ?: 'Senza titolo' }}</p>
                                                </div>
                                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $publicationInfo['badge'] }}">
                                                    {{ $publicationInfo['label'] }}
                                                </span>
                                            </div>

                                            @if(!empty($previewImagePath))
                                                <div class="relative mt-2">
                                                    <img
                                                        src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}"
                                                        alt="Anteprima contenuto"
                                                        class="h-20 w-full rounded-lg object-cover bg-gray-100"
                                                        loading="lazy"
                                                        onerror="this.remove();"
                                                    >
                                                    @if($isVideo)
                                                        <span class="absolute right-1.5 top-1.5 inline-flex items-center rounded bg-black/70 px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                            video
                                                        </span>
                                                    @endif
                                                </div>
                                            @elseif($isVideo)
                                                <div class="relative mt-2 rounded-lg border border-gray-200 bg-black">
                                                    <video
                                                        class="h-20 w-full rounded-lg object-cover"
                                                        muted
                                                        playsinline
                                                        preload="metadata"
                                                        data-frame-preview
                                                    >
                                                        <source src="{{ $videoUrl }}" type="video/mp4">
                                                    </video>
                                                    <span class="absolute right-1.5 top-1.5 inline-flex items-center rounded bg-black/70 px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                        video
                                                    </span>
                                                </div>
                                            @endif

                                            <div class="mt-2 flex items-center justify-between">
                                                <p class="text-xs text-gray-600">{{ strtoupper((string) $it->format) }}</p>
                                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $aiInfo['badge'] }}">
                                                    AI {{ $aiInfo['label'] }}
                                                </span>
                                            </div>

                                            <div class="mt-3 flex gap-2">
                                                <a href="{{ route('posts.edit', $it) }}" class="inline-flex flex-1 items-center justify-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                                                    Apri contenuto
                                                </a>

                                                @if(in_array((string) $it->status, ['draft', 'review', 'approved', 'failed', 'scheduled'], true))
                                                <form method="POST" action="{{ route('calendar.content.approve', $it) }}" class="flex-1">
                                                    @csrf
                                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-xs font-semibold text-cyan-700 hover:bg-cyan-100">
                                                        {{ in_array((string) $it->status, ['approved', 'scheduled'], true) ? 'Sincronizza publish' : 'Approva e programma' }}
                                                    </button>
                                                </form>
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-6 text-center text-xs text-gray-600">
                                            Nessun contenuto
                                        </div>
                                    @endforelse
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Situazione oggi</h2>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Contenuti previsti oggi</p>
                            <p class="mt-1 text-xl font-semibold text-gray-900">{{ $todayItems->count() }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Da completare oggi</p>
                            <p class="mt-1 text-xl font-semibold {{ $todayPending > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $todayPending }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p class="text-xs text-gray-500">Prossimo slot</p>
                            @if($nextItem && $nextItem->scheduled_at)
                                <p class="mt-1 text-sm font-semibold text-gray-900">
                                    {{ $nextItem->scheduled_at->format('d/m H:i') }} - {{ $nextItem->title ?: 'Senza titolo' }}
                                </p>
                            @else
                                <p class="mt-1 text-sm text-gray-600">Nessun contenuto pianificato.</p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Aree collegate</h2>
                    <p class="mt-1 text-sm text-gray-600">Le funzioni che usi di piu quando stai pianificando o sistemando una settimana.</p>

                    <div class="mt-4 space-y-2">
                        <a href="{{ route('posts.create') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                            <p class="text-sm font-semibold text-gray-900">Area crea</p>
                            <p class="mt-1 text-xs text-gray-600">Apri contenuti singoli, reel o nuovi piani da un solo punto.</p>
                        </a>
                        <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                            <p class="text-sm font-semibold text-gray-900">Piano editoriale</p>
                            <p class="mt-1 text-xs text-gray-600">Definisci strategia, volume e orizzonte dei contenuti.</p>
                        </a>
                        <a href="{{ route('profile.brand') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                            <p class="text-sm font-semibold text-gray-900">Brand e asset</p>
                            <p class="mt-1 text-xs text-gray-600">Aggiorna i riferimenti che aiutano caption, visual e reel.</p>
                        </a>
                        <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                            <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                            <p class="mt-1 text-xs text-gray-600">Modifica post, stati e dettagli AI.</p>
                        </a>
                        <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                            <p class="text-sm font-semibold text-gray-900">App e connessioni</p>
                            <p class="mt-1 text-xs text-gray-600">Rivedi Meta, notifiche e configurazione operativa.</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
