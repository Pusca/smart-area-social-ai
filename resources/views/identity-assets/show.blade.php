@extends('layouts.app')

@section('content')
@php
    $typeLabels = ['person' => 'Persona', 'product' => 'Prodotto', 'location' => 'Location', 'brand_object' => 'Brand Object'];
    $typeBadge  = ['person' => 'ui-badge-blue', 'product' => 'ui-badge-green', 'location' => 'ui-badge-yellow', 'brand_object' => 'ui-badge-purple'];
    $roleLabels = ['canonical' => 'Canonico', 'reference' => 'Riferimento', 'negative' => 'Negativo'];
    $roleBadge  = ['canonical' => 'ui-badge-blue', 'reference' => 'ui-badge-gray', 'negative' => 'ui-badge-red'];
@endphp

<div
    x-data="identityShow()"
    class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8"
>

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('identity-assets.index') }}" class="mb-2 inline-flex items-center gap-1 text-xs" style="color:#64748b;">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Identity Asset
            </a>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="ui-h1">{{ $identityAsset->name }}</h1>
                <span class="ui-chip {{ $typeBadge[$identityAsset->type] ?? 'ui-badge-gray' }}">
                    {{ $typeLabels[$identityAsset->type] ?? $identityAsset->type }}
                </span>
                @if($identityAsset->locked_visual_identity)
                    <span class="ui-chip" style="border-color:rgba(10,45,111,0.18);background:rgba(10,45,111,0.08);color:#0A2D6F;">
                        <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Locked
                    </span>
                @endif
            </div>
            @if($identityAsset->description)
                <p class="ui-subtitle mt-1">{{ $identityAsset->description }}</p>
            @endif
        </div>
        <div class="flex gap-2">
            <a href="{{ route('identity-assets.edit', $identityAsset) }}" class="ui-btn-secondary text-sm">Modifica</a>
            <form method="POST" action="{{ route('identity-assets.destroy', $identityAsset) }}" onsubmit="return confirm('Eliminare questa identità? Le variabili e gli alter ego collegati verranno scollegati.')">
                @csrf @method('DELETE')
                <button type="submit" class="ui-btn-danger text-sm">Elimina</button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Colonna sinistra: info + prompt --}}
        <div class="space-y-5 lg:col-span-1">

            {{-- Caratteristiche visive --}}
            @if($identityAsset->visual_features_json)
            <div class="ui-card">
                <h2 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Caratteristiche visive</h2>
                <ul class="space-y-2">
                    @foreach($identityAsset->visual_features_json as $key => $value)
                    <li class="flex items-start gap-2 text-xs">
                        <span class="font-medium capitalize" style="color:#0A2D6F;min-width:5rem;">{{ str_replace('_', ' ', $key) }}</span>
                        <span style="color:#475569;">{{ is_array($value) ? implode(', ', $value) : $value }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Regole di utilizzo --}}
            @if($identityAsset->usage_rules_json)
            <div class="ui-card">
                <h2 class="mb-3 text-sm font-semibold" style="color:#0A2D6F;">Regole di composizione</h2>
                <ul class="space-y-2">
                    @foreach($identityAsset->usage_rules_json as $key => $value)
                    @if($value !== '' && $value !== null && $value !== false)
                    <li class="flex items-start gap-2 text-xs">
                        <span class="font-medium capitalize" style="color:#0A2D6F;min-width:5rem;">{{ str_replace('_', ' ', $key) }}</span>
                        <span style="color:#475569;">{{ is_bool($value) ? ($value ? 'Sì' : 'No') : (is_array($value) ? implode(', ', $value) : $value) }}</span>
                    </li>
                    @endif
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Prompt compilato --}}
            <div class="ui-card" x-data="{ showPrompt: false }">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Prompt identità
                        @if($identityAsset->identity_prompt_version > 0)
                            <span class="ml-1 text-xs font-normal" style="color:#94a3b8;">v{{ $identityAsset->identity_prompt_version }}</span>
                        @endif
                    </h2>
                    <div class="flex items-center gap-2">
                        <button
                            @click="recompile()"
                            :disabled="recompiling"
                            class="text-xs underline disabled:opacity-50"
                            style="color:#1498F3;"
                        >
                            <span x-text="recompiling ? 'Ricalcolo...' : 'Ricalcola'"></span>
                        </button>
                        <button @click="showPrompt = !showPrompt" class="text-xs" style="color:#64748b;" x-text="showPrompt ? 'Nascondi' : 'Mostra'"></button>
                    </div>
                </div>
                @if(!$identityAsset->identity_prompt)
                    <p class="mt-2 text-xs" style="color:#94a3b8;">Nessun prompt compilato — aggiungi caratteristiche visive e clicca "Ricalcola".</p>
                @else
                    <div x-show="showPrompt" x-cloak class="mt-3 rounded-xl p-3 text-xs font-mono leading-relaxed" style="background:rgba(239,245,255,0.8);color:#0A2D6F;white-space:pre-wrap;word-break:break-word;">{{ $identityAsset->identity_prompt }}</div>
                @endif
            </div>

        </div>

        {{-- Colonna destra: media + link --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Media di riferimento --}}
            <div class="ui-card" x-data="mediaManager()">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Media di riferimento</h2>
                    <button @click="showAddPanel = !showAddPanel" class="ui-btn-quiet text-xs py-1.5 px-3 gap-1">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Aggiungi
                    </button>
                </div>

                {{-- Panel aggiunta --}}
                <div x-show="showAddPanel" x-cloak class="mt-4 rounded-2xl border p-4" style="border-color:rgba(10,45,111,0.12);background:rgba(239,245,255,0.6);">
                    <p class="mb-3 text-xs font-semibold" style="color:#0A2D6F;">Seleziona un brand asset e scegli il ruolo</p>

                    <div class="space-y-3">
                        <select x-model="addForm.brand_asset_id" class="ui-input text-sm">
                            <option value="">— Seleziona immagine —</option>
                            @foreach($brandAssets as $asset)
                                <option value="{{ $asset->id }}">
                                    {{ $asset->original_name ?? 'Asset #'.$asset->id }}
                                    {{ $asset->ai_description ? ' — ' . \Illuminate\Support\Str::limit($asset->ai_description, 40) : '' }}
                                </option>
                            @endforeach
                        </select>

                        <div class="flex gap-2">
                            @foreach(['canonical' => 'Canonico', 'reference' => 'Riferimento', 'negative' => 'Negativo'] as $roleKey => $roleLabel)
                            <label class="flex cursor-pointer items-center gap-1.5 rounded-xl border px-3 py-2 text-xs transition" :style="addForm.role === '{{ $roleKey }}' ? 'background:rgba(10,45,111,0.08);border-color:#0A2D6F;color:#0A2D6F;font-weight:600;' : 'border-color:rgba(10,45,111,0.12);color:#64748b;'">
                                <input type="radio" value="{{ $roleKey }}" x-model="addForm.role" class="sr-only">
                                {{ $roleLabel }}
                            </label>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-2">
                            <button @click="attachMedia()" :disabled="!addForm.brand_asset_id || adding" class="ui-btn-primary text-xs py-2 disabled:opacity-40">
                                <span x-text="adding ? 'Aggiunta...' : 'Aggiungi'"></span>
                            </button>
                            <button @click="showAddPanel = false" class="ui-btn-secondary text-xs py-2">Annulla</button>
                        </div>
                    </div>
                </div>

                {{-- Media list --}}
                @php $allMedia = $identityAsset->canonicalMedia->merge($identityAsset->referenceMedia)->merge($identityAsset->negativeMedia); @endphp

                @if($allMedia->isEmpty())
                    <p class="mt-4 text-center text-sm" style="color:#94a3b8;">Nessun media collegato ancora. Aggiungi almeno un'immagine canonica per abilitare l'identità visiva nel pipeline.</p>
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($identityAsset->canonicalMedia as $asset)
                        @include('identity-assets._media_thumb', ['asset' => $asset, 'role' => 'canonical'])
                        @endforeach
                        @foreach($identityAsset->referenceMedia as $asset)
                        @include('identity-assets._media_thumb', ['asset' => $asset, 'role' => 'reference'])
                        @endforeach
                        @foreach($identityAsset->negativeMedia as $asset)
                        @include('identity-assets._media_thumb', ['asset' => $asset, 'role' => 'negative'])
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Asset Variable collegate --}}
            <div class="ui-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Variabili asset collegate</h2>
                    <span class="ui-chip text-xs">{{ $identityAsset->assetVariables->count() }}</span>
                </div>

                @if($identityAsset->assetVariables->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($identityAsset->assetVariables as $var)
                        <li class="flex items-center justify-between rounded-xl border px-3 py-2.5 text-sm" style="border-color:rgba(10,45,111,0.1);background:rgba(239,245,255,0.5);">
                            <div>
                                <span class="font-medium" style="color:#0A2D6F;">{{ $var->name }}</span>
                                <span class="ml-2 text-xs" style="color:#94a3b8;">{{ $var->kind }}</span>
                            </div>
                            <button
                                @click="unlinkVariable({{ $var->id }})"
                                class="text-xs" style="color:#dc2626;"
                            >Scollega</button>
                        </li>
                        @endforeach
                    </ul>
                @endif

                @if($unlinkable->isNotEmpty())
                    <div class="mt-3" x-data="{ open: false }">
                        <button @click="open = !open" class="text-xs underline" style="color:#1498F3;" x-text="open ? 'Chiudi' : '+ Collega variabile'"></button>
                        <div x-show="open" x-cloak class="mt-2 rounded-xl border p-3" style="border-color:rgba(10,45,111,0.12);">
                            <select id="link-variable-select" class="ui-input text-sm">
                                <option value="">— Seleziona variabile —</option>
                                @foreach($unlinkable as $var)
                                    <option value="{{ $var->id }}">{{ $var->name }} ({{ $var->kind }}){{ $var->identity_asset_id ? ' [già collegata ad altra identità]' : '' }}</option>
                                @endforeach
                            </select>
                            <button
                                @click="linkVariable(document.getElementById('link-variable-select').value)"
                                class="ui-btn-primary mt-2 text-xs py-2"
                            >Collega</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Alter Ego collegati --}}
            <div class="ui-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold" style="color:#0A2D6F;">Alter Ego collegati</h2>
                    <span class="ui-chip text-xs">{{ $identityAsset->alterEgos->count() }}</span>
                </div>

                @if($identityAsset->alterEgos->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($identityAsset->alterEgos as $ego)
                        <li class="flex items-center justify-between rounded-xl border px-3 py-2.5 text-sm" style="border-color:rgba(10,45,111,0.1);background:rgba(239,245,255,0.5);">
                            <span class="font-medium" style="color:#0A2D6F;">{{ $ego->name }}</span>
                            <button @click="unlinkAlterEgo({{ $ego->id }})" class="text-xs" style="color:#dc2626;">Scollega</button>
                        </li>
                        @endforeach
                    </ul>
                @endif

                @if($unlinkableAlterEgos->isNotEmpty())
                    <div class="mt-3" x-data="{ open: false }">
                        <button @click="open = !open" class="text-xs underline" style="color:#1498F3;" x-text="open ? 'Chiudi' : '+ Collega alter ego'"></button>
                        <div x-show="open" x-cloak class="mt-2 rounded-xl border p-3" style="border-color:rgba(10,45,111,0.12);">
                            <select id="link-ego-select" class="ui-input text-sm">
                                <option value="">— Seleziona alter ego —</option>
                                @foreach($unlinkableAlterEgos as $ego)
                                    <option value="{{ $ego->id }}">{{ $ego->name }}{{ $ego->identity_asset_id ? ' [già collegato]' : '' }}</option>
                                @endforeach
                            </select>
                            <button @click="linkAlterEgo(document.getElementById('link-ego-select').value)" class="ui-btn-primary mt-2 text-xs py-2">Collega</button>
                        </div>
                    </div>
                @endif
            </div>

        </div>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const BASE = '{{ route("identity-assets.show", $identityAsset) }}';

function identityShow() {
    return {
        recompiling: false,
        async recompile() {
            this.recompiling = true;
            await fetch('{{ route("identity-assets.recompile", $identityAsset) }}', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
            });
            window.location.reload();
        },
        async linkVariable(id) {
            if (!id) return;
            await fetch('{{ route("identity-assets.link-variable", $identityAsset) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ asset_variable_id: parseInt(id) }),
            });
            window.location.reload();
        },
        async unlinkVariable(id) {
            await fetch('{{ route("identity-assets.unlink-variable", $identityAsset) }}', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ asset_variable_id: id }),
            });
            window.location.reload();
        },
        async linkAlterEgo(id) {
            if (!id) return;
            await fetch('{{ route("identity-assets.link-alter-ego", $identityAsset) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ alter_ego_id: parseInt(id) }),
            });
            window.location.reload();
        },
        async unlinkAlterEgo(id) {
            await fetch('{{ route("identity-assets.unlink-alter-ego", $identityAsset) }}', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ alter_ego_id: id }),
            });
            window.location.reload();
        },
    };
}

function mediaManager() {
    return {
        showAddPanel: false,
        adding: false,
        addForm: { brand_asset_id: '', role: 'reference' },
        async attachMedia() {
            if (!this.addForm.brand_asset_id) return;
            this.adding = true;
            await fetch('{{ route("identity-assets.media.attach", $identityAsset) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify(this.addForm),
            });
            window.location.reload();
        },
        async detachMedia(brandAssetId) {
            await fetch('{{ route("identity-assets.media.detach", $identityAsset) }}', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ brand_asset_id: brandAssetId }),
            });
            window.location.reload();
        },
    };
}
</script>
@endsection
