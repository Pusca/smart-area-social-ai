<x-app-layout>
    <div class="py-6">
        <div class="ui-container max-w-3xl">
            <div class="ui-card">
                <div class="ui-card-b">
                    <h1 class="ui-h1">Bozze generate ✅</h1>
                    <p class="ui-sub">Ho creato un piano e ho distribuito i contenuti nella settimana scelta.</p>

                    <div class="mt-4 flex flex-col sm:flex-row gap-2">
                        <a href="{{ route('calendar') }}" class="ui-btn ui-btn-primary">Vai al calendario</a>
                        <a href="{{ route('posts') }}" class="ui-btn ui-btn-ghost">Lista contenuti</a>
                        <a href="{{ route('wizard.start') }}" class="ui-btn ui-btn-ghost">Crea un altro piano</a>
                    </div>

                    @if($planId)
                        <div class="mt-4 text-xs text-gray-500">
                            Plan ID: <span class="font-mono">{{ $planId }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
