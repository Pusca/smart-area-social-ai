@extends('layouts.app')

@section('content')
@php
    $tz = config('app.timezone', 'Europe/Rome');
    $today = now($tz);
    $todayKey = $today->format('Y-m-d');

    $daysTotal = max(1, count($byDay));
    $daysWithItems = collect($byDay)->filter(fn ($d) => $d['items']->count() > 0)->count();

    $totalWeek    = (int) ($stats['total'] ?? 0);
    $publishedWeek= (int) ($stats['published'] ?? 0);
    $pendingWeek  = max(0, $totalWeek - $publishedWeek);
    $publishedRate= $totalWeek > 0 ? (int) round(($publishedWeek / $totalWeek) * 100) : 0;

    $flatItems  = collect($byDay)->flatMap(fn ($d) => $d['items'])->sortBy('scheduled_at')->values();
    $nextItem   = $flatItems->first(fn ($it) => $it->scheduled_at && $it->scheduled_at->gte($today));
    $aiQueued   = $flatItems->whereIn('ai_status', ['queued', 'pending'])->count();
    $aiDone     = $flatItems->where('ai_status', 'done')->count();
    $aiError    = $flatItems->where('ai_status', 'error')->count();

    $connectedPlatforms = collect($connectedPlatforms ?? [])->filter()->values();
    $uiAi          = fn ($s) => \App\Support\UiStatus::ai((string) $s);
    $uiPublication = fn ($s) => \App\Support\UiStatus::publication((string) $s);

    $platformShort = [
        'instagram' => ['label' => 'IG', 'bg' => 'rgba(131,58,180,.12)', 'color' => '#7b2d8b'],
        'facebook'  => ['label' => 'FB', 'bg' => 'rgba(24,119,242,.12)',  'color' => '#1877f2'],
        'tiktok'    => ['label' => 'TT', 'bg' => 'rgba(0,0,0,.08)',       'color' => '#333'],
        'linkedin'  => ['label' => 'LI', 'bg' => 'rgba(0,119,181,.12)',   'color' => '#0077b5'],
        'youtube'   => ['label' => 'YT', 'bg' => 'rgba(255,0,0,.1)',      'color' => '#cc0000'],
        'threads'   => ['label' => 'TH', 'bg' => 'rgba(0,0,0,.08)',       'color' => '#333'],
    ];
@endphp

<style>
:root {
    --cl-navy:     #0A2D6F;
    --cl-slate:    #64748b;
    --cl-slate-lt: #94a3b8;
    --cl-border:   rgba(10,45,111,0.09);
    --cl-bg:       rgba(10,45,111,0.04);
    --cl-radius:   1.125rem;
    --cl-rsm:      .75rem;
}

/* ── Grid wrapper: nascondi scrollbar ── */
.cl-grid-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.cl-grid-wrap::-webkit-scrollbar { display: none; }

.cl-week-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(152px, 1fr));
    gap: .625rem;
    min-width: 990px;
    align-items: start;
}

/* ── Day column ── */
.cl-day-col { display: flex; flex-direction: column; gap: .5rem; }

