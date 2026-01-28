<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Calendario editoriale
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700">
                    Qui costruiremo il calendario editoriale continuo (slot giornalieri, stati, approvazioni).
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">Slot di esempio</h3>
                    <p class="text-sm text-gray-600 mt-1">Mock UI (poi diventa dinamico).</p>

                    <div class="mt-4 space-y-3">
                        <div class="rounded-lg border p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold">Mer 29 Gen</span>
                                <span class="text-xs px-2 py-1 rounded-full bg-gray-100">Bozza</span>
                            </div>
                            <p class="text-sm text-gray-700 mt-2">Post “Chi siamo” (IG)</p>
                        </div>

                        <div class="rounded-lg border p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold">Gio 30 Gen</span>
                                <span class="text-xs px-2 py-1 rounded-full bg-gray-100">In revisione</span>
                            </div>
                            <p class="text-sm text-gray-700 mt-2">Reel “Dietro le quinte”</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">Prossimi step</h3>
                    <ul class="mt-3 list-disc pl-5 text-sm text-gray-700 space-y-1">
                        <li>Entità: CalendarSlot, Post, Platform</li>
                        <li>Stati: draft → review → approved → scheduled → published</li>
                        <li>UI: drag & drop + filtro per piattaforma</li>
                    </ul>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">Azioni rapide</h3>
                    <div class="mt-4 flex flex-col gap-2">
                        <button type="button" class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Genera nuovi slot (mock)
                        </button>
                        <button type="button" class="inline-flex justify-center rounded-lg border px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Vedi settimana (mock)
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-3">
                        (Questi bottoni ora non fanno nulla: li colleghiamo dopo.)
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
