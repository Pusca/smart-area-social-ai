@extends('layouts.app')

@section('content')
@php
    $socialAccounts = $socialAccounts ?? collect();
    $metaReady = (bool) ($metaReady ?? false);
    $metaScopes = is_array($metaScopes ?? null) ? $metaScopes : [];
    $activeSocialAccounts = $socialAccounts->where('status', 'active');
    $connectedPlatforms = $activeSocialAccounts->pluck('platform')->filter()->unique()->values();
    $canvaSummary = is_array($canvaSummary ?? null) ? $canvaSummary : [];
    $canvaFeatureEnabled = (bool) ($canvaSummary['enabled'] ?? false);
    $canvaConfigured = (bool) ($canvaSummary['configured'] ?? false);
    $canvaConnected = (bool) ($canvaSummary['connected'] ?? false);
    $canvaTemplatesAvailable = (bool) ($canvaSummary['templates_available'] ?? false);
    $canvaAutofillAvailable = (bool) ($canvaSummary['autofill_available'] ?? false);
    $canvaExportAvailable = (bool) ($canvaSummary['export_available'] ?? false);
    $canvaConnection = $canvaSummary['connection'] ?? null;
    $canvaCatalogPreview = collect((array) ($canvaSummary['catalog_preview'] ?? []))->filter(fn ($row) => is_array($row))->values();
    $canvaWorkflowOptions = is_array($canvaWorkflowOptions ?? null) ? $canvaWorkflowOptions : [];
    $canvaTemplateMappings = $canvaTemplateMappings ?? collect();
@endphp

