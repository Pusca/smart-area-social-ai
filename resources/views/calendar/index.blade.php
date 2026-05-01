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
    $pendingWeek = max(0, $totalWeek - $publishedWeek);
    $publishedRate = $totalWeek > 0 ? (int) round(($publishedWeek / $totalWeek) * 100) : 0;

    $flatItems = collect($byDay)->flatMap(fn ($d) => $d['items'])->sortBy('scheduled_at')->values();
    $nextItem = $flatItems->first(fn ($it) => $it->scheduled_at && $it->scheduled_at->gte($today));

    $aiQueued = $flatItems->whereIn('ai_status', ['queued', 'pending'])->count();
    $aiDone   = $flatItems->where('ai_status', 'done')->count();
    $aiError  = $flatItems->where('ai_status', 'error')->count();

    $connectedPlatforms = collect($connectedPlatforms ?? [])->filter()->values();
    $uiAi          = fn ($s) => \App\Support\UiStatus::ai((string) $s);
    $uiPublication = fn ($s) => \App\Support\UiStatus::publication((string) $s);

    $platformShort = [
        'instagram' => ['label' => 'IG', 'bg' => 'rgba(131,58,180,.12)', 'color' => '#7b2d8b'],
        'facebook'  => ['label' => 'FB', 'bg' => 'rgba(24,119,242,.12)',  'color' => '#1877f2'],
        'tiktok'    => ['label' => 'TT', 'bg' => 'rgba(0,0,0,.08)',        'color' => '#000'],
        'linkedin'  => ['label' => 'LI', 'bg' => 'rgba(0,119,181,.12)',   'color' => '#0077b5'],
        'youtube'   => ['label' => 'YT', 'bg' => 'rgba(255,0,0,.1)',       'color' => '#cc0000'],
        'threads'   => ['label' => 'TH', 'bg' => 'rgba(0,0,0,.08)',        'color' => '#000'],
    ];
@endphp

<style>
:root {
    --cl-navy:    #0A2D6F;
    --cl-slate:   #64748b;
    --cl-slate-lt:#94a3b8;
    --cl-border:  rgba(10,45,111,0.09);
    --cl-bg:      rgba(10,45,111,0.04);
    --cl-radius:  1.125rem;
    --cl-radius-sm: .75rem;
}

/* ── Day column ── */
.cl-day-col { display:flex;flex-direction:column;gap:.5rem;min-width:0; }

.cl-day-hd {
    border-radius:var(--cl-radius-sm);
    padding:.5rem .625rem;
    background:var(--cl-bg);
    border:1px solid rgba(10,45,111,0.08);
    text-align:center;
}
.cl-day-hd.today {
    background:var(--cl-navy);
    border-color:var(--cl-navy);
}

/* ── Content item card ── */
.cl-item {
    border-radius:var(--cl-radius-sm);
    border:1px solid var(--cl-border);
    background:#fff;
    overflow:hidden;
    transition:box-shadow 160ms, transform 160ms;
}
.cl-item:hover {
    box-shadow:0 4px 16px rgba(10,45,111,0.1);
    transform:translateY(-1px);
}

/* ── Empty day slot ── */
.cl-empty {
    border-radius:var(--cl-radius-sm);
    border:1.5px dashed rgba(10,45,111,0.11);
    padding:1.25rem .5rem;
    text-align:center;
    display:flex;flex-direction:column;align-items:center;gap:.4rem;
}

/* ── Action buttons ── */
.cl-action {
    display:inline-flex;align-items:center;justify-content:center;
    border-radius:.45rem;
    font-size:.65rem;font-weight:700;
    padding:.3rem .45rem;
    cursor:pointer;
    border:1px solid rgba(10,45,111,0.12);
    background:var(--cl-bg);
    color:var(--cl-navy);
    text-decoration:none;
    transition:background 140ms;
    white-space:nowrap;
    width:100%;
}
.cl-action:hover { background:rgba(10,45,111,0.09); }
.cl-action.approve {
    background:rgba(59,200,255,.1);
    border-color:rgba(59,200,255,.35);
    color:#0e7490;
}
.cl-action.approve:hover { background:rgba(59,200,255,.2); }

/* ── Stat chips ── */
.cl-chip {
    display:inline-flex;align-items:center;gap:.3rem;
    padding:.22rem .6rem;
    border-radius:999px;
    font-size:.7rem;font-weight:600;
    background:var(--cl-bg);
    color:var(--cl-navy);
    border:1px solid rgba(10,45,111,0.1);
    white-space:nowrap;
}

