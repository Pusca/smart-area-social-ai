{{-- resources/views/wizard/brand.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Profilo attività
            </h2>
            <span class="text-sm text-gray-500">Wizard unico (tenant)</span>
        </div>
    </x-slot>

    @php
        /** @var \App\Models\TenantProfile|null $profile */
        /** @var \Illuminate\Support\Collection|\App\Models\BrandAsset[] $assets */

        $profile = $profile ?? null;
        $assets = $assets ?? collect();

        $byKind = $assets->groupBy('kind');
        $logos = $byKind['logo'] ?? collect();
        $images = $byKind['image'] ?? collect();

        $defaultPlatforms = old('default_platforms', $profile?->default_platforms ?? ['instagram','facebook']);
        if (!is_array($defaultPlatforms)) $defaultPlatforms = [];

        $defaultFormats = old('default_formats', $profile?->default_formats ?? ['reel','post']);
        if (!is_array($defaultFormats)) $defaultFormats = [];
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            @if (session('status'))
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                    <div class="font-semibold">Controlla questi campi:</div>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- FORM PROFILO --}}
            <div class="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Dati attività + default contenuti</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Queste info sono “base” per il tenant e le useremo come default per i piani editoriali.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('profile.brand.store') }}" enctype="multipart/form-data" class="space-y-8">
                        @csrf

                        {{-- DATI AZIENDA --}}
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5">
                            <h4 class="text-base font-semibold text-gray-900">Profilo</h4>

                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Nome attività</label>
                                    <input
                                        type="text"
                                        name="business_name"
                                        value="{{ old('business_name', $profile?->business_name ?? '') }}"
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Settore (industry)</label>
                                    <input
                                        type="text"
                                        name="industry"
                                        value="{{ old('industry', $profile?->industry ?? '') }}"
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Sito web</label>
                                    <input
                                        type="text"
                                        name="website"
                                        value="{{ old('website', $profile?->website ?? '') }}"
                                        placeholder="https://..."
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">CTA principale</label>
                                    <input
                                        type="text"
                                        name="cta"
                                        value="{{ old('cta', $profile?->cta ?? '') }}"
                                        placeholder="Es. Scrivici su WhatsApp per una demo"
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Servizi principali</label>
                                <textarea
                                    name="services"
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Es. Siti web, web app, marketing, chatbot AI, automazioni..."
                                >{{ old('services', $profile?->services ?? '') }}</textarea>
                                <p class="mt-1 text-xs text-gray-500">Elenca 3–6 servizi, separati da virgole.</p>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Target ideale</label>
                                <textarea
                                    name="target"
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Es. PMI e attività locali che vogliono più clienti senza perdere tempo sui social"
                                >{{ old('target', $profile?->target ?? '') }}</textarea>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Note</label>
                                <textarea
                                    name="notes"
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Extra info: posizionamento, punti di forza, differenziatori..."
                                >{{ old('notes', $profile?->notes ?? '') }}</textarea>
                            </div>
                        </div>

                        {{-- DEFAULT CONTENUTI --}}
                        <div class="rounded-2xl border border-gray-200 bg-white p-5">
                            <h4 class="text-base font-semibold text-gray-900">Default contenuti (per i piani)</h4>
                            <p class="mt-1 text-sm text-gray-600">
                                Questi valori vengono precompilati quando crei un nuovo piano editoriale.
                            </p>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Obiettivo default</label>
                                <textarea
                                    name="default_goal"
                                    rows="2"
                                    class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Es. Lead + Awareness + Autorità"
                                >{{ old('default_goal', $profile?->default_goal ?? '') }}</textarea>
                            </div>

                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Tone default</label>
                                    @php $tone = old('default_tone', $profile?->default_tone ?? 'professionale'); @endphp
                                    <select
                                        name="default_tone"
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="professionale" @selected($tone==='professionale')>Professionale</option>
                                        <option value="amichevole" @selected($tone==='amichevole')>Amichevole</option>
                                        <option value="ironico" @selected($tone==='ironico')>Ironico</option>
                                        <option value="ispirazionale" @selected($tone==='ispirazionale')>Ispirazionale</option>
                                        <option value="tecnico" @selected($tone==='tecnico')>Tecnico/Esperto</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Post/settimana default</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="21"
                                        step="1"
                                        name="default_posts_per_week"
                                        value="{{ old('default_posts_per_week', $profile?->default_posts_per_week ?? 5) }}"
                                        class="mt-1 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <div class="text-sm text-gray-500 flex items-end">
                                    Consiglio: 4–12 per partire.
                                </div>
                            </div>

                            <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                                {{-- PLATFORMS --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Piattaforme default</label>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        @foreach ([
                                            'instagram' => 'Instagram',
                                            'facebook'  => 'Facebook',
                                            'tiktok'    => 'TikTok',
                                            'linkedin'  => 'LinkedIn',
                                            'youtube'   => 'YouTube',
                                            'threads'   => 'Threads',
                                        ] as $k => $label)
                                            <label class="flex items-center gap-2 rounded-xl border border-gray-200 p-3 hover:bg-gray-50">
                                                <input
                                                    type="checkbox"
                                                    name="default_platforms[]"
                                                    value="{{ $k }}"
                                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    @checked(in_array($k, $defaultPlatforms, true))
                                                />
                                                <span class="text-sm text-gray-800">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">Seleziona almeno 1 piattaforma.</p>
                                </div>

                                {{-- FORMATS --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Formati default</label>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        @foreach ([
                                            'reel'       => 'Reel / Short video',
                                            'post'       => 'Post immagine / carousel',
                                            'story'      => 'Stories',
                                            'live'       => 'Live',
                                            'blog'       => 'Articolo / long copy',
                                            'newsletter' => 'Newsletter',
                                        ] as $k => $label)
                                            <label class="flex items-center gap-2 rounded-xl border border-gray-200 p-3 hover:bg-gray-50">
                                                <input
                                                    type="checkbox"
                                                    name="default_formats[]"
                                                    value="{{ $k }}"
                                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    @checked(in_array($k, $defaultFormats, true))
                                                />
                                                <span class="text-sm text-gray-800">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">Seleziona almeno 1 formato.</p>
                                </div>
                            </div>
                        </div>

                        {{-- BRAND ASSETS UPLOAD --}}
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5">
                            <h4 class="text-base font-semibold text-gray-900">Brand assets (logo & immagini)</h4>
                            <p class="mt-1 text-sm text-gray-600">
                                Carica logo e immagini di riferimento: l’AI cercherà di rispettare colori e stile.
                            </p>

                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Logo (opzionale)</label>
                                    <input type="file" name="logo" accept="image/*" class="mt-2 block w-full text-sm" />
                                    <p class="mt-1 text-xs text-gray-500">PNG consigliato, trasparente se possibile.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Immagini (multiple)</label>
                                    <input type="file" name="images[]" accept="image/*" multiple class="mt-2 block w-full text-sm" />
                                    <p class="mt-1 text-xs text-gray-500">Esempi: prodotti, progetti, mood, palette.</p>
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-between gap-3">
                            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                ← Dashboard
                            </a>

                            <div class="flex gap-2">
                                <a href="{{ route('wizard.start') }}"
                                   class="inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold border bg-white hover:bg-gray-50">
                                    + Crea nuovo piano editoriale
                                </a>

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-900"
                                >
                                    Salva profilo
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ASSETS LIST + DELETE MULTIPLO --}}
            <div class="mt-6 bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Assets caricati</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Seleziona uno o più assets e usa “Elimina selezionati”.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button"
                                    id="selectAllAssets"
                                    class="rounded-xl border px-3 py-2 text-sm bg-white hover:bg-gray-50">
                                Seleziona tutti
                            </button>

                            <button type="button"
                                    id="clearAllAssets"
                                    class="rounded-xl border px-3 py-2 text-sm bg-white hover:bg-gray-50">
                                Deseleziona
                            </button>

                            <button type="button"
                                    id="bulkDeleteBtn"
                                    class="rounded-xl px-4 py-2 text-sm font-semibold bg-red-600 text-white hover:bg-red-700">
                                Elimina selezionati
                            </button>
                        </div>
                    </div>

                    @if($assets->count() === 0)
                        <div class="mt-6 rounded-xl border border-dashed p-6 text-center text-sm text-gray-600">
                            Nessun asset caricato.
                        </div>
                    @else
                        {{-- LOGHI --}}
                        <div class="mt-6">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold">Logo</div>
                                <div class="text-xs text-gray-500">{{ $logos->count() }} file</div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                                @forelse($logos as $a)
                                    <div class="border rounded-xl overflow-hidden bg-white">
                                        <div class="p-2 bg-gray-50">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox"
                                                       class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                       value="{{ $a->id }}"
                                                       data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="aspect-square flex items-center justify-center p-2 bg-white">
                                            <img src="{{ asset('storage/' . $a->path) }}" class="max-h-full max-w-full" alt="logo">
                                        </div>

                                        <div class="px-2 py-1 text-[11px] text-gray-500 truncate">
                                            {{ $a->original_name ?? $a->path }}
                                        </div>

                                        <div class="px-2 pb-2">
                                            <form method="POST"
                                                  action="{{ route('profile.brand.asset.destroy', $a->id) }}"
                                                  onsubmit="return confirm('Eliminare questo logo?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="w-full text-xs rounded-lg border px-2 py-1 bg-white hover:bg-red-50 hover:border-red-200 hover:text-red-700">
                                                    Elimina
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-sm text-gray-400">Nessun logo caricato.</div>
                                @endforelse
                            </div>
                        </div>

                        {{-- IMMAGINI --}}
                        <div class="mt-8">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold">Immagini</div>
                                <div class="text-xs text-gray-500">{{ $images->count() }} file</div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                                @forelse($images as $a)
                                    <div class="border rounded-xl overflow-hidden bg-white">
                                        <div class="p-2 bg-gray-50">
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="checkbox"
                                                       class="asset-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                       value="{{ $a->id }}"
                                                       data-destroy-url="{{ route('profile.brand.asset.destroy', $a->id) }}">
                                                Seleziona
                                            </label>
                                        </div>

                                        <div class="aspect-square overflow-hidden bg-white">
                                            <img src="{{ asset('storage/' . $a->path) }}" class="w-full h-full object-cover" alt="image">
                                        </div>

                                        <div class="px-2 py-1 text-[11px] text-gray-500 truncate">
                                            {{ $a->original_name ?? $a->path }}
                                        </div>

                                        <div class="px-2 pb-2">
                                            <form method="POST"
                                                  action="{{ route('profile.brand.asset.destroy', $a->id) }}"
                                                  onsubmit="return confirm('Eliminare questa immagine?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="w-full text-xs rounded-lg border px-2 py-1 bg-white hover:bg-red-50 hover:border-red-200 hover:text-red-700">
                                                    Elimina
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-sm text-gray-400">Nessuna immagine caricata.</div>
                                @endforelse
                            </div>
                        </div>

                        {{-- CSRF per fetch bulk delete --}}
                        <meta name="csrf-token" content="{{ csrf_token() }}">
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- JS: bulk delete (chiama la DELETE route esistente per ogni asset selezionato) --}}
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
                    checkboxes().forEach(cb => cb.checked = true);
                });
            }

            if (btnClearAll) {
                btnClearAll.addEventListener('click', () => {
                    checkboxes().forEach(cb => cb.checked = false);
                });
            }

            if (btnBulkDelete) {
                btnBulkDelete.addEventListener('click', async () => {
                    const items = selected();
                    if (!items.length) {
                        alert('Seleziona almeno un asset da eliminare.');
                        return;
                    }

                    if (!confirm(`Eliminare ${items.length} asset selezionati?`)) return;

                    btnBulkDelete.disabled = true;
                    btnBulkDelete.textContent = 'Eliminazione...';

                    try {
                        // Esegui DELETE uno per uno (semplice e robusto)
                        for (const cb of items) {
                            const url = cb.getAttribute('data-destroy-url');
                            if (!url) continue;

                            const form = new FormData();
                            form.append('_method', 'DELETE');

                            const res = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: form
                            });

                            if (!res.ok) {
                                // Se uno fallisce, fermiamo e mostriamo errore
                                console.error('Delete failed', url, res.status);
                                alert('Errore eliminazione su uno degli asset. Controlla i log.');
                                break;
                            }
                        }

                        // refresh
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
</x-app-layout>