<style>
:root {
    --st-navy: #0A2D6F;
    --st-cyan: #3BC8FF;
    --st-blue: #1498F3;
    --st-slate: #64748b;
    --st-border: rgba(10,45,111,.1);
    --st-bg: #F7F9FD;
}
.st-card {
    background:#fff;
    border:1px solid var(--st-border);
    border-radius:1.25rem;
    padding:1.5rem;
}
.st-section-title {
    font-size:.6rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.1em;
    color:var(--st-slate);
    margin-bottom:.75rem;
}
.st-h2 {
    font-size:.9375rem;
    font-weight:700;
    color:var(--st-navy);
}
.st-chip {
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    border-radius:999px;
    padding:.3rem .75rem;
    font-size:.6rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
}
.st-chip-ok   { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.st-chip-warn { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.st-chip-info { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.st-chip-cyan { background:#e0f9ff; color:#0e7490; border:1px solid #a5f3fc; }
.st-chip-navy { background:rgba(10,45,111,.06); color:var(--st-navy); border:1px solid var(--st-border); }
.st-account-row {
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:1rem 1.125rem;
    background:#fafbff;
}
.st-progress-bar {
    height:5px;
    border-radius:999px;
    background:rgba(10,45,111,.08);
    overflow:hidden;
    margin:.625rem 0 1.25rem;
}
.st-progress-fill {
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#3BC8FF,#1498F3,#0A2D6F);
}
.st-step-link {
    display:block;
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:.875rem 1rem;
    background:#fafbff;
    transition:background 150ms,border-color 150ms;
    text-decoration:none;
}
.st-step-link:hover {
    background:#fff;
    border-color:var(--st-blue);
}
.st-canva-chip {
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:.875rem 1rem;
    background:#fafbff;
    text-align:center;
}
.st-template-card {
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:.875rem 1rem;
    background:#fafbff;
}
.st-workflow-card {
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:1rem;
    background:#fafbff;
}
.st-env-chip {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
    border:1px solid var(--st-border);
    border-radius:.875rem;
    padding:.75rem 1rem;
    background:#fafbff;
}
@media(max-width:680px) {
    .st-two-col  { display:block !important; }
    .st-two-col > * + * { margin-top:1rem; }
    .st-canva-grid { grid-template-columns:1fr 1fr !important; }
    .st-header-ctas { flex-wrap:wrap; }
}
</style>

<meta name="csrf-token" content="{{ csrf_token() }}">

<div style="max-width:860px;margin:0 auto;padding:1.5rem 1rem 3rem;">

    {{-- Header --}}
    <div style="margin-bottom:1.5rem;">
        <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;">Setup</p>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:.375rem;">
            <h1 style="font-size:1.375rem;font-weight:700;color:#07183F;line-height:1.2;">Workspace</h1>
            <div class="st-header-ctas" style="display:flex;align-items:center;gap:.625rem;">
                <a href="{{ route('profile.brand') }}"
                   style="display:inline-flex;align-items:center;gap:.4rem;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#fff;background:#0A2D6F;border:none;text-decoration:none;white-space:nowrap;">
                    <svg style="width:.9rem;height:.9rem;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    Brand Center
                </a>
                @if($metaReady)
                <a href="{{ route('settings.social.meta.redirect') }}"
                   style="display:inline-flex;align-items:center;gap:.4rem;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#0e7490;background:#e0f9ff;border:1px solid #a5f3fc;text-decoration:none;white-space:nowrap;">
                    Collega Meta
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Stat chips row --}}
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;">
        <span class="st-chip {{ $setupRate >= 80 ? 'st-chip-ok' : ($setupRate >= 40 ? 'st-chip-warn' : 'st-chip-navy') }}">
            Setup {{ $setupDone }}/{{ $setupChecks->count() }} · {{ $setupRate }}%
        </span>
        <span class="st-chip {{ $activeSocialAccounts->isNotEmpty() ? 'st-chip-ok' : 'st-chip-warn' }}">
            {{ $activeSocialAccounts->count() }} account attivi
        </span>
        <span class="st-chip {{ $connectedPlatforms->isNotEmpty() ? 'st-chip-info' : 'st-chip-navy' }}">
            {{ $connectedPlatforms->count() }}/4 piattaforme
        </span>
        @if($canvaFeatureEnabled)
        <span class="st-chip {{ $canvaConnected ? 'st-chip-cyan' : 'st-chip-navy' }}">
            Canva {{ $canvaConnected ? 'connessa' : 'non connessa' }}
        </span>
        @endif
        @if($metaReady)
        <span class="st-chip st-chip-ok">Meta OAuth OK</span>
        @else
        <span class="st-chip st-chip-warn">Meta OAuth da configurare</span>
        @endif
    </div>

    @if(session('status'))
        <div style="border:1px solid #a7f3d0;background:#ecfdf5;border-radius:.875rem;padding:.875rem 1rem;font-size:.8125rem;color:#065f46;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Setup progress --}}
    <div class="st-card" style="margin-bottom:1.25rem;">
        <p class="st-section-title">Percorso setup</p>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <p class="st-h2">{{ $setupRate >= 100 ? 'Setup completo' : 'Completa il workspace' }}</p>
            <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">{{ $setupDone }}/{{ $setupChecks->count() }} aree</span>
        </div>

        <div class="st-progress-bar">
            <div class="st-progress-fill" style="width:{{ $setupRate > 0 ? max(8,$setupRate) : 0 }}%;"></div>
        </div>

        {{-- Quick links 2-col --}}
        <div class="st-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1.25rem;">
            <a href="{{ route('profile.brand') }}" class="st-step-link">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Brand Center</p>
                <p style="margin-top:.375rem;font-size:.875rem;font-weight:700;color:#07183F;">{{ $profile?->business_name ?: 'Profilo da completare' }}</p>
                <p style="margin-top:.25rem;font-size:.7rem;color:#64748b;">{{ $logosCount }} logo · {{ $imagesCount }} img · {{ $knowledgeAssetsCount ?? 0 }} asset · {{ $assetVariablesCount }} var</p>
            </a>
            <a href="{{ route('settings') }}#meta-connections" class="st-step-link">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Connessioni tecniche</p>
                <p style="margin-top:.375rem;font-size:.875rem;font-weight:700;color:#07183F;">{{ $activeSocialAccounts->count() }} account attivi</p>
                <p style="margin-top:.25rem;font-size:.7rem;color:#64748b;">Meta, piattaforme social, ambiente operativo</p>
            </a>
        </div>

        {{-- Prossimi passi --}}
        @if($setupMissing->isNotEmpty())
        <div>
            <p class="st-section-title">Prossimi passi</p>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
                @foreach($setupMissing->take(4) as $item)
                <a href="{{ $item['href'] }}" class="st-step-link" style="display:flex;align-items:center;gap:.75rem;">
                    <div style="width:1.5rem;height:1.5rem;border-radius:999px;border:2px solid rgba(10,45,111,.15);flex-shrink:0;"></div>
                    <div>
                        <p style="font-size:.8125rem;font-weight:700;color:#07183F;">{{ $item['label'] }}</p>
                        <p style="font-size:.7rem;color:#64748b;margin-top:.125rem;">{{ $item['hint'] }}</p>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        @else
        <div style="border:1px solid #a7f3d0;background:#ecfdf5;border-radius:.875rem;padding:1rem;display:flex;align-items:center;gap:.75rem;">
            <svg style="width:1.25rem;height:1.25rem;color:#065f46;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <p style="font-size:.8125rem;font-weight:700;color:#065f46;">Setup completo</p>
                <p style="font-size:.7rem;color:#047857;margin-top:.125rem;">Puoi passare direttamente a crea, pianifica o libreria.</p>
            </div>
        </div>
        @endif
    </div>

    {{-- Meta connections --}}
    <div class="st-card" id="meta-connections" style="margin-bottom:1.25rem;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
            <div>
                <p class="st-section-title">Connessioni Meta</p>
                <p class="st-h2">Account social</p>
                <p style="font-size:.75rem;color:#64748b;margin-top:.25rem;">Facebook Page e Instagram Business per pubblicazione automatica.</p>
            </div>
            @if($metaReady)
            <a href="{{ route('settings.social.meta.redirect') }}"
               style="display:inline-flex;align-items:center;gap:.4rem;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#fff;background:#0A2D6F;border:none;text-decoration:none;white-space:nowrap;flex-shrink:0;">
                + Collega account
            </a>
            @endif
        </div>

        @if(!$metaReady)
            <div style="border:1px solid #fde68a;background:#fffbeb;border-radius:.875rem;padding:.875rem 1rem;font-size:.8rem;color:#92400e;margin-bottom:1rem;">
                Configura <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">META_APP_ID</code>, <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">META_APP_SECRET</code> e <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">META_REDIRECT_URI</code> per attivare OAuth Meta.
            </div>
        @endif

        @if($metaReady && !empty($metaScopes))
            <div style="border:1px solid var(--st-border);background:#fafbff;border-radius:.875rem;padding:.875rem 1rem;margin-bottom:1rem;">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.375rem;">Scope richiesti</p>
                <p style="font-size:.75rem;color:#07183F;">{{ implode(', ', $metaScopes) }}</p>
            </div>
        @endif

        <div style="display:flex;flex-direction:column;gap:.625rem;">
            @forelse($socialAccounts as $account)
                <div class="st-account-row" style="border-left:3px solid {{ $account->status === 'active' ? '#3BC8FF' : '#e2e8f0' }};">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;">
                        <div>
                            <p style="font-size:.875rem;font-weight:700;color:#07183F;">
                                {{ $account->account_name ?: ($account->username ?: 'Account social') }}
                            </p>
                            <p style="font-size:.7rem;color:#64748b;margin-top:.2rem;">
                                {{ strtoupper((string) $account->platform) }}
                                @if($account->username) · {{ $account->username }}@endif
                                @if($account->account_id) · ID {{ $account->account_id }}@endif
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                            <span class="st-chip {{ $account->status === 'active' ? 'st-chip-ok' : 'st-chip-navy' }}">
                                {{ $account->status === 'active' ? 'Attivo' : 'Disconnesso' }}
                            </span>
                            @if($account->is_primary)
                                <span class="st-chip st-chip-cyan">Primario</span>
                            @endif
                            <form method="POST" action="{{ route('settings.social.accounts.disconnect', $account) }}">
                                @csrf
                                <button type="submit"
                                    style="border:1px solid #fecaca;background:#fff5f5;border-radius:.625rem;padding:.3rem .75rem;font-size:.7rem;font-weight:700;color:#dc2626;cursor:pointer;">
                                    Disconnetti
                                </button>
                            </form>
                        </div>
                    </div>
                    <p style="font-size:.7rem;color:#94a3b8;margin-top:.5rem;">
                        Sync: {{ optional($account->last_synced_at)->format('d/m/Y H:i') ?: 'mai' }}
                        @if($account->last_error) · <span style="color:#ef4444;">{{ $account->last_error }}</span>@endif
                    </p>
                </div>
            @empty
                <div style="border:1px dashed rgba(10,45,111,.15);border-radius:.875rem;padding:2rem;text-align:center;">
                    <p style="font-size:.8125rem;color:#64748b;">Nessun account Meta collegato.</p>
                    @if($metaReady)
                        <a href="{{ route('settings.social.meta.redirect') }}"
                           style="display:inline-flex;margin-top:.75rem;align-items:center;gap:.4rem;border-radius:.875rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:700;color:#fff;background:#0A2D6F;text-decoration:none;">
                            Collega il primo account
                        </a>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Canva integration --}}
    @if($canvaFeatureEnabled)
    <div class="st-card" style="margin-bottom:1.25rem;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
            <div>
                <p class="st-section-title">Integrazioni</p>
                <p class="st-h2">Canva</p>
                <p style="font-size:.75rem;color:#64748b;margin-top:.25rem;">Social AI genera, Canva rifinisce. Layout, template ed export finale.</p>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                @if($canvaConnected)
                    <form method="POST" action="{{ route('settings.integrations.canva.templates.refresh') }}" style="display:inline;">
                        @csrf
                        <button type="submit"
                            style="border:1px solid var(--st-border);background:#fafbff;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#07183F;cursor:pointer;">
                            Aggiorna template
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.integrations.canva.disconnect') }}" style="display:inline;">
                        @csrf
                        <button type="submit"
                            style="border:1px solid #fecaca;background:#fff5f5;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#dc2626;cursor:pointer;">
                            Disconnetti
                        </button>
                    </form>
                @elseif($canvaConfigured)
                    <a href="{{ route('settings.integrations.canva.redirect') }}"
                       style="display:inline-flex;align-items:center;gap:.4rem;border-radius:.875rem;padding:.5rem 1rem;font-size:.8rem;font-weight:700;color:#fff;background:#0A2D6F;text-decoration:none;">
                        Collega Canva
                    </a>
                @endif
            </div>
        </div>

        @if(!$canvaConfigured)
            <div style="border:1px solid #fde68a;background:#fffbeb;border-radius:.875rem;padding:.875rem 1rem;font-size:.8rem;color:#92400e;margin-bottom:1rem;">
                Configura <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">CANVA_CLIENT_ID</code>, <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">CANVA_CLIENT_SECRET</code> e <code style="font-size:.75rem;background:rgba(0,0,0,.06);padding:.1rem .3rem;border-radius:.25rem;">CANVA_REDIRECT_URI</code>.
            </div>
        @endif

        {{-- 4 status chips --}}
        <div class="st-canva-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:.625rem;margin-bottom:1.25rem;">
            <div class="st-canva-chip">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Connessa</p>
                <p style="margin-top:.5rem;font-size:.875rem;font-weight:700;color:{{ $canvaConnected ? '#065f46' : '#07183F' }};">{{ $canvaConnected ? 'Sì' : 'No' }}</p>
            </div>
            <div class="st-canva-chip">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Autofill</p>
                <p style="margin-top:.5rem;font-size:.875rem;font-weight:700;color:{{ $canvaAutofillAvailable ? '#065f46' : '#92400e' }};">{{ $canvaAutofillAvailable ? 'OK' : 'Manual' }}</p>
            </div>
            <div class="st-canva-chip">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Template</p>
                <p style="margin-top:.5rem;font-size:.875rem;font-weight:700;color:{{ $canvaTemplatesAvailable ? '#065f46' : '#92400e' }};">{{ $canvaTemplatesAvailable ? 'OK' : 'Limitati' }}</p>
            </div>
            <div class="st-canva-chip">
                <p style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Export</p>
                <p style="margin-top:.5rem;font-size:.875rem;font-weight:700;color:{{ $canvaExportAvailable ? '#065f46' : '#92400e' }};">{{ $canvaExportAvailable ? 'OK' : 'Non pronto' }}</p>
            </div>
        </div>

        @if($canvaConnected)
            {{-- Connected user info --}}
            <div style="border:1px solid var(--st-border);border-radius:.875rem;padding:1rem;background:#fafbff;margin-bottom:1.25rem;display:flex;align-items:center;gap:.875rem;">
                <div style="width:2.25rem;height:2.25rem;border-radius:999px;background:linear-gradient(135deg,#3BC8FF,#0A2D6F);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg style="width:1.125rem;height:1.125rem;color:#fff;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div style="min-width:0;">
                    <p style="font-size:.875rem;font-weight:700;color:#07183F;">{{ $canvaConnection?->canva_display_name ?: 'Utente Canva connesso' }}</p>
                    <p style="font-size:.7rem;color:#64748b;margin-top:.2rem;">
                        User {{ $canvaConnection?->canva_user_id ?: 'n/d' }} · Team {{ $canvaConnection?->canva_team_id ?: 'n/d' }}
                    </p>
                    @php $canvaScopes = collect((array) ($canvaSummary['scopes'] ?? [])); @endphp
                    @if($canvaScopes->isNotEmpty())
                    <p style="font-size:.7rem;color:#94a3b8;margin-top:.25rem;">{{ $canvaScopes->implode(', ') }}</p>
                    @endif
                </div>
            </div>

            {{-- Template preview + Workflow mapping --}}
            <div class="st-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;">
                {{-- Template preview --}}
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.75rem;">
                        <p style="font-size:.8125rem;font-weight:700;color:#07183F;">Template preview</p>
                        <span class="st-chip st-chip-navy">{{ $canvaCatalogPreview->count() }} template</span>
                    </div>
                    <p style="font-size:.7rem;color:#94a3b8;margin-bottom:.75rem;">Refresh: {{ $canvaSummary['catalog_refreshed_at'] ?: 'mai' }}</p>
                    <div style="display:flex;flex-direction:column;gap:.5rem;">
                        @forelse($canvaCatalogPreview->take(6) as $template)
                            <div class="st-template-card">
                                <p style="font-size:.8rem;font-weight:700;color:#07183F;">{{ $template['title'] ?? 'Template' }}</p>
                                <p style="font-size:.65rem;color:#94a3b8;margin-top:.2rem;">{{ $template['id'] ?? '' }}</p>
                                @if(!empty($template['create_url']))
                                    <a href="{{ $template['create_url'] }}" target="_blank" rel="noreferrer"
                                       style="display:inline-block;margin-top:.375rem;font-size:.7rem;font-weight:700;color:#0e7490;text-decoration:none;">
                                        Apri in Canva →
                                    </a>
                                @endif
                            </div>
                        @empty
                            <div style="border:1px dashed rgba(10,45,111,.15);border-radius:.875rem;padding:1.5rem;text-align:center;">
                                <p style="font-size:.8rem;color:#64748b;">Nessun template. Esegui un refresh dopo aver collegato un account con Brand Templates.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Workflow mapping --}}
                <div>
                    <p style="font-size:.8125rem;font-weight:700;color:#07183F;margin-bottom:.375rem;">Workflow mapping</p>
                    <p style="font-size:.7rem;color:#64748b;margin-bottom:.75rem;">Associa template Canva ai formati di output.</p>
                    <div style="display:flex;flex-direction:column;gap:.625rem;">
                        @foreach($canvaWorkflowOptions as $workflowKey => $workflow)
                            @php $mapping = $canvaTemplateMappings->get($workflowKey); @endphp
                            <form method="POST" action="{{ route('settings.integrations.canva.templates.map') }}" class="st-workflow-card">
                                @csrf
                                <input type="hidden" name="channel_format" value="{{ $workflowKey }}">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.625rem;">
                                    <p style="font-size:.8rem;font-weight:700;color:#07183F;">{{ $workflow['label'] ?? $workflowKey }}</p>
                                    <span class="st-chip {{ $mapping?->status === 'active' ? 'st-chip-ok' : 'st-chip-navy' }}">
                                        {{ $mapping?->status === 'active' ? 'Attivo' : 'Nessuno' }}
                                    </span>
                                </div>
                                <select name="canva_template_id"
                                    style="width:100%;border:1px solid var(--st-border);border-radius:.625rem;padding:.4rem .625rem;font-size:.75rem;background:#fff;color:#07183F;outline:none;">
                                    <option value="">Manual Canva finishing</option>
                                    @foreach($canvaCatalogPreview as $template)
                                        <option value="{{ $template['id'] }}" @selected((string) $template['id'] === (string) ($mapping?->canva_template_id ?? ''))>
                                            {{ $template['title'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.625rem;">
                                    <span style="font-size:.7rem;color:#94a3b8;">{{ $mapping?->canva_template_name ?: 'Nessun template' }}</span>
                                    <button type="submit"
                                        style="border:1px solid var(--st-border);background:#fff;border-radius:.625rem;padding:.3rem .75rem;font-size:.7rem;font-weight:700;color:#07183F;cursor:pointer;">
                                        Salva
                                    </button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endif

    {{-- Stato ambiente + Logout --}}
    <div class="st-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="st-card">
            <p class="st-section-title">Stato ambiente</p>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
                <div class="st-env-chip">
                    <p style="font-size:.75rem;color:#64748b;">Meta OAuth</p>
                    <span class="st-chip {{ $metaReady ? 'st-chip-ok' : 'st-chip-warn' }}">{{ $metaReady ? 'Configurato' : 'Da configurare' }}</span>
                </div>
                <div class="st-env-chip">
                    <p style="font-size:.75rem;color:#64748b;">Account social</p>
                    <span class="st-chip {{ $activeSocialAccounts->isNotEmpty() ? 'st-chip-ok' : 'st-chip-navy' }}">{{ $activeSocialAccounts->count() > 0 ? $activeSocialAccounts->count().' collegati' : 'Nessuno' }}</span>
                </div>
                <div class="st-env-chip">
                    <p style="font-size:.75rem;color:#64748b;">Brand Center</p>
                    <a href="{{ route('profile.brand') }}"
                       style="font-size:.7rem;font-weight:700;color:#0A2D6F;text-decoration:none;">Apri →</a>
                </div>
                @if($canvaFeatureEnabled)
                <div class="st-env-chip">
                    <p style="font-size:.75rem;color:#64748b;">Canva</p>
                    <span class="st-chip {{ $canvaConnected ? 'st-chip-cyan' : 'st-chip-navy' }}">{{ $canvaConnected ? 'Connessa' : 'Non connessa' }}</span>
                </div>
                @endif
            </div>
        </div>

        <div class="st-card" style="display:flex;flex-direction:column;justify-content:space-between;">
            <div>
                <p class="st-section-title">Sessione</p>
                <p class="st-h2" style="margin-bottom:.375rem;">Account</p>
                <p style="font-size:.75rem;color:#64748b;">Chiudi la sessione corrente su questo dispositivo.</p>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="margin-top:1.25rem;">
                @csrf
                <button type="submit"
                    style="width:100%;border:1px solid #fecaca;background:#fff5f5;border-radius:.875rem;padding:.625rem 1rem;font-size:.8rem;font-weight:700;color:#dc2626;cursor:pointer;">
                    Esci dall'account
                </button>
            </form>
        </div>
    </div>

</div>
@endsection
