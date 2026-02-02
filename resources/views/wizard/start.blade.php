<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Wizard · Piano editoriale
            </h2>
            <span class="text-sm text-gray-500">Step 1 / 2</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Dati del piano</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Dai un nome al piano e scegli il periodo. Poi passiamo allo style/brand.
                        </p>
                    </div>

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

                        {{-- ✅ FIX: questi campi servono alla validation (anche se sono Step 2) --}}
                        <input type="hidden" name="goal" value="{{ old('goal', 'Lead + Awareness + Autorità') }}">
                        <input type="hidden" name="tone" value="{{ old('tone', 'professionale') }}">
                        <input type="hidden" name="posts_per_week" value="{{ old('posts_per_week', 5) }}">
                        <input type="hidden" name="platforms[]" value="instagram">
                        <input type="hidden" name="formats[]" value="post">

                        {{-- NOME PIANO --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nome piano</label>
                            <input
                                type="text"
                                name="name"
                                value="{{ old('name', 'Piano February 2026') }}"
                                class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            />
                        </div>

                        {{-- DATE --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Data inizio</label>
                                <input
                                    type="date"
                                    name="start_date"
                                    value="{{ old('start_date') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Data fine</label>
                                <input
                                    type="date"
                                    name="end_date"
                                    value="{{ old('end_date') }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                />
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                Continua →
                            </button>
                        </div>
                    </form>

                    <p class="mt-4 text-xs text-gray-500">
                        Nota: abbiamo messo valori “di default” nascosti per sbloccare il flusso. Li sistemiamo nello Step 2.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
