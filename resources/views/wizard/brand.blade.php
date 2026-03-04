@extends('layouts.app')

@section('content')
@php
    /** @var \App\Models\TenantProfile|null $profile */
    /** @var \Illuminate\Support\Collection|\App\Models\BrandAsset[] $assets */
    $profile = $profile ?? null;
    $assets = $assets ?? collect();
    if (!($assets instanceof \Illuminate\Support\Collection)) {
        $assets = collect($assets);
    }

    $byKind = $assets->groupBy('kind');
    $logos = $byKind['logo'] ?? collect();
    $images = $byKind['image'] ?? collect();

    $defaultPlatforms = old('default_platforms', $profile?->default_platforms ?? ['instagram', 'facebook']);
    if (!is_array($defaultPlatforms)) {
        $defaultPlatforms = [];
    }

    $defaultFormats = old('default_formats', $profile?->default_formats ?? ['reel', 'post']);
    if (!is_array($defaultFormats)) {
        $defaultFormats = [];
    }

    $tone = old('default_tone', $profile?->default_tone ?? 'professionale');

    $profileChecks = [
        old('business_name', $profile?->business_name ?? ''),
        old('services', $profile?->services ?? ''),
        old('target', $profile?->target ?? ''),
        old('cta', $profile?->cta ?? ''),
        old('default_goal', $profile?->default_goal ?? ''),
    ];
    $filledCount = collect($profileChecks)->filter(fn ($v) => filled($v))->count();
    $completionRate = (int) round(($filledCount / max(1, count($profileChecks))) * 100);

    $inputClass = 'block w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200';
    $labelClass = 'mb-1 block text-sm font-semibold text-gray-700';
@endphp

