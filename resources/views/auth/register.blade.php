<x-guest-layout>
    <div class="mx-auto max-w-md">
        <div class="overflow-hidden rounded-[2rem] border border-app bg-white/94 shadow-panel">
            <div class="border-b border-app bg-[var(--gradient-soft)] px-6 py-6">
                <x-application-logo class="h-10 w-auto" />
                <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Crea il tuo workspace</h1>
                <p class="mt-2 text-sm leading-6 text-muted">
                    Parti in pochi minuti e inizia a costruire una presenza social più chiara, più continua e più semplice da gestire.
                </p>
            </div>

            <div class="px-6 py-6">
                <form method="POST" action="{{ route('register') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" :value="__('Nome')" />
                        <x-text-input
                            id="name"
                            class="mt-1 block w-full rounded-xl"
                            type="text"
                            name="name"
                            :value="old('name')"
                            required
                            autofocus
                            autocomplete="name"
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input
                            id="email"
                            class="mt-1 block w-full rounded-xl"
                            type="email"
                            name="email"
                            :value="old('email')"
                            required
                            autocomplete="username"
                        />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input
                            id="password"
                            class="mt-1 block w-full rounded-xl"
                            type="password"
                            name="password"
                            required
                            autocomplete="new-password"
                        />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="password_confirmation" :value="__('Conferma password')" />
                        <x-text-input
                            id="password_confirmation"
                            class="mt-1 block w-full rounded-xl"
                            type="password"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                        />
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>

                    <div class="rounded-2xl border border-brand bg-brand-soft px-3 py-3 text-xs leading-5 text-brand">
                        Alla registrazione viene creato automaticamente uno spazio dedicato. Poi ti guidiamo nel primo setup del brand e nella demo iniziale.
                    </div>

                    <button
                        type="submit"
                        class="ui-btn-primary w-full justify-center py-3"
                    >
                        {{ __('Crea account') }}
                    </button>

                    <div class="text-center text-sm text-muted">
                        Hai gia un account?
                        <a class="underline hover:text-gray-900" href="{{ route('login') }}">{{ __('Accedi') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-4 text-center text-xs text-muted">
            Accesso protetto e setup guidato con {{ config('app.name', 'Social AI') }}
        </div>
    </div>
</x-guest-layout>
