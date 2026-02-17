<x-guest-layout>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
        {{-- Left --}}
        <div class="pt-2">
            <div class="inline-flex items-center gap-2 rounded-full border bg-white px-3 py-1 text-xs text-gray-600">
                <span class="h-2 w-2 rounded-full bg-green-500"></span>
                Social AI · Workspace
            </div>

            <h1 class="mt-6 text-3xl sm:text-4xl font-bold tracking-tight">
                Gestisci contenuti e piani
                <span class="text-gray-500">in modo semplice.</span>
            </h1>

            <p class="mt-3 text-gray-600 max-w-xl">
                Accedi al workspace per creare piani, post e gestire la coda AI.
            </p>

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                @if (Route::has('login'))
                    <a href="{{ route('login') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white hover:bg-gray-800">
                        Accedi
                    </a>
                @endif

                @if (Route::has('register'))
                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center rounded-xl border bg-white px-5 py-3 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                        Crea account
                    </a>
                @endif
            </div>

            <div class="mt-10 grid grid-cols-1 sm:grid-cols-3 gap-3 max-w-xl">
                <div class="rounded-2xl border bg-white p-4">
                    <div class="text-xs text-gray-500">Piani</div>
                    <div class="mt-1 text-sm font-semibold">Wizard rapido</div>
                </div>
                <div class="rounded-2xl border bg-white p-4">
                    <div class="text-xs text-gray-500">Post</div>
                    <div class="mt-1 text-sm font-semibold">CRUD & preview</div>
                </div>
                <div class="rounded-2xl border bg-white p-4">
                    <div class="text-xs text-gray-500">AI</div>
                    <div class="mt-1 text-sm font-semibold">Queue ready</div>
                </div>
            </div>
        </div>

        {{-- Right: app preview --}}
        <div class="rounded-3xl border bg-white shadow-sm overflow-hidden">
            <div class="border-b bg-gray-50 px-5 py-4 flex items-center justify-between">
                <div class="text-sm font-semibold">Preview</div>
                <div class="text-xs text-gray-500">Dashboard</div>
            </div>

            <div class="p-5 space-y-4">
                <div class="rounded-2xl border bg-white p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs text-gray-500">Ultimo piano</div>
                            <div class="mt-1 font-semibold">Prossima settimana</div>
                        </div>
                        <span class="text-xs rounded-full border bg-gray-50 px-2 py-1 text-gray-600">draft</span>
                    </div>
                </div>

                <div class="rounded-2xl border bg-white p-4">
                    <div class="text-xs text-gray-500">Coda AI</div>
                    <div class="mt-2 h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full w-2/3 bg-gray-900"></div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">In lavorazione</div>
                </div>

                <div class="rounded-2xl border bg-white p-4">
                    <div class="text-xs text-gray-500">Ultimi post</div>
                    <div class="mt-3 space-y-2">
                        <div class="flex items-center justify-between rounded-xl border bg-gray-50 px-3 py-2">
                            <div class="text-sm font-medium">Instagram · Post</div>
                            <span class="text-xs text-gray-500">queued</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border bg-gray-50 px-3 py-2">
                            <div class="text-sm font-medium">LinkedIn · Post</div>
                            <span class="text-xs text-gray-500">draft</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border bg-gray-50 px-3 py-2">
                            <div class="text-sm font-medium">TikTok · Reel</div>
                            <span class="text-xs text-gray-500">done</span>
                        </div>
                    </div>
                </div>

                <div class="text-xs text-gray-500">
                    Stile app: pulito, leggibile, senza effetti.
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
