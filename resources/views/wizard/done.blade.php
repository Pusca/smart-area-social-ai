<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Wizard · Completato
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-xl border border-gray-100 overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="flex items-start gap-4">
                        <div class="mt-1 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 text-green-700">
                            ✓
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-900">Piano generato!</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Il piano è stato creato e inserito nel calendario. Ora puoi modificare i contenuti e aggiungere media (immagini/video).
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-col sm:flex-row gap-3">
                        <a
                            href="{{ route('calendar') }}"
                            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            Vai al calendario
                        </a>

                        <a
                            href="{{ route('posts') }}"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50"
                        >
                            Gestisci contenuti
                        </a>

                        <a
                            href="{{ route('ai') }}"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50"
                        >
                            AI Lab
                        </a>
                    </div>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-500">
                Prossimo step: generazione immagini/video + collegamento account social.
            </p>
        </div>
    </div>
</x-app-layout>
