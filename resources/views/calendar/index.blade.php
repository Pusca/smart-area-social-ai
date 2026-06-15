@extends('layouts.app')

@section('content')
@php
    $tz = config('app.timezone', 'Europe/Rome');
    $today = now($tz);
    $todayKey = $today->format('Y-m-d');

    $daysTotal     = max(1, count($byDay));
    $daysWithItems = collect($byDay)->filter(fn ($d) => $d['items']->count() > 0)->count();
    $totalWeek     = (int) ($stats['total'] ?? 0);
    $publishedWeek = (int) ($stats['published'] ?? 0);
    $pendingWeek   = max(0, $totalWeek - $publishedWeek);
    $publishedRate = $totalWeek > 0 ? (int) round(($publishedWeek / $totalWeek) * 100) : 0;

    $flatItems = collect($byDay)->flatMap(fn ($d) => $d['items'])->sortBy('scheduled_at')->values();
    $nextItem  = $flatItems->first(fn ($it) => $it->scheduled_at && $it->scheduled_at->gte($today));
    $aiQueued  = $flatItems->whereIn('ai_status', ['queued', 'pending'])->count();
    $aiDone    = $flatItems->where('ai_status', 'done')->count();
    $aiError   = $flatItems->where('ai_status', 'error')->count();

    $connectedPlatforms = collect($connectedPlatforms ?? [])->filter()->values();
    $uiAi          = fn ($s) => \App\Support\UiStatus::ai((string) $s);
    $uiPublication = fn ($s) => \App\Support\UiStatus::publication((string) $s);

    $platformMeta = [
        'instagram' => ['label' => 'IG', 'color' => '#c13584'],
        'facebook'  => ['label' => 'FB', 'color' => '#1877f2'],
        'tiktok'    => ['label' => 'TT', 'color' => '#010101'],
        'linkedin'  => ['label' => 'LI', 'color' => '#0077b5'],
        'youtube'   => ['label' => 'YT', 'color' => '#ff0000'],
        'threads'   => ['label' => 'TH', 'color' => '#101010'],
    ];

    // Calcola primo giorno selezionato (oggi se in settimana, altrimenti lunedì)
    $defaultDay = array_key_exists($todayKey, $byDay) ? $todayKey : array_key_first($byDay);
@endphp

<style>
:root {
    --n:   #0A2D6F;
    --n2:  #071D4E;
    --cy:  #3BC8FF;
    --sl:  #64748b;
    --sll: #94a3b8;
    --bdr: rgba(10,45,111,.09);
    --bg:  rgba(10,45,111,.04);
    --r:   1.25rem;
    --rs:  .875rem;
}

/* ═══ LAYOUT WRAPPER ═══ */
.cal-wrap { padding: 1.25rem 1rem; max-width: 1400px; margin: 0 auto; }

/* ═══ HEADER CARD ═══ */
.cal-header {
    background: #fff;
    border: 1px solid var(--bdr);
    border-radius: var(--r);
    padding: 1.125rem 1.375rem;
    margin-bottom: .875rem;
}
.cal-header-row {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .75rem;
}
.cal-title-label {
    font-size: .6rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .18em; color: var(--sll);
}
.cal-title-h1 {
    font-size: 1.15rem; font-weight: 800; color: var(--n);
    letter-spacing: -.025em; margin-top: .2rem;
}
.cal-header-chips {
    display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .875rem;
}
.cal-chip {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .6rem; border-radius: 999px;
    font-size: .7rem; font-weight: 600;
    background: var(--bg); color: var(--n);
    border: 1px solid rgba(10,45,111,.1);
    white-space: nowrap;
}
.cal-nav-row { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.cal-nav-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .25rem;
    padding: .375rem .7rem; border-radius: .75rem;
    font-size: .8rem; font-weight: 600; color: var(--n);
    border: 1px solid rgba(10,45,111,.15);
    background: #fff; text-decoration: none;
    transition: background 140ms, border-color 140ms;
    white-space: nowrap;
}
.cal-nav-btn:hover { background: var(--bg); border-color: rgba(10,45,111,.25); }
.cal-nav-btn.active { background: var(--n); color: #fff; border-color: var(--n); }
.cal-nav-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 2.125rem; height: 2.125rem;
    border-radius: .625rem; border: 1px solid rgba(10,45,111,.15);
    background: #fff; color: var(--n); text-decoration: none;
    transition: background 140ms;
}
.cal-nav-icon:hover { background: var(--bg); }
.cal-divider { width: 1px; height: 1.125rem; background: rgba(10,45,111,.1); margin: 0 .15rem; }

