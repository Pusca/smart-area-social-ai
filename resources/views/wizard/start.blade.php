
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Nuovo piano editoriale
            </h2>
            <span class="text-sm text-gray-500">Step 1 / 2</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Impostazioni piano</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Dati del piano + preferenze contenuti. Precompilato dal profilo attività.
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

                    <form method="POST" action="{{ route('wizard.store') }}" class="space-y-6">
                        @csrf

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nome piano</label>
                            <input
                                type="text"
                                name="name"
                                value="{{ old('name', $step1['name'] ?? '') }}"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Data inizio</label>
                                <input
                                    type="date"
                                    name="start_date"
                                    value="{{ old('start_date', $step1['start_date'] ?? '') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Data fine</label>
                                <input
                                    type="date"
                                    name="end_date"
                                    value="{{ old('end_date', $step1['end_date'] ?? '') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Obiettivo (goal)</label>
                            <textarea
                                name="goal"
                                rows="2"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            >{{ old('goal', $step1['goal'] ?? '') }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tone of voice</label>
                                @php $tone = old('tone', $step1['tone'] ?? 'professionale'); @endphp
                                <select
                                    name="tone"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="professionale" @selected($tone === 'professionale')>Professionale</option>
                                    <option value="amichevole" @selected($tone === 'amichevole')>Amichevole</option>
                                    <option value="ironico" @selected($tone === 'ironico')>Ironico</option>
                                    <option value="ispirazionale" @selected($tone === 'ispirazionale')>Ispirazionale</option>
                                    <option value="tecnico" @selected($tone === 'tecnico')>Tecnico/Esperto</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Post totali nel periodo</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="21"
                                    step="1"
                                    name="posts_per_week"
                                    value="{{ old('posts_per_week', $step1['posts_per_week'] ?? 5) }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                        </div>

                        @php
                            $platforms = old('platforms', $step1['platforms'] ?? ['instagram']);
                            if (!is_array($platforms)) $platforms = ['instagram'];

                            $formats = old('formats', $step1['formats'] ?? ['post']);
                            if (!is_array($formats)) $formats = ['post'];
                        @endphp

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Piattaforme</label>
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
                                        <input type="checkbox" name="platforms[]" value="{{ $k }}"
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                               @checked(in_array($k, $platforms, true)) />
                                        <span class="text-sm text-gray-800">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Formati</label>
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
                                        <input type="checkbox" name="formats[]" value="{{ $k }}"
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                               @checked(in_array($k, $formats, true)) />
                                        <span class="text-sm text-gray-800">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <a href="{{ route('profile.brand') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                ← Modifica profilo attività
                            </a>

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                Continua →
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>


