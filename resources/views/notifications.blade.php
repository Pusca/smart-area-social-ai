<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Notifiche
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700">
                    Questo sarà il centro notifiche interno. Più avanti lo colleghiamo alle Push (PWA).
                </p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-900">Attività recente (mock)</h3>

                <div class="mt-4 space-y-3">
                    <div class="rounded-lg border p-4">
                        <p class="font-semibold text-gray-900">Post pronto per approvazione</p>
                        <p class="text-sm text-gray-600 mt-1">“Reel Automazioni & AI” • 2 min fa</p>
                    </div>

                    <div class="rounded-lg border p-4">
                        <p class="font-semibold text-gray-900">Pubblicazione completata</p>
                        <p class="text-sm text-gray-600 mt-1">“Story Case Study” • ieri</p>
                    </div>

                    <div class="rounded-lg border p-4">
                        <p class="font-semibold text-gray-900">Connessione social da rinnovare</p>
                        <p class="text-sm text-gray-600 mt-1">Instagram token scaduto • 3 giorni fa</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