/* ═══ PROGRESS STRIP ═══ */
.cal-progress {
    background: #fff; border: 1px solid var(--bdr);
    border-radius: var(--r); padding: .75rem 1.375rem;
    margin-bottom: .875rem;
    display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
}
.cal-progress-bar-wrap { flex: 1; min-width: 120px; }
.cal-progress-bar-label {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: .3rem;
    font-size: .6rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: var(--sll);
}
.cal-progress-bar-track {
    height: .3rem; background: rgba(10,45,111,.07);
    border-radius: 999px; overflow: hidden;
}
.cal-progress-bar-fill {
    height: 100%; border-radius: 999px;
    background: linear-gradient(90deg, var(--n), var(--cy));
}
.cal-stat { text-align: center; }
.cal-stat-lbl { font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--sll); }
.cal-stat-num { font-size: .95rem; font-weight: 700; color: var(--n); margin-top: .1rem; }

/* ═══ DESKTOP GRID ═══ */
.cal-grid-outer {
    overflow-x: auto; -webkit-overflow-scrolling: touch;
    scrollbar-width: none; -ms-overflow-style: none;
}
.cal-grid-outer::-webkit-scrollbar { display: none; }

.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(148px, 1fr));
    gap: .5rem; min-width: 960px; align-items: start;
}

