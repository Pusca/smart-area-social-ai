<x-guest-layout>
    <div class="mx-auto max-w-md ui-reveal">
        <div class="ui-card p-6 sm:p-7">
            <x-application-logo class="h-12 w-auto" />
            <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Conferma la tua email</h1>
            <div class="mt-3 text-sm leading-6 text-muted">
                Prima di iniziare, conferma il tuo indirizzo email dal link che ti abbiamo inviato. Se non lo trovi, puoi richiederne uno nuovo qui sotto.
            </div>

            @if (session('status') == 'verification-link-sent')
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-success">
                    Ti abbiamo inviato un nuovo link di verifica.
                </div>
            @endif

            <div class="mt-4 flex items-center justify-between gap-3">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <x-primary-button>
                        Invia di nuovo il link
                    </x-primary-button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md text-sm text-muted underline transition hover:text-text focus:outline-none focus:ring-2 focus:ring-primary">
                        Esci
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-guest-layout>
