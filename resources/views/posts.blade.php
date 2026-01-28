<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Post
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700">
                    Qui gestiremo bozze, varianti, copy, asset, e pubblicazioni (prima manuali, poi automatiche).
                </p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900">Lista contenuti (mock)</h3>
                        <p class="text-sm text-gray-600">Poi diventa una tabella filtrabile.</p>
                    </div>

                    <button type="button" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        + Nuovo post
                    </button>
                </div>

                <div class="mt-5 divide-y">
                    <div class="py-4 flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-900">Post “Servizi Smartera”</p>
                            <p class="text-sm text-gray-600">Piattaforma: Instagram • Formato: Carousel</p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100">Draft</span>
                    </div>

                    <div class="py-4 flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-900">Reel “Automazioni & AI”</p>
                            <p class="text-sm text-gray-600">Piattaforma: TikTok • Formato: Video</p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100">Review</span>
                    </div>

                    <div class="py-4 flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-900">Story “Case Study”</p>
                            <p class="text-sm text-gray-600">Piattaforma: Instagram • Formato: Story</p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100">Scheduled</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
