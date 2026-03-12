<x-guest-layout>
    <div class="mx-auto max-w-md ui-reveal">
        <div class="ui-card p-6 sm:p-7">
            <x-application-logo class="h-12 w-auto" />
            <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Recupera l'accesso</h1>
            <div class="mt-3 text-sm leading-6 text-muted">
                Inserisci la tua email e ti inviamo il link per scegliere una nuova password.
            </div>

            <x-auth-session-status class="mb-4" :status="session('status')" />

            <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button>
                        Invia link di recupero
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-guest-layout>
