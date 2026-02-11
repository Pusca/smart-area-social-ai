<x-app-layout>
    {{-- BRAND VIEW v5 + ASSETS --}}

    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Wizard · Brand & piano
            </h2>
            <span class="text-sm text-gray-500">Step 2 / 2</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">

                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Impostazioni contenuti</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Seleziona obiettivo, tono, piattaforme, formati e quante uscite a settimana. Poi generiamo.
                        </p>
                    </div>

                    @if (session('status'))
                        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4">
                            <p class="font-medium text-green-800">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                            <p class="font-medium text-red-800">Controlla questi campi:</p>
                            <ul class="mt-2 list-disc pl-5 text-sm text-red-700">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('wizard.brand.store') }}" class="space-y-8" enctype="multipart/form-data">
                        @csrf

                        {{-- =======================
                             BRAND (campi richiesti)
                        ======================== --}}
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5">
                            <div class="mb-4">
                                <h4 class="text-base font-semibold text-gray-900">Brand</h4>
                                <p class="mt-1 text-sm text-gray-600">
                                    Queste info servono per creare un piano coerente con la tua attività.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Nome attività</label>
                                    <input
                                        type="text"
                                        name="business_name"
                                        value="{{ old('business_name', 'Smartera') }}"
                                        class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Settore (industry)</label>
                                    <input
                                        type="text"
                                        name="industry"
                                        value="{{ old('industry', 'Digital agency + automazioni + AI') }}"
                                        class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                        required
                                    />
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Servizi principali</label>
                                <textarea
                                    name="services"
                                    rows="3"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Es. Siti web, web app, marketing, chatbot AI, automazioni, consulenza"
                                    required
                                >{{ old('services', 'Siti web e web app, marketing, chatbot AI, automazioni, consulenza') }}</textarea>
                                <p class="mt-1 text-xs text-gray-500">Elenca 3–6 servizi, separati da virgole.</p>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Target ideale</label>
                                <textarea
                                    name="target"
                                    rows="3"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Es. PMI e attività locali che vogliono più clienti senza perdere tempo sui social"
                                    required
                                >{{ old('target', 'PMI e attività locali che vogliono più clienti senza perdere tempo sui social') }}</textarea>
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">CTA (Call To Action) principale</label>
                                <input
                                    type="text"
                                    name="cta"
                                    value="{{ old('cta', 'Scrivici su WhatsApp per una demo') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                                <p class="mt-1 text-xs text-gray-500">Esempio: “Richiedi preventivo”, “Prenota call”, “Scrivici su WhatsApp”.</p>
                            </div>
                        </div>

                        {{-- =======================
                             GOAL
                        ======================== --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Obiettivo (goal)</label>
                            <textarea
                                name="goal"
                                rows="3"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            >{{ old('goal', 'Lead + Awareness + Autorità') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Scrivi in una riga cosa vuoi ottenere dai social.</p>
                        </div>

                        {{-- TONE + POSTS/WEEK --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tone of voice</label>
                                @php $tone = old('tone', 'professionale'); @endphp
                                <select
                                    name="tone"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="" disabled @selected($tone==='')>Seleziona…</option>
                                    <option value="professionale" @selected($tone === 'professionale')>Professionale</option>
                                    <option value="amichevole" @selected($tone === 'amichevole')>Amichevole</option>
                                    <option value="ironico" @selected($tone === 'ironico')>Ironico</option>
                                    <option value="ispirazionale" @selected($tone === 'ispirazionale')>Ispirazionale</option>
                                    <option value="tecnico" @selected($tone === 'tecnico')>Tecnico/Esperto</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Post per settimana</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="21"
                                    step="1"
                                    name="posts_per_week"
                                    value="{{ old('posts_per_week', 5) }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                                <p class="mt-1 text-xs text-gray-500">Consiglio: 4–7 per partire.</p>
                            </div>
                        </div>

                        {{-- PLATFORMS --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Piattaforme</label>
                            @php
                                $platforms = old('platforms', ['instagram','facebook']);
                                if (!is_array($platforms)) $platforms = [];
                            @endphp

                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ([
                                    'instagram' => 'Instagram',
                                    'facebook'  => 'Facebook',
                                    'tiktok'    => 'TikTok',
                                    'linkedin'  => 'LinkedIn',
                                    'youtube'   => 'YouTube',
                                    'threads'   => 'Threads',
                                ] as $k => $label)
                                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 hover:bg-gray-50">
                                        <input
                                            type="checkbox"
                                            name="platforms[]"
                                            value="{{ $k }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @checked(in_array($k, $platforms, true))
                                        />
                                        <span class="text-sm text-gray-800">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <p class="mt-1 text-xs text-gray-500">Seleziona almeno 1 piattaforma.</p>
                        </div>

                        {{-- FORMATS --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Formati</label>
                            @php
                                $formats = old('formats', ['reel','post']);
                                if (!is_array($formats)) $formats = [];
                            @endphp

                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ([
                                    'reel'       => 'Reel / Short video',
                                    'post'       => 'Post immagine / carousel',
                                    'story'      => 'Stories',
                                    'live'       => 'Live',
                                    'blog'       => 'Articolo / long copy',
                                    'newsletter' => 'Newsletter',
                                ] as $k => $label)
                                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 hover:bg-gray-50">
                                        <input
                                            type="checkbox"
                                            name="formats[]"
                                            value="{{ $k }}"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @checked(in_array($k, $formats, true))
                                        />
                                        <span class="text-sm text-gray-800">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <p class="mt-1 text-xs text-gray-500">Seleziona almeno 1 formato.</p>
                        </div>

                        {{-- =======================
                             ✅ BRAND ASSETS (NUOVO)
                        ======================== --}}
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-5">
                            <div class="mb-4">
                                <h4 class="text-base font-semibold text-gray-900">Brand assets (logo & immagini)</h4>
                                <p class="mt-1 text-sm text-gray-600">
                                    Carica logo e immagini di riferimento: l’AI cercherà di rispettare colori e stile.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Logo (opzionale)</label>
                                    <input
                                        type="file"
                                        name="logo"
                                        accept="image/*"
                                        class="mt-2 block w-full text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500">PNG consigliato, sfondo trasparente se possibile.</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Immagini azienda (fino a 8)</label>
                                    <input
                                        type="file"
                                        name="images[]"
                                        accept="image/*"
                                        multiple
                                        class="mt-2 block w-full text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500">Esempi: prodotti, progetti, mood, palette, reference.</p>
                                </div>
                            </div>

                            {{-- Preview assets se passati dal controller --}}
                            @php
                                $assets = $assets ?? collect();
                                $byKind = method_exists($assets, 'groupBy') ? $assets->groupBy('kind') : collect();
                            @endphp

                            @if ($assets && $assets->count())
                                <div class="mt-6">
                                    <div class="text-sm font-medium text-gray-700 mb-2">Assets caricati</div>

                                    <div class="mb-5">
                                        <div class="text-xs text-gray-500 mb-2">Logo</div>
                                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                                            @foreach(($byKind['logo'] ?? collect()) as $a)
                                                <div class="border rounded-lg overflow-hidden bg-white">
                                                    <div class="aspect-square flex items-center justify-center p-2 bg-gray-50">
                                                        <img src="{{ asset('storage/' . $a->path) }}" class="max-h-full max-w-full" alt="logo">
                                                    </div>
                                                    <div class="px-2 py-1 text-[11px] text-gray-500 truncate">
                                                        {{ $a->original_name ?? $a->path }}
                                                    </div>
                                                </div>
                                            @endforeach

                                            @if(($byKind['logo'] ?? collect())->count() === 0)
                                                <div class="text-sm text-gray-400">Nessun logo caricato.</div>
                                            @endif
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-xs text-gray-500 mb-2">Immagini</div>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                            @foreach(($byKind['image'] ?? collect()) as $a)
                                                <div class="border rounded-lg overflow-hidden bg-white">
                                                    <div class="aspect-square overflow-hidden bg-gray-50">
                                                        <img src="{{ asset('storage/' . $a->path) }}" class="w-full h-full object-cover" alt="image">
                                                    </div>
                                                    <div class="px-2 py-1 text-[11px] text-gray-500 truncate">
                                                        {{ $a->original_name ?? $a->path }}
                                                    </div>
                                                </div>
                                            @endforeach

                                            @if(($byKind['image'] ?? collect())->count() === 0)
                                                <div class="text-sm text-gray-400">Nessuna immagine caricata.</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <a href="{{ route('wizard.start') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                ← Indietro
                            </a>

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                Genera piano →
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <p class="text-sm text-gray-700">
                    Dopo questo step: generazione contenuti + (poi) immagini/video e collegamento account social.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