/* ── Nav buttons ── */
.cl-nav {
    display:inline-flex;align-items:center;justify-content:center;gap:.25rem;
    padding:.375rem .7rem;
    border-radius:.75rem;
    font-size:.8rem;font-weight:600;
    color:var(--cl-navy);
    border:1px solid rgba(10,45,111,0.15);
    background:#fff;
    text-decoration:none;
    transition:background 140ms,border-color 140ms;
    white-space:nowrap;
}
.cl-nav:hover { background:var(--cl-bg);border-color:rgba(10,45,111,0.25); }
.cl-nav.current { background:var(--cl-navy);color:#fff;border-color:var(--cl-navy); }
</style>

<div style="padding:1.25rem 1rem;max-width:1400px;margin:0 auto;">

    {{-- ══ HEADER ══ --}}
    <div style="background:#fff;border:1px solid var(--cl-border);border-radius:var(--cl-radius);padding:1.125rem 1.375rem;margin-bottom:1rem;">

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
                <div style="width:1px;height:1.25rem;background:rgba(10,45,111,0.1);margin:0 .2rem;"></div>
                <a href="{{ route('posts.create') }}" class="ui-btn-primary" style="font-size:.78rem;padding:.38rem .85rem;gap:.35rem;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Crea
                </a>
                <a href="{{ route('wizard.start') }}" class="cl-nav" style="font-size:.78rem;">Piano</a>
                <a href="{{ route('posts.index') }}" class="cl-nav" style="font-size:.78rem;">Libreria</a>
            </div>
        </div>

        {{-- Chips riga --}}
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
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                {{ $aiError }} errori AI
            </span>
            @endif
            @if($aiQueued > 0)
            <span class="cl-chip" style="background:rgba(217,119,6,.07);color:#b45309;border-color:rgba(217,119,6,.18);">
                {{ $aiQueued }} in generazione
            </span>
            @endif
            @if($nextItem?->scheduled_at)
            <span class="cl-chip" style="background:rgba(10,45,111,0.06);">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                Prossimo {{ $nextItem->scheduled_at->format('d/m H:i') }}
            </span>
            @endif
            @if($connectedPlatforms->isNotEmpty())
            <span class="cl-chip">Meta: {{ $connectedPlatforms->implode(', ') }}</span>
            @endif
        </div>
    </div>

    {{-- ══ PROGRESS BAR ══ --}}
    @if($totalWeek > 0)
    <div style="background:#fff;border:1px solid var(--cl-border);border-radius:var(--cl-radius);padding:.875rem 1.375rem;margin-bottom:1rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:120px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.35rem;">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--cl-slate-lt);">Avanzamento</p>
                <span style="font-size:.7rem;font-weight:700;color:var(--cl-navy);">{{ $publishedRate }}%</span>
            </div>
            <div style="height:.3rem;background:rgba(10,45,111,0.07);border-radius:999px;overflow:hidden;">
                <div style="height:100%;width:{{ $publishedRate }}%;background:linear-gradient(90deg,#0A2D6F,#3BC8FF);border-radius:999px;"></div>
            </div>
        </div>
        <div style="display:flex;gap:1.25rem;flex-shrink:0;">
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--cl-slate-lt);">Totale</p>
                <p style="font-size:.9rem;font-weight:700;color:var(--cl-navy);">{{ $totalWeek }}</p>
            </div>
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#059669;">Pubblicati</p>
                <p style="font-size:.9rem;font-weight:700;color:#047857;">{{ $publishedWeek }}</p>
            </div>
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#d97706;">Pending</p>
                <p style="font-size:.9rem;font-weight:700;color:#b45309;">{{ $pendingWeek }}</p>
            </div>
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--cl-slate-lt);">Copertura</p>
                <p style="font-size:.9rem;font-weight:700;color:var(--cl-navy);">{{ $daysWithItems }}/7</p>
            </div>
            @if($aiDone > 0 || $aiQueued > 0 || $aiError > 0)
            <div>
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:{{ $aiError > 0 ? '#dc2626' : 'var(--cl-slate-lt)' }};">AI</p>
                <p style="font-size:.9rem;font-weight:700;color:{{ $aiError > 0 ? '#dc2626' : 'var(--cl-navy)' }};">{{ $aiDone }}/{{ $totalWeek }}</p>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ══ WEEK GRID ══ --}}
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:.5rem;">
        <div style="display:grid;grid-template-columns:repeat(7,minmax(150px,1fr));gap:.625rem;min-width:980px;align-items:start;">

            @foreach($byDay as $dayKey => $day)
            @php
                $date    = $day['date'];
                $items   = $day['items'];
                $isToday = $date->toDateString() === $todayKey;
                $dayName = ucfirst($date->locale('it')->isoFormat('ddd'));
                $dayNum  = $date->format('j');
                $monthLbl= ucfirst($date->locale('it')->isoFormat('MMM'));
            @endphp

            <div class="cl-day-col">

                {{-- Day header --}}
                <div class="cl-day-hd {{ $isToday ? 'today' : '' }}">
                    <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;{{ $isToday ? 'color:rgba(255,255,255,.65)' : 'color:var(--cl-slate-lt)' }};">{{ $dayName }}</p>
                    <p style="font-size:1.25rem;font-weight:800;letter-spacing:-.03em;{{ $isToday ? 'color:#fff' : 'color:var(--cl-navy)' }};">{{ $dayNum }}</p>
                    <p style="font-size:.6rem;font-weight:600;{{ $isToday ? 'color:rgba(255,255,255,.55)' : 'color:var(--cl-slate-lt)' }};">{{ $monthLbl }}</p>
                    @if($items->count() > 0)
                    <p style="font-size:.58rem;font-weight:700;margin-top:.25rem;{{ $isToday ? 'color:rgba(255,255,255,.55)' : 'color:var(--cl-slate-lt)' }};">{{ $items->count() }} cont.</p>
                    @endif
                </div>

                {{-- Content items --}}
                @forelse($items as $it)
                @php
                    $time = $it->scheduled_at ? $it->scheduled_at->format('H:i') : '--:--';
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

                <div class="cl-item">
                    {{-- Top: ora + piattaforma --}}
                    <div style="padding:.45rem .6rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(10,45,111,0.06);">
                        <span style="font-size:.7rem;font-weight:700;color:var(--cl-navy);">{{ $time }}</span>
                        <span style="font-size:.58rem;font-weight:700;padding:.1rem .35rem;border-radius:999px;background:{{ $pf['bg'] }};color:{{ $pf['color'] }};">{{ $pf['label'] }}</span>
                    </div>

                    {{-- Anteprima media --}}
                    @if(!empty($previewImagePath))
                        <a href="{{ route('posts.edit', $it) }}" style="display:block;position:relative;aspect-ratio:1;overflow:hidden;">
                            <img src="{{ asset('storage/' . ltrim($previewImagePath, '/')) }}" alt=""
                                style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.closest('a').remove()">
                            @if($isVideo)
                            <span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.6);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>
                            @endif
                        </a>
                    @elseif($isVideo && $videoUrl)
                        <a href="{{ route('posts.edit', $it) }}" style="display:block;position:relative;aspect-ratio:1;overflow:hidden;background:#0d0d0d;">
                            <video style="width:100%;height:100%;object-fit:cover;" muted playsinline preload="metadata" data-frame-preview>
                                <source src="{{ $videoUrl }}" type="video/mp4">
                            </video>
                            <span style="position:absolute;top:.25rem;right:.25rem;background:rgba(0,0,0,.6);color:#fff;font-size:.55rem;font-weight:700;padding:.1rem .3rem;border-radius:.25rem;">VID</span>
                        </a>
                    @else
                        <a href="{{ route('posts.edit', $it) }}" style="display:flex;aspect-ratio:1;background:rgba(10,45,111,0.03);align-items:center;justify-content:center;">
                            <svg width="22" height="22" fill="none" stroke="rgba(10,45,111,0.18)" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M3 9h18M9 21V9"/></svg>
                        </a>
                    @endif

                    {{-- Body --}}
                    <div style="padding:.5rem .6rem;">
                        <p style="font-size:.75rem;font-weight:600;color:#1e3a5f;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;margin-bottom:.35rem;">{{ $it->title ?: 'Senza titolo' }}</p>

                        <div style="display:flex;align-items:center;gap:.25rem;flex-wrap:wrap;margin-bottom:.45rem;">
                            <span class="{{ $publicationInfo['badge'] }}" style="font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:999px;">{{ $publicationInfo['label'] }}</span>
                            <span class="{{ $aiInfo['badge'] }}" style="font-size:.58rem;font-weight:700;padding:.1rem .4rem;border-radius:999px;">AI {{ $aiInfo['label'] }}</span>
                        </div>

                        {{-- Azioni --}}
                        <div style="display:flex;gap:.3rem;">
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
                    <svg width="16" height="16" fill="none" stroke="rgba(10,45,111,0.18)" stroke-width="1.5" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/>
                    </svg>
                    <p style="font-size:.62rem;color:var(--cl-slate-lt);font-weight:500;">Libero</p>
                    <a href="{{ route('posts.create') }}"
                        style="font-size:.62rem;font-weight:700;color:var(--cl-navy);text-decoration:none;background:rgba(10,45,111,0.06);padding:.2rem .55rem;border-radius:999px;border:1px solid rgba(10,45,111,0.1);transition:background 140ms;"
                        onmouseover="this.style.background='rgba(10,45,111,0.12)'"
                        onmouseout="this.style.background='rgba(10,45,111,0.06)'">+ Aggiungi</a>
                </div>

                @endforelse

            </div>
            @endforeach

        </div>
    </div>

</div>
@endsection