<section class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <div class="overflow-hidden rounded-3xl border border-gray-200 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/40 p-6 shadow-sm lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr] xl:items-center">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Brand Center</div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">Profilo azienda e asset</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-600">
                    Configura il profilo brand, i default del piano editoriale e i materiali visual usati dall'app.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Completamento {{ $completionRate }}%
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $logos->count() }} logo
                    </span>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
                        {{ $images->count() }} immagini
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Azioni rapide</div>
                <div class="mt-3 grid grid-cols-1 gap-2">
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri wizard piano
                    </a>
                    <a href="{{ route('posts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Apri contenuti
                    </a>
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Torna alla dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Controlla i campi del profilo brand:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <form method="POST" action="{{ route('profile.brand.store') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Profilo azienda</h2>
                    <p class="mt-1 text-sm text-gray-600">Dati base del brand usati dalla strategia editoriale.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="business_name" class="{{ $labelClass }}">Nome attivita</label>
                            <input id="business_name" type="text" name="business_name" value="{{ old('business_name', $profile?->business_name ?? '') }}" class="{{ $inputClass }}" required />
                        </div>
                        <div>
                            <label for="industry" class="{{ $labelClass }}">Settore</label>
                            <input id="industry" type="text" name="industry" value="{{ old('industry', $profile?->industry ?? '') }}" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="website" class="{{ $labelClass }}">Sito web</label>
                            <input id="website" type="text" name="website" value="{{ old('website', $profile?->website ?? '') }}" placeholder="https://..." class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="cta" class="{{ $labelClass }}">CTA principale</label>
                            <input id="cta" type="text" name="cta" value="{{ old('cta', $profile?->cta ?? '') }}" placeholder="Es. Scrivici su WhatsApp" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="services" class="{{ $labelClass }}">Servizi principali</label>
                            <textarea id="services" name="services" rows="3" class="{{ $inputClass }}" placeholder="Es. siti web, web app, marketing, chatbot AI...">{{ old('services', $profile?->services ?? '') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Elenca 3-6 servizi separati da virgole.</p>
                        </div>
                        <div>
                            <label for="target" class="{{ $labelClass }}">Target ideale</label>
                            <textarea id="target" name="target" rows="3" class="{{ $inputClass }}" placeholder="Es. PMI e attivita locali che vogliono piu clienti">{{ old('target', $profile?->target ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="notes" class="{{ $labelClass }}">Note</label>
                            <textarea id="notes" name="notes" rows="3" class="{{ $inputClass }}" placeholder="Extra info su posizionamento e differenziatori">{{ old('notes', $profile?->notes ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="vision" class="{{ $labelClass }}">Vision / Mission</label>
                            <textarea id="vision" name="vision" rows="3" class="{{ $inputClass }}" placeholder="Es. diventare riferimento locale">{{ old('vision', $profile?->vision ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="values" class="{{ $labelClass }}">Valori brand</label>
                            <textarea id="values" name="values" rows="3" class="{{ $inputClass }}" placeholder="Es. trasparenza, rapidita, qualita">{{ old('values', $profile?->values ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="business_hours" class="{{ $labelClass }}">Orari business (opzionale)</label>
                            <textarea id="business_hours" name="business_hours" rows="2" class="{{ $inputClass }}" placeholder="Lun-Ven 9:00-18:00">{{ old('business_hours', $profile?->business_hours ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="brand_palette" class="{{ $labelClass }}">Palette brand (opzionale)</label>
                            <input id="brand_palette" type="text" name="brand_palette" value="{{ old('brand_palette', $profile?->brand_palette ?? '') }}" placeholder="Es. #0F172A, #2563EB, #F59E0B" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="seasonal_offers" class="{{ $labelClass }}">Offerte/promozioni periodo (opzionale)</label>
                            <textarea id="seasonal_offers" name="seasonal_offers" rows="3" class="{{ $inputClass }}" placeholder="Es. promo mese, bundle, sconto primo mese">{{ old('seasonal_offers', $profile?->seasonal_offers ?? '') }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Default contenuti</h2>
                    <p class="mt-1 text-sm text-gray-600">Precompilazione automatica quando apri un nuovo piano editoriale.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="default_goal" class="{{ $labelClass }}">Obiettivo default</label>
                            <textarea id="default_goal" name="default_goal" rows="2" class="{{ $inputClass }}" placeholder="Es. Lead + Awareness + Autorita">{{ old('default_goal', $profile?->default_goal ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="default_tone" class="{{ $labelClass }}">Tone default</label>
                            <select id="default_tone" name="default_tone" class="{{ $inputClass }}">
                                <option value="professionale" @selected($tone === 'professionale')>Professionale</option>
                                <option value="amichevole" @selected($tone === 'amichevole')>Amichevole</option>
                                <option value="ironico" @selected($tone === 'ironico')>Ironico</option>
                                <option value="ispirazionale" @selected($tone === 'ispirazionale')>Ispirazionale</option>
                                <option value="tecnico" @selected($tone === 'tecnico')>Tecnico/Esperto</option>
                            </select>
                        </div>
                        <div>
                            <label for="default_posts_per_week" class="{{ $labelClass }}">Post/settimana default</label>
                            <input id="default_posts_per_week" type="number" min="1" max="21" step="1" name="default_posts_per_week" value="{{ old('default_posts_per_week', $profile?->default_posts_per_week ?? 5) }}" class="{{ $inputClass }}" />
                        </div>

                        <div>
                            <label class="{{ $labelClass }}">Piattaforme default</label>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ([
                                    'instagram' => 'Instagram',
                                    'facebook' => 'Facebook',
                                    'tiktok' => 'TikTok',
                                    'linkedin' => 'LinkedIn',
                                    'youtube' => 'YouTube',
                                    'threads' => 'Threads',
                                ] as $k => $label)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                        <input type="checkbox" name="default_platforms[]" value="{{ $k }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($k, $defaultPlatforms, true)) />
                                        <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="{{ $labelClass }}">Formati default</label>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ([
                                    'reel' => 'Reel / Short video',
                                    'post' => 'Post immagine / carousel',
                                    'story' => 'Stories',
                                    'live' => 'Live',
                                    'blog' => 'Articolo / long copy',
                                    'newsletter' => 'Newsletter',
                                ] as $k => $label)
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100">
                                        <input type="checkbox" name="default_formats[]" value="{{ $k }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($k, $defaultFormats, true)) />
                                        <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Upload assets</h2>
                    <p class="mt-1 text-sm text-gray-600">Carica logo e immagini di riferimento per guidare output e stile.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="logo" class="{{ $labelClass }}">Logo (opzionale)</label>
                            <input id="logo" type="file" name="logo" accept="image/*" class="{{ $inputClass }}" />
                            <p class="mt-1 text-xs text-gray-500">PNG consigliato, trasparente se possibile.</p>
                        </div>
                        <div>
                            <label for="images" class="{{ $labelClass }}">Immagini (multiple)</label>
                            <input id="images" type="file" name="images[]" accept="image/*" multiple class="{{ $inputClass }}" />
                            <p class="mt-1 text-xs text-gray-500">Esempi: prodotti, progetti, mood, palette.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('wizard.start') }}" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Nuovo piano editoriale
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Salva profilo brand
                    </button>
                </div>
            </form>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Assets caricati</h2>
                        <p class="mt-1 text-sm text-gray-600">Seleziona asset e usa elimina multipla o elimina singolo.</p>
                    </div>

                    @if($assets->count() > 0)
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" id="selectAllAssets" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Seleziona tutti</button>
                            <button type="button" id="clearAllAssets" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Deseleziona</button>
                            <button type="button" id="bulkDeleteBtn" data-bulk-url="{{ route('profile.brand.assets.destroy') }}" class="inline-flex items-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Elimina selezionati</button>
                        </div>
                    @endif
                </div>

                @if($assets->count() === 0)
                    <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center text-sm text-gray-600">
                        Nessun asset caricato.
                    </div>
                @else
                    <div class="mt-6 space-y-8">
                        <div>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Logo</h3>
                                <span class="text-xs text-gray-500">{{ $logos->count() }} file</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @forelse($logos as $a)
                                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <div class="border-b border-gray-200 bg-gray-50 p-2">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $a->id }}" data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="flex aspect-square items-center justify-center p-2">
                                            <img src="{{ asset('storage/' . ltrim($a->path, '/')) }}" class="max-h-full max-w-full" alt="logo">
                                        </div>

                                        <div class="truncate px-2 py-1 text-[11px] text-gray-600">{{ $a->original_name ?? $a->path }}</div>

                                        <div class="px-2 pb-2">
                                            <form method="POST" action="{{ route('profile.brand.asset.destroy', $a->id) }}" onsubmit="return confirm('Eliminare questo logo?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Elimina</button>
                                            </form>
                                        </div>
                                    </article>
                                @empty
                                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessun logo caricato.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Immagini</h3>
                                <span class="text-xs text-gray-500">{{ $images->count() }} file</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                @forelse($images as $a)
                                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <div class="border-b border-gray-200 bg-gray-50 p-2">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox" class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $a->id }}" data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="aspect-square overflow-hidden">
                                            <img src="{{ asset('storage/' . ltrim($a->path, '/')) }}" class="h-full w-full object-cover" alt="image">
                                        </div>

                                        <div class="truncate px-2 py-1 text-[11px] text-gray-600">{{ $a->original_name ?? $a->path }}</div>

                                        <div class="px-2 pb-2">
                                            <form method="POST" action="{{ route('profile.brand.asset.destroy', $a->id) }}" onsubmit="return confirm('Eliminare questa immagine?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Elimina</button>
                                            </form>
                                        </div>
                                    </article>
                                @empty
                                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-xs text-gray-600">
                                        Nessuna immagine caricata.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Stato profilo</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Completamento campi chiave</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $completionRate }}%</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Asset totali</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $assets->count() }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p class="text-xs text-gray-500">Piattaforme default</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ max(0, count($defaultPlatforms)) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Collegamenti utili</h2>
                <p class="mt-1 text-sm text-gray-600">Aree principali collegate al profilo brand.</p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('wizard.start') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Wizard Piano</p>
                        <p class="mt-1 text-xs text-gray-600">Imposta strategia e periodo contenuti.</p>
                    </a>
                    <a href="{{ route('calendar') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Calendario</p>
                        <p class="mt-1 text-xs text-gray-600">Controlla distribuzione uscite.</p>
                    </a>
                    <a href="{{ route('posts.index') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Libreria contenuti</p>
                        <p class="mt-1 text-xs text-gray-600">Gestisci post e output AI.</p>
                    </a>
                    <a href="{{ route('settings') }}" class="block rounded-xl border border-gray-200 px-4 py-3 hover:bg-gray-50">
                        <p class="text-sm font-semibold text-gray-900">Settings</p>
                        <p class="mt-1 text-xs text-gray-600">Configura opzioni applicazione.</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const checkboxes = () => Array.from(document.querySelectorAll('.asset-checkbox'));
        const selected = () => checkboxes().filter(cb => cb.checked);

        const btnSelectAll = document.getElementById('selectAllAssets');
        const btnClearAll = document.getElementById('clearAllAssets');
        const btnBulkDelete = document.getElementById('bulkDeleteBtn');

        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', () => {
                checkboxes().forEach(cb => {
                    cb.checked = true;
                });
            });
        }

        if (btnClearAll) {
            btnClearAll.addEventListener('click', () => {
                checkboxes().forEach(cb => {
                    cb.checked = false;
                });
            });
        }

        if (btnBulkDelete) {
            btnBulkDelete.addEventListener('click', async () => {
                const items = selected();
                if (!items.length) {
                    alert('Seleziona almeno un asset da eliminare.');
                    return;
                }

                if (!confirm(`Eliminare ${items.length} asset selezionati?`)) {
                    return;
                }

                btnBulkDelete.disabled = true;
                btnBulkDelete.textContent = 'Eliminazione...';

                try {
                    const bulkUrl = btnBulkDelete.getAttribute('data-bulk-url');
                    if (!bulkUrl) {
                        throw new Error('Bulk URL missing');
                    }

                    const form = new FormData();
                    form.append('_method', 'DELETE');
                    for (const cb of items) {
                        form.append('asset_ids[]', cb.value);
                    }

                    const res = await fetch(bulkUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: form,
                    });

                    if (!res.ok) {
                        throw new Error(`Bulk delete failed: ${res.status}`);
                    }

                    window.location.reload();
                } catch (e) {
                    console.error(e);
                    alert('Errore durante eliminazione multipla. Controlla console/log.');
                } finally {
                    btnBulkDelete.disabled = false;
                    btnBulkDelete.textContent = 'Elimina selezionati';
                }
            });
        }
    })();
</script>
@endsection
