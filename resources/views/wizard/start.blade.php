<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Nuovo piano editoriale
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Quando e con quale obiettivo?</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Tono, piattaforme e formati arrivano dal tuo profilo attività.
                            Al salvataggio la generazione AI parte subito.
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

                    {{-- Riepilogo dal profilo attività --}}
                    <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-800">Dal profilo attività</div>
                            <a href="{{ route('profile.brand') }}" class="text-sm text-indigo-600 hover:text-indigo-800">Modifica →</a>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-white border border-gray-200 px-3 py-1 text-gray-700">
                                Tono: <b>{{ $profile->default_tone ?? 'professionale' }}</b>
                            </span>
                            @foreach (($profile->default_platforms ?: ['instagram']) as $p)
                                <span class="rounded-full bg-indigo-50 border border-indigo-100 px-3 py-1 text-indigo-700">{{ ucfirst($p) }}</span>
                            @endforeach
                            @foreach (($profile->default_formats ?: ['post']) as $f)
                                <span class="rounded-full bg-white border border-gray-200 px-3 py-1 text-gray-700">{{ ucfirst($f) }}</span>
                            @endforeach
                        </div>
                    </div>

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

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Data inizio</label>
                                <input
                                    type="date"
                                    name="start_date"
                                    id="wizard-start-date"
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
                                    id="wizard-end-date"
                                    value="{{ old('end_date', $step1['end_date'] ?? '') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Post a settimana</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="21"
                                    step="1"
                                    name="posts_per_week"
                                    id="wizard-posts-per-week"
                                    value="{{ old('posts_per_week', $step1['posts_per_week'] ?? 5) }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                        </div>

                        <p id="wizard-total-estimate" class="text-xs text-gray-500"></p>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Obiettivo (goal)</label>
                            <textarea
                                name="goal"
                                rows="2"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            >{{ old('goal', $step1['goal'] ?? '') }}</textarea>
                        </div>

                        <div class="pt-2 flex items-center justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                Crea piano e genera con l'AI ✨
                            </button>
                        </div>
                    </form>

                    <script>
                        (() => {
                            const start = document.getElementById('wizard-start-date');
                            const end = document.getElementById('wizard-end-date');
                            const perWeek = document.getElementById('wizard-posts-per-week');
                            const label = document.getElementById('wizard-total-estimate');

                            const update = () => {
                                const s = new Date(start.value), e = new Date(end.value), n = parseInt(perWeek.value, 10);
                                if (isNaN(s) || isNaN(e) || isNaN(n) || e < s) { label.textContent = ''; return; }
                                const days = Math.floor((e - s) / 86400000) + 1;
                                const weeks = Math.max(1, Math.ceil(days / 7));
                                const total = Math.min(n * weeks, 90);
                                label.textContent = `≈ ${total} post totali nel periodo (${weeks} settiman${weeks === 1 ? 'a' : 'e'})`;
                            };

                            [start, end, perWeek].forEach(el => el.addEventListener('input', update));
                            update();
                        })();
                    </script>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
