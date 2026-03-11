<x-guest-layout>
    <div class="mx-auto max-w-md ui-reveal">
        <div class="ui-card p-6 sm:p-7">
            <x-application-logo class="h-10 w-auto" />
            <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Conferma la password</h1>
            <div class="mt-3 text-sm leading-6 text-muted">
                Questa e un'area protetta. Inserisci la password per continuare.
            </div>

            <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="flex justify-end">
                    <x-primary-button>
                        Conferma
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-guest-layout>
