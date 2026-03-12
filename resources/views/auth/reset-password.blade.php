<x-guest-layout>
    <div class="mx-auto max-w-md ui-reveal">
        <div class="ui-card p-6 sm:p-7">
            <x-application-logo class="h-14 w-auto" />
            <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Scegli una nuova password</h1>
            <p class="mt-3 text-sm leading-6 text-muted">
                Aggiorna l'accesso del tuo workspace e rientra subito nel flusso di lavoro.
            </p>

            <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                    <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button>
                        Aggiorna password
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-guest-layout>