/* ── Day header ── */
.cl-day-hd {
    border-radius: var(--cl-rsm);
    background: var(--cl-bg);
    border: 1px solid rgba(10,45,111,0.08);
    padding: .5rem .625rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.cl-day-hd.today {
    background: var(--cl-navy);
    border-color: var(--cl-navy);
}
/* separatore · solo su mobile */
.cl-dh-sep { display: none; }

/* ── Item card ── */
.cl-item {
    border-radius: var(--cl-rsm);
    border: 1px solid var(--cl-border);
    background: #fff;
    overflow: hidden;
    transition: box-shadow 160ms, transform 160ms;
    display: flex;
    flex-direction: column; /* desktop: colonna */
}
.cl-item:hover {
    box-shadow: 0 4px 18px rgba(10,45,111,0.1);
    transform: translateY(-1px);
}

/* thumbnail wrapper */
.cl-item-media { flex-shrink: 0; }
.cl-item-thumb {
    display: block;
    position: relative;
    aspect-ratio: 1;
    overflow: hidden;
    background: rgba(10,45,111,0.03);
}
.cl-item-thumb img,
.cl-item-thumb video {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.cl-item-no-thumb {
    display: flex;
    aspect-ratio: 1;
    align-items: center;
    justify-content: center;
    background: rgba(10,45,111,0.03);
}

/* body */
.cl-item-body { padding: .45rem .6rem .55rem; display: flex; flex-direction: column; gap: .3rem; }
.cl-item-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .25rem;
}
.cl-item-title {
    font-size: .75rem;
    font-weight: 600;
    color: #1e3a5f;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.cl-item-badges { display: flex; flex-wrap: wrap; gap: .25rem; }
.cl-item-actions { display: flex; gap: .3rem; margin-top: .15rem; }

/* ── Action buttons ── */
.cl-action {
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: .45rem;
    font-size: .65rem; font-weight: 700;
    padding: .3rem .45rem;
    cursor: pointer;
    border: 1px solid rgba(10,45,111,0.12);
    background: var(--cl-bg);
    color: var(--cl-navy);
    text-decoration: none;
    transition: background 140ms;
    white-space: nowrap;
    width: 100%;
}
.cl-action:hover { background: rgba(10,45,111,0.09); }
.cl-action.approve {
    background: rgba(59,200,255,.1);
    border-color: rgba(59,200,255,.3);
    color: #0e7490;
}
.cl-action.approve:hover { background: rgba(59,200,255,.2); }

/* ── Empty slot ── */
.cl-empty {
    border-radius: var(--cl-rsm);
    border: 1.5px dashed rgba(10,45,111,0.11);
    padding: 1.125rem .5rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .375rem;
}

/* ── Stats chips ── */
.cl-chip {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .625rem;
    border-radius: 999px;
    font-size: .7rem; font-weight: 600;
    background: var(--cl-bg);
    color: var(--cl-navy);
    border: 1px solid rgba(10,45,111,0.1);
    white-space: nowrap;
}

/* ── Nav buttons ── */
.cl-nav {
    display: inline-flex; align-items: center; justify-content: center; gap: .25rem;
    padding: .375rem .7rem;
    border-radius: .75rem;
    font-size: .8rem; font-weight: 600;
    color: var(--cl-navy);
    border: 1px solid rgba(10,45,111,0.15);
    background: #fff;
    text-decoration: none;
    transition: background 140ms, border-color 140ms;
    white-space: nowrap;
}
.cl-nav:hover { background: var(--cl-bg); border-color: rgba(10,45,111,0.25); }
.cl-nav.current { background: var(--cl-navy); color: #fff; border-color: var(--cl-navy); }

/* ══════════════════════════════════
   MOBILE — verticale, una riga per giorno
══════════════════════════════════ */
@media (max-width: 680px) {

    /* Grid: 1 colonna, no scroll orizzontale */
    .cl-grid-wrap { overflow-x: visible; }
    .cl-week-grid {
        grid-template-columns: 1fr;
        min-width: 0;
        gap: .75rem;
    }

    /* Day header: barra orizzontale */
    .cl-day-hd {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        text-align: left;
        padding: .5rem .875rem;
    }
    .cl-dh-left { display: flex; align-items: baseline; gap: .3rem; }
    .cl-dh-sep { display: inline; }
    .cl-dh-month-label { display: none; } /* nascondo il mese, già nel titolo header */
    .cl-dh-right { display: flex; align-items: center; }

    /* Item: riga orizzontale */
    .cl-item {
        flex-direction: row;
    }
    .cl-item-media {
        width: 5rem;
    }
    .cl-item-thumb {
        aspect-ratio: unset;
        height: 100%;
        min-height: 5rem;
    }
    .cl-item-no-thumb {
        aspect-ratio: unset;
        height: 100%;
        min-height: 5rem;
    }
    .cl-item-body {
        padding: .5rem .625rem;
        justify-content: space-between;
        min-width: 0;
        flex: 1;
    }
    .cl-item-title { font-size: .8rem; }

    /* Empty: riga compatta */
    .cl-empty {
        flex-direction: row;
        justify-content: space-between;
        padding: .625rem .875rem;
        align-items: center;
    }
    .cl-empty-icon { display: none; }
}
</style>

<div style="padding:1.25rem 1rem;max-width:1400px;margin:0 auto;">

    {{-- ══ HEADER ══ --}}
    <div style="background:#fff;border:1px solid var(--cl-border);border-radius:var(--cl-radius);padding:1.125rem 1.375rem;margin-bottom:.875rem;">

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.18em;color:var(--cl-slate-lt);">Calendario editoriale</p>
                <h1 style="font-size:1.15rem;font-weight:800;color:var(--cl-navy);letter-spacing:-.025em;margin-top:.2rem;">
                    {{ $weekStart->locale('it')->isoFormat('D MMM') }} – {{ $weekEnd->locale('it')->isoFormat('D MMM YYYY') }}
                </h1>
            </div>

            <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                <a href="{{ route('calendar', ['date' => $prevDate]) }}" class="cl-nav" title="Settimana precedente">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <a href="{{ route('calendar') }}" class="cl-nav {{ !request('date') ? 'current' : '' }}">Oggi</a>
                <a href="{{ route('calendar', ['date' => $nextDate]) }}" class="cl-nav" title="Settimana successiva">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
                <div style="width:1px;height:1.125rem;background:rgba(10,45,111,0.1);margin:0 .15rem;"></div>
                <a href="{{ route('posts.create') }}" class="ui-btn-primary" style="font-size:.78rem;padding:.38rem .85rem;gap:.35rem;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Crea
                </a>
                <a href="{{ route('wizard.start') }}" class="cl-nav" style="font-size:.78rem;">Piano</a>
                <a href="{{ route('posts.index') }}" class="cl-nav" style="font-size:.78rem;">Libreria</a>
            </div>
        </div>

        {{-- Chips stats ── --}}
        <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.875rem;">
            <span class="cl-chip">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                {{ $totalWeek }} contenuti
            </span>
            <span class="cl-chip" style="background:rgba(5,150,105,.07);color:#047857;border-color:rgba(5,150,105,.18);">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                {{ $publishedWeek }} pubblicati
            </span>
            @if($pendingWeek > 0)
            <span class="cl-chip" style="background:rgba(217,119,6,.07);color:#b45309;border-color:rgba(217,119,6,.18);">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                {{ $pendingWeek }} da completare
            </span>
            @endif
            <span class="cl-chip">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                {{ $daysWithItems }}/7 giorni
            </span>
            @if($aiError > 0)
            <span class="cl-chip" style="background:rgba(220,38,38,.07);color:#dc2626;border-color:rgba(220,38,38,.18);">
                {{ $aiError }} errori AI
            </span>
            @endif
            @if($aiQueued > 0)
            <span class="cl-chip" style="background:rgba(217,119,6,.07);color:#b45309;border-color:rgba(217,119,6,.18);">
                {{ $aiQueued }} in gen.
            </span>
            @endif
            @if($nextItem?->scheduled_at)
            <span class="cl-chip">
                Prossimo: {{ $nextItem->scheduled_at->format('d/m H:i') }}
            </span>
            @endif
        </div>
    </div>

    {{-- ══ PROGRESS BAR ══ --}}
    @if($totalWeek > 0)
    <div style="background:#fff;border:1px solid var(--cl-border);border-radius:var(--cl-radius);padding:.75rem 1.375rem;margin-bottom:.875rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:100px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--cl-slate-lt);">Avanzamento settimana</p>
                <span style="font-size:.7rem;font-weight:700;color:var(--cl-navy);">{{ $publishedRate }}%</span>
            </div>
            <div style="height:.3rem;background:rgba(10,45,111,0.07);border-radius:999px;overflow:hidden;">
                <div style="height:100%;width:{{ $publishedRate }}%;background:linear-gradient(90deg,#0A2D6F,#3BC8FF);border-radius:999px;"></div>
            </div>
        </div>
        <div style="display:flex;gap:1rem;flex-shrink:0;flex-wrap:wrap;">
            <div><p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--cl-slate-lt);">Totale</p><p style="font-size:.9rem;font-weight:700;color:var(--cl-navy);">{{ $totalWeek }}</p></div>
            <div><p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#059669;">Pubblicati</p><p style="font-size:.9rem;font-weight:700;color:#047857;">{{ $publishedWeek }}</p></div>
            @if($pendingWeek > 0)<div><p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#d97706;">Da fare</p><p style="font-size:.9rem;font-weight:700;color:#b45309;">{{ $pendingWeek }}</p></div>@endif
            <div><p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--cl-slate-lt);">Giorni</p><p style="font-size:.9rem;font-weight:700;color:var(--cl-navy);">{{ $daysWithItems }}/7</p></div>
            @if($aiError > 0)<div><p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#dc2626;">Errori AI</p><p style="font-size:.9rem;font-weight:700;color:#dc2626;">{{ $aiError }}</p></div>@endif
        </div>
    </div>
    @endif

    {{-- ══ WEEK GRID ══ --}}
    <div class="cl-grid-wrap">
        <div class="cl-week-grid">

            @foreach($byDay as $dayKey => $day)
            @php
                $date    = $day['date'];
                $items   = $day['items'];
                $isToday = $date->toDateString() === $todayKey;
                $dayName = ucfirst($date->locale('it')->isoFormat('ddd'));
                $dayNum  = $date->format('j');
                $monthLbl= ucfirst($date->locale('it')->isoFormat('MMM'));
                $todayColor = $isToday ? 'color:rgba(255,255,255,.7)' : 'color:var(--cl-slate-lt)';
                $todayNum   = $isToday ? 'color:#fff' : 'color:var(--cl-navy)';
            @endphp

            <div class="cl-day-col">

                {{-- Day header — supporta layout centrato (desktop) e barra orizzontale (mobile) --}}
                <div class="cl-day-hd {{ $isToday ? 'today' : '' }}">
                    {{-- Left group: nome + num + mese --}}
                    <div class="cl-dh-left">
                        <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;{{ $todayColor }};">{{ $dayName }}</span>
                        <span class="cl-dh-sep" style="{{ $todayColor }};font-size:.6rem;">·</span>
                        <span style="font-size:1.2rem;font-weight:800;letter-spacing:-.03em;line-height:1;{{ $todayNum }};">{{ $dayNum }}</span>
                        <span class="cl-dh-month-label" style="font-size:.6rem;font-weight:600;{{ $todayColor }};">{{ $monthLbl }}</span>
                    </div>
                    {{-- Right group: conteggio --}}
                    <div class="cl-dh-right">
                        @if($items->count() > 0)
                        <span style="font-size:.6rem;font-weight:700;padding:.15rem .45rem;border-radius:999px;{{ $isToday ? 'background:rgba(255,255,255,.2);color:#fff;' : 'background:rgba(10,45,111,0.1);color:var(--cl-navy);' }}">{{ $items->count() }}</span>
                        @else
                        <span style="font-size:.6rem;{{ $todayColor }};opacity:.5;">—</span>
                        @endif
                    </div>
                    {{-- Mese visible solo desktop sotto il numero --}}
                    <span style="font-size:.6rem;font-weight:600;{{ $todayColor }};margin-top:.15rem;" class="cl-dh-month-desktop">{{ $monthLbl }}</span>
                </div>

                {{-- Items --}}
                @forelse($items as $it)
                @php
                    $time             = $it->scheduled_at ? $it->scheduled_at->format('H:i') : '--:--';
                    $mediaPreview     = is_array($it->media_preview ?? null) ? $it->media_preview : [];
                    $previewImagePath = trim((string) ($mediaPreview['preview_image_path'] ?? ''));
                    $isVideo          = (bool) ($mediaPreview['is_video'] ?? false);
                    $videoPath        = trim((string) ($mediaPreview['video_path'] ?? ''));
                    $videoUrl         = $videoPath !== '' ? asset('storage/' . ltrim($videoPath, '/')) : '';
                    $publicationInfo  = $uiPublication($it->status);
                    $aiInfo           = $uiAi($it->ai_status);
                    $platformKey      = strtolower((string) $it->platform);
                    $pf               = $platformShort[$platformKey] ?? ['label' => strtoupper(substr($platformKey, 0, 2)), 'bg' => 'rgba(10,45,111,.07)', 'color' => '#0A2D6F'];
                    $canApprove       = in_array((string) $it->status, ['draft', 'review', 'approved', 'failed', 'scheduled'], true);
                    $approveLabel     = in_array((string) $it->status, ['approved', 'scheduled'], true) ? 'Sincronizza' : 'Approva';
                @endphp

                <div class="cl-item" style="border-left:3px solid {{ $pf['color'] }};">

                    {{-- Thumbnail (sinistra su mobile, sopra su desktop) --}}
                    <div class="cl-item-media">
                        @if(!empty($previewImagePath))
                            <a href="{{ route('posts.edit', $it) }}" class="cl-item-thumb">
                                <img src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}" alt="" loading="lazy" onerror="this.closest('.cl-item-thumb').remove()">
                                @if($isVideo)<span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>@endif
                            </a>
                        @elseif($isVideo && $videoUrl)
                            <a href="{{ route('posts.edit', $it) }}" class="cl-item-thumb" style="background:#0d0d0d;">
                                <video style="width:100%;height:100%;object-fit:cover;" muted playsinline preload="metadata" data-frame-preview>
                                    <source src="{{ $videoUrl }}" type="video/mp4">
                                </video>
                                <span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.65);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>
                            </a>
                        @else
                            <a href="{{ route('posts.edit', $it) }}" class="cl-item-no-thumb">
                                <svg width="20" height="20" fill="none" stroke="rgba(10,45,111,0.15)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M3 9h18M9 21V9"/></svg>
                            </a>
                        @endif
                    </div>

                    {{-- Body --}}
                    <div class="cl-item-body">
                        <div class="cl-item-topbar">
                            <span style="font-size:.68rem;font-weight:700;color:var(--cl-navy);">{{ $time }}</span>
                            <span style="font-size:.58rem;font-weight:700;padding:.1rem .35rem;border-radius:999px;background:{{ $pf['bg'] }};color:{{ $pf['color'] }};">{{ $pf['label'] }}</span>
                        </div>

                        <p class="cl-item-title">{{ $it->title ?: 'Senza titolo' }}</p>

                        <div class="cl-item-badges">
                            <span class="{{ $publicationInfo['badge'] }}" style="font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:999px;">{{ $publicationInfo['label'] }}</span>
                            @if($it->ai_status !== 'done')
                            <span class="{{ $aiInfo['badge'] }}" style="font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:999px;">AI {{ $aiInfo['label'] }}</span>
                            @endif
                        </div>

                        <div class="cl-item-actions">
                            <a href="{{ route('posts.edit', $it) }}" class="cl-action">Apri</a>
                            @if($canApprove)
                            <form method="POST" action="{{ route('calendar.content.approve', $it) }}" style="flex:1;margin:0;">
                                @csrf
                                <button type="submit" class="cl-action approve">{{ $approveLabel }}</button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>

                @empty

                <div class="cl-empty">
                    <span class="cl-empty-icon">
                        <svg width="15" height="15" fill="none" stroke="rgba(10,45,111,0.18)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                    </span>
                    <p style="font-size:.65rem;color:var(--cl-slate-lt);font-weight:500;">Libero</p>
                    <a href="{{ route('posts.create') }}"
                        style="font-size:.65rem;font-weight:700;color:var(--cl-navy);text-decoration:none;background:rgba(10,45,111,0.07);padding:.2rem .55rem;border-radius:999px;border:1px solid rgba(10,45,111,0.12);white-space:nowrap;">
                        + Aggiungi
                    </a>
                </div>

                @endforelse

            </div>
            @endforeach

        </div>
    </div>

</div>

<style>
/* Fix day header desktop: mostra mese sotto il numero, non come quarto elemento a destra */
.cl-dh-month-desktop {
    display: block;
}
@media (max-width: 680px) {
    .cl-dh-month-desktop { display: none; }
    /* Ridefinisco struttura header per mobile */
    .cl-day-hd { flex-wrap: nowrap; }
    .cl-dh-left { gap: .3rem; }
}
</style>

@endsection