/* Day column */
.cal-day {
    display: flex; flex-direction: column; gap: .4rem;
}
.cal-day-hd {
    border-radius: var(--rs);
    padding: .625rem .75rem;
    background: var(--bg);
    border: 1px solid rgba(10,45,111,.08);
    text-align: center;
}
.cal-day-hd.today {
    background: var(--n); border-color: var(--n);
    box-shadow: 0 4px 16px rgba(10,45,111,.25);
}
.cal-day-name {
    font-size: .58rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: var(--sll);
}
.cal-day-hd.today .cal-day-name { color: rgba(255,255,255,.65); }
.cal-day-num {
    font-size: 1.35rem; font-weight: 800; letter-spacing: -.03em;
    line-height: 1; color: var(--n); margin-top: .1rem;
}
.cal-day-hd.today .cal-day-num { color: #fff; }
.cal-day-count {
    font-size: .58rem; font-weight: 600; color: var(--sll); margin-top: .2rem;
}
.cal-day-hd.today .cal-day-count { color: rgba(255,255,255,.5); }

/* Event card */
.cal-event {
    border-radius: .625rem;
    background: #fff;
    border: 1px solid var(--bdr);
    overflow: hidden;
    transition: box-shadow 150ms, transform 150ms;
    border-left-width: 3px;
}
.cal-event:hover {
    box-shadow: 0 6px 20px rgba(10,45,111,.12);
    transform: translateY(-2px);
}
.cal-event-thumb {
    display: block; position: relative;
    aspect-ratio: 16/10; overflow: hidden;
    background: rgba(10,45,111,.04);
}
.cal-event-thumb img, .cal-event-thumb video {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.cal-event-nothumb {
    display: flex; aspect-ratio: 16/10;
    align-items: center; justify-content: center;
    background: rgba(10,45,111,.04);
}
.cal-event-body { padding: .5rem .625rem .55rem; }
.cal-event-toprow {
    display: flex; align-items: center;
    justify-content: space-between; gap: .25rem;
    margin-bottom: .3rem;
}
.cal-event-time { font-size: .68rem; font-weight: 700; color: var(--n); }
.cal-event-plat {
    font-size: .58rem; font-weight: 700;
    padding: .1rem .35rem; border-radius: 999px;
}
.cal-event-title {
    font-size: .75rem; font-weight: 600; color: #1e3a5f;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: .3rem;
}
.cal-event-badges { display: flex; flex-wrap: wrap; gap: .2rem; margin-bottom: .4rem; }
.cal-event-badge { font-size: .58rem; font-weight: 700; padding: .1rem .4rem; border-radius: 999px; }
.cal-event-actions { display: flex; gap: .3rem; }
.cal-event-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center;
    border-radius: .4rem; font-size: .65rem; font-weight: 700;
    padding: .3rem .4rem; cursor: pointer;
    border: 1px solid rgba(10,45,111,.12);
    background: var(--bg); color: var(--n);
    text-decoration: none; transition: background 140ms; white-space: nowrap;
}
.cal-event-btn:hover { background: rgba(10,45,111,.09); }
.cal-event-btn.approve {
    background: rgba(20,152,243,.09); border-color: rgba(20,152,243,.3); color: #0e7490;
}
.cal-event-btn.approve:hover { background: rgba(20,152,243,.18); }

/* Empty slot */
.cal-empty {
    border-radius: .625rem;
    border: 1.5px dashed rgba(10,45,111,.1);
    padding: 1rem .5rem;
    display: flex; flex-direction: column; align-items: center; gap: .35rem;
    text-align: center;
}
.cal-empty-lbl { font-size: .62rem; color: var(--sll); font-weight: 500; }
.cal-empty-add {
    font-size: .62rem; font-weight: 700; color: var(--n);
    text-decoration: none;
    background: rgba(10,45,111,.06);
    padding: .2rem .55rem; border-radius: 999px;
    border: 1px solid rgba(10,45,111,.1);
    transition: background 140ms;
}
.cal-empty-add:hover { background: rgba(10,45,111,.12); }

/* ═══ MOBILE ═══ */
@media (max-width: 700px) {
    .cal-wrap { padding: .875rem .75rem; }

    /* Header compatto */
    .cal-header { padding: .875rem 1rem; }
    .cal-title-h1 { font-size: 1rem; }
    .cal-header-chips { margin-top: .625rem; gap: .3rem; }
    .cal-chip { font-size: .65rem; padding: .18rem .5rem; }

    /* Nascondi Piano + Libreria su mobile */
    .cal-hide-mobile { display: none !important; }

    /* Progress strip compatto */
    .cal-progress { padding: .625rem 1rem; gap: .75rem; }

    /* Day picker — pillole orizzontali */
    .cal-day-picker {
        display: flex; gap: .4rem;
        overflow-x: auto; -webkit-overflow-scrolling: touch;
        scrollbar-width: none; -ms-overflow-style: none;
        padding-bottom: .25rem; margin-bottom: .875rem;
    }
    .cal-day-picker::-webkit-scrollbar { display: none; }
    .cal-day-pill {
        display: flex; flex-direction: column; align-items: center;
        min-width: 3rem; padding: .5rem .4rem; border-radius: .75rem;
        border: 1.5px solid rgba(10,45,111,.1);
        background: #fff; cursor: pointer; flex-shrink: 0;
        transition: border-color 150ms, background 150ms;
    }
    .cal-day-pill.has-items { border-color: rgba(10,45,111,.2); }
    .cal-day-pill.is-today { border-color: var(--n); }
    .cal-day-pill.selected { background: var(--n); border-color: var(--n); }
    .cal-day-pill-name {
        font-size: .58rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: var(--sll);
    }
    .cal-day-pill.selected .cal-day-pill-name { color: rgba(255,255,255,.7); }
    .cal-day-pill.is-today:not(.selected) .cal-day-pill-name { color: var(--n); }
    .cal-day-pill-num {
        font-size: 1rem; font-weight: 800; color: var(--n);
        letter-spacing: -.02em; line-height: 1; margin-top: .1rem;
    }
    .cal-day-pill.selected .cal-day-pill-num { color: #fff; }
    .cal-day-pill-dot {
        width: .35rem; height: .35rem; border-radius: 50%;
        background: var(--n); margin-top: .25rem; opacity: .5;
    }
    .cal-day-pill.selected .cal-day-pill-dot { background: rgba(255,255,255,.6); }
    .cal-day-pill.has-no-items .cal-day-pill-dot { opacity: 0; }

    /* Nascondi griglia desktop, mostra sezioni mobile */
    .cal-grid-outer { display: none; }
    .cal-mobile-sections { display: block; }

    /* Sezione giorno mobile */
    .cal-mobile-day { display: none; }
    .cal-mobile-day.visible { display: block; }
    .cal-mobile-day-title {
        font-size: .95rem; font-weight: 800; color: var(--n);
        letter-spacing: -.02em; margin-bottom: .625rem;
    }
    .cal-mobile-day-sub {
        font-size: .7rem; color: var(--sll); font-weight: 500;
        margin-bottom: .875rem;
    }

    /* Item mobile: riga orizzontale */
    .cal-mobile-item {
        display: flex; align-items: stretch;
        border-radius: var(--rs); border: 1px solid var(--bdr);
        background: #fff; overflow: hidden; margin-bottom: .625rem;
        border-left-width: 3px;
    }
    .cal-mobile-thumb {
        width: 4.5rem; flex-shrink: 0; position: relative; overflow: hidden;
        background: rgba(10,45,111,.04);
    }
    .cal-mobile-thumb img, .cal-mobile-thumb video {
        width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .cal-mobile-nothumb {
        width: 4.5rem; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: rgba(10,45,111,.04);
    }
    .cal-mobile-body {
        flex: 1; padding: .625rem .75rem;
        display: flex; flex-direction: column; gap: .3rem; min-width: 0;
    }
    .cal-mobile-toprow {
        display: flex; align-items: center; gap: .4rem;
    }
    .cal-mobile-time { font-size: .75rem; font-weight: 700; color: var(--n); }
    .cal-mobile-plat {
        font-size: .6rem; font-weight: 700;
        padding: .1rem .35rem; border-radius: 999px;
    }
    .cal-mobile-title {
        font-size: .82rem; font-weight: 600; color: #1e3a5f;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .cal-mobile-badges { display: flex; flex-wrap: wrap; gap: .25rem; }
    .cal-mobile-badge { font-size: .6rem; font-weight: 700; padding: .1rem .4rem; border-radius: 999px; }
    .cal-mobile-actions { display: flex; gap: .375rem; margin-top: .1rem; }
    .cal-mobile-btn {
        flex: 1; display: inline-flex; align-items: center; justify-content: center;
        border-radius: .45rem; font-size: .7rem; font-weight: 700;
        padding: .35rem .5rem; cursor: pointer;
        border: 1px solid rgba(10,45,111,.12);
        background: var(--bg); color: var(--n);
        text-decoration: none; transition: background 140ms;
    }
    .cal-mobile-btn:hover { background: rgba(10,45,111,.1); }
    .cal-mobile-btn.approve {
        background: rgba(20,152,243,.09); border-color: rgba(20,152,243,.3); color: #0e7490;
    }

    /* Empty mobile */
    .cal-mobile-empty {
        border: 1.5px dashed rgba(10,45,111,.1);
        border-radius: var(--rs); padding: 1.5rem 1rem;
        text-align: center; display: flex; flex-direction: column;
        align-items: center; gap: .5rem;
    }
}

@media (min-width: 701px) {
    .cal-day-picker { display: none; }
    .cal-mobile-sections { display: none; }
}
</style>

<div class="cal-wrap" x-data="{ day: '{{ $defaultDay }}' }">

    {{-- ══ HEADER ══ --}}
    <div class="cal-header">
        <div class="cal-header-row">
            <div>
                <p class="cal-title-label">Calendario editoriale</p>
                <h1 class="cal-title-h1">
                    {{ $weekStart->locale('it')->isoFormat('D MMM') }} – {{ $weekEnd->locale('it')->isoFormat('D MMM YYYY') }}
                </h1>
            </div>
            <div class="cal-nav-row">
                <a href="{{ route('calendar', ['date' => $prevDate]) }}" class="cal-nav-icon" title="Settimana precedente">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <a href="{{ route('calendar') }}" class="cal-nav-btn {{ !request('date') ? 'active' : '' }}">Oggi</a>
                <a href="{{ route('calendar', ['date' => $nextDate]) }}" class="cal-nav-icon" title="Settimana successiva">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
                <div class="cal-divider"></div>
                <a href="{{ route('posts.create') }}" class="ui-btn-primary" style="font-size:.78rem;padding:.38rem .85rem;gap:.35rem;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Crea
                </a>
                <a href="{{ route('wizard.start') }}" class="cal-nav-btn cal-hide-mobile" style="font-size:.78rem;">Piano</a>
                <a href="{{ route('posts.index') }}" class="cal-nav-btn cal-hide-mobile" style="font-size:.78rem;">Libreria</a>
            </div>
        </div>

        <div class="cal-header-chips">
            <span class="cal-chip">{{ $totalWeek }} contenuti</span>
            <span class="cal-chip" style="background:rgba(5,150,105,.07);color:#047857;border-color:rgba(5,150,105,.18);">{{ $publishedWeek }} pubblicati</span>
            @if($pendingWeek > 0)
            <span class="cal-chip" style="background:rgba(217,119,6,.07);color:#b45309;border-color:rgba(217,119,6,.18);">{{ $pendingWeek }} da completare</span>
            @endif
            <span class="cal-chip">{{ $daysWithItems }}/7 giorni</span>
            @if($aiError > 0)<span class="cal-chip" style="background:rgba(220,38,38,.07);color:#dc2626;border-color:rgba(220,38,38,.18);">{{ $aiError }} errori AI</span>@endif
            @if($aiQueued > 0)<span class="cal-chip" style="background:rgba(217,119,6,.07);color:#b45309;border-color:rgba(217,119,6,.18);">{{ $aiQueued }} in gen.</span>@endif
            @if($nextItem?->scheduled_at)<span class="cal-chip">Prossimo: {{ $nextItem->scheduled_at->format('d/m H:i') }}</span>@endif
        </div>
    </div>

    {{-- ══ PROGRESS ══ --}}
    @if($totalWeek > 0)
    <div class="cal-progress">
        <div class="cal-progress-bar-wrap">
            <div class="cal-progress-bar-label">
                <span>Avanzamento settimana</span>
                <span style="color:var(--n);font-size:.7rem;">{{ $publishedRate }}%</span>
            </div>
            <div class="cal-progress-bar-track">
                <div class="cal-progress-bar-fill" style="width:{{ $publishedRate }}%;"></div>
            </div>
        </div>
        <div style="display:flex;gap:1.25rem;flex-shrink:0;flex-wrap:wrap;">
            <div class="cal-stat"><div class="cal-stat-lbl">Totale</div><div class="cal-stat-num">{{ $totalWeek }}</div></div>
            <div class="cal-stat"><div class="cal-stat-lbl" style="color:#059669;">Pubblicati</div><div class="cal-stat-num" style="color:#047857;">{{ $publishedWeek }}</div></div>
            @if($pendingWeek > 0)<div class="cal-stat"><div class="cal-stat-lbl" style="color:#d97706;">Da fare</div><div class="cal-stat-num" style="color:#b45309;">{{ $pendingWeek }}</div></div>@endif
            <div class="cal-stat"><div class="cal-stat-lbl">Giorni</div><div class="cal-stat-num">{{ $daysWithItems }}/7</div></div>
        </div>
    </div>
    @endif

    {{-- ══ MOBILE: day picker + sezioni ══ --}}

    {{-- Pillole giorno --}}
    <div class="cal-day-picker">
        @foreach($byDay as $dk => $day)
        @php
            $d = $day['date'];
            $isT = $d->toDateString() === $todayKey;
            $cnt = $day['items']->count();
        @endphp
        <button type="button"
            class="cal-day-pill {{ $cnt > 0 ? 'has-items' : 'has-no-items' }} {{ $isT ? 'is-today' : '' }}"
            :class="day === '{{ $dk }}' ? 'selected' : ''"
            @click="day = '{{ $dk }}'">
            <span class="cal-day-pill-name">{{ ucfirst($d->locale('it')->isoFormat('ddd')) }}</span>
            <span class="cal-day-pill-num">{{ $d->format('j') }}</span>
            <span class="cal-day-pill-dot"></span>
        </button>
        @endforeach
    </div>

    {{-- Sezioni giorno mobile --}}
    <div class="cal-mobile-sections">
        @foreach($byDay as $dk => $day)
        @php
            $date = $day['date'];
            $items = $day['items'];
            $isT = $date->toDateString() === $todayKey;
        @endphp
        <div class="cal-mobile-day" :class="day === '{{ $dk }}' ? 'visible' : ''">
            <div class="cal-mobile-day-title">
                {{ ucfirst($date->locale('it')->isoFormat('dddd')) }}, {{ $date->format('j') }} {{ ucfirst($date->locale('it')->isoFormat('MMMM')) }}
                @if($isT) <span style="font-size:.7rem;font-weight:600;color:var(--n);background:rgba(10,45,111,.1);padding:.15rem .5rem;border-radius:999px;margin-left:.5rem;">Oggi</span>@endif
            </div>

            @forelse($items as $it)
            @php
                $time            = $it->scheduled_at ? $it->scheduled_at->format('H:i') : '--:--';
                $mediaPreview    = is_array($it->media_preview ?? null) ? $it->media_preview : [];
                $previewImg      = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                $isVid           = (bool) ($mediaPreview['is_video'] ?? false);
                $vidPath         = trim((string) ($mediaPreview['video_path'] ?? ''));
                $vidUrl          = $vidPath !== '' ? asset('storage/' . ltrim($vidPath, '/')) : '';
                $pubInfo         = $uiPublication($it->status);
                $aiInfo          = $uiAi($it->ai_status);
                $platKey         = strtolower((string) $it->platform);
                $pm              = $platformMeta[$platKey] ?? ['label' => strtoupper(substr($platKey, 0, 2)), 'color' => '#0A2D6F'];
                $canApprove      = in_array((string) $it->status, ['draft', 'review', 'approved', 'failed', 'scheduled'], true);
                $approveLabel    = in_array((string) $it->status, ['approved', 'scheduled'], true) ? 'Sincronizza' : 'Approva';
            @endphp

            <div class="cal-mobile-item" style="border-left-color:{{ $pm['color'] }};">
                @if(!empty($previewImg))
                    <div class="cal-mobile-thumb">
                        <img src="{{ asset('storage/' . ltrim($previewImg, '/')) }}" alt="" loading="lazy" onerror="this.closest('.cal-mobile-thumb').classList.add('cal-mobile-nothumb')">
                        @if($isVid)<span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>@endif
                    </div>
                @elseif($isVid && $vidUrl)
                    <div class="cal-mobile-thumb" style="background:#0d0d0d;">
                        <video style="width:100%;height:100%;object-fit:cover;" muted playsinline preload="metadata" data-frame-preview>
                            <source src="{{ $vidUrl }}" type="video/mp4">
                        </video>
                        <span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>
                    </div>
                @else
                    <div class="cal-mobile-nothumb">
                        <svg width="18" height="18" fill="none" stroke="rgba(10,45,111,.18)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M3 9h18M9 21V9"/></svg>
                    </div>
                @endif

                <div class="cal-mobile-body">
                    <div class="cal-mobile-toprow">
                        <span class="cal-mobile-time">{{ $time }}</span>
                        <span class="cal-mobile-plat" style="background:{{ $pm['color'] }}18;color:{{ $pm['color'] }};">{{ $pm['label'] }}</span>
                    </div>
                    <p class="cal-mobile-title">{{ $it->title ?: 'Senza titolo' }}</p>
                    <div class="cal-mobile-badges">
                        <span class="{{ $pubInfo['badge'] }} cal-mobile-badge">{{ $pubInfo['label'] }}</span>
                        @if($it->ai_status !== 'done')<span class="{{ $aiInfo['badge'] }} cal-mobile-badge">AI {{ $aiInfo['label'] }}</span>@endif
                    </div>
                    <div class="cal-mobile-actions">
                        <a href="{{ route('posts.edit', $it) }}" class="cal-mobile-btn">Apri</a>
                        @if($canApprove)
                        <form method="POST" action="{{ route('calendar.content.approve', $it) }}" style="flex:1;margin:0;">
                            @csrf
                            <button type="submit" class="cal-mobile-btn approve" style="width:100%;">{{ $approveLabel }}</button>
                        </form>
                        @endif
                    </div>
                </div>
            </div>

            @empty
            <div class="cal-mobile-empty">
                <svg width="22" height="22" fill="none" stroke="rgba(10,45,111,.18)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                <p style="font-size:.8rem;color:var(--sll);font-weight:500;">Giorno libero</p>
                <a href="{{ route('posts.create') }}" class="cal-empty-add" style="font-size:.75rem;">+ Crea contenuto</a>
            </div>
            @endforelse
        </div>
        @endforeach
    </div>

    {{-- ══ DESKTOP GRID ══ --}}
    <div class="cal-grid-outer">
        <div class="cal-grid">

            @foreach($byDay as $dk => $day)
            @php
                $date  = $day['date'];
                $items = $day['items'];
                $isT   = $date->toDateString() === $todayKey;
            @endphp

            <div class="cal-day">
                <div class="cal-day-hd {{ $isT ? 'today' : '' }}">
                    <div class="cal-day-name">{{ ucfirst($date->locale('it')->isoFormat('ddd')) }}</div>
                    <div class="cal-day-num">{{ $date->format('j') }}</div>
                    <div class="cal-day-count">
                        @if($items->count() > 0) {{ $items->count() }} cont. @else — @endif
                    </div>
                </div>

                @forelse($items as $it)
                @php
                    $time         = $it->scheduled_at ? $it->scheduled_at->format('H:i') : '--:--';
                    $mediaPreview = is_array($it->media_preview ?? null) ? $it->media_preview : [];
                    $previewImg   = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                    $isVid        = (bool) ($mediaPreview['is_video'] ?? false);
                    $vidPath      = trim((string) ($mediaPreview['video_path'] ?? ''));
                    $vidUrl       = $vidPath !== '' ? asset('storage/' . ltrim($vidPath, '/')) : '';
                    $pubInfo      = $uiPublication($it->status);
                    $aiInfo       = $uiAi($it->ai_status);
                    $platKey      = strtolower((string) $it->platform);
                    $pm           = $platformMeta[$platKey] ?? ['label' => strtoupper(substr($platKey, 0, 2)), 'color' => '#0A2D6F'];
                    $canApprove   = in_array((string) $it->status, ['draft', 'review', 'approved', 'failed', 'scheduled'], true);
                    $approveLabel = in_array((string) $it->status, ['approved', 'scheduled'], true) ? 'Sincronizza' : 'Approva';
                @endphp

                <div class="cal-event" style="border-left-color:{{ $pm['color'] }};">
                    {{-- Thumbnail --}}
                    @if(!empty($previewImg))
                        <a href="{{ route('posts.edit', $it) }}" class="cal-event-thumb">
                            <img src="{{ asset('storage/' . ltrim($previewImg, '/')) }}" alt="" loading="lazy" onerror="this.closest('.cal-event-thumb').remove()">
                            @if($isVid)<span style="position:absolute;top:.3rem;right:.3rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>@endif
                        </a>
                    @elseif($isVid && $vidUrl)
                        <a href="{{ route('posts.edit', $it) }}" class="cal-event-thumb" style="background:#0d0d0d;">
                            <video style="width:100%;height:100%;object-fit:cover;" muted playsinline preload="metadata" data-frame-preview>
                                <source src="{{ $vidUrl }}" type="video/mp4">
                            </video>
                            <span style="position:absolute;top:.3rem;right:.3rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>
                        </a>
                    @else
                        <a href="{{ route('posts.edit', $it) }}" class="cal-event-nothumb">
                            <svg width="20" height="20" fill="none" stroke="rgba(10,45,111,.15)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M3 9h18M9 21V9"/></svg>
                        </a>
                    @endif

                    <div class="cal-event-body">
                        <div class="cal-event-toprow">
                            <span class="cal-event-time">{{ $time }}</span>
                            <span class="cal-event-plat" style="background:{{ $pm['color'] }}18;color:{{ $pm['color'] }};">{{ $pm['label'] }}</span>
                        </div>
                        <div class="cal-event-title">{{ $it->title ?: 'Senza titolo' }}</div>
                        <div class="cal-event-badges">
                            <span class="{{ $pubInfo['badge'] }} cal-event-badge">{{ $pubInfo['label'] }}</span>
                            @if($it->ai_status !== 'done')<span class="{{ $aiInfo['badge'] }} cal-event-badge">AI {{ $aiInfo['label'] }}</span>@endif
                        </div>
                        <div class="cal-event-actions">
                            <a href="{{ route('posts.edit', $it) }}" class="cal-event-btn">Apri</a>
                            @if($canApprove)
                            <form method="POST" action="{{ route('calendar.content.approve', $it) }}" style="flex:1;margin:0;">
                                @csrf
                                <button type="submit" class="cal-event-btn approve" style="width:100%;">{{ $approveLabel }}</button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>

                @empty
                <div class="cal-empty">
                    <div class="cal-empty-lbl">Libero</div>
                    <a href="{{ route('posts.create') }}" class="cal-empty-add">+ Aggiungi</a>
                </div>
                @endforelse

            </div>
            @endforeach

        </div>
    </div>

</div>
@endsection
