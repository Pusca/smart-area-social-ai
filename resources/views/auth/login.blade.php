<x-guest-layout>
    <div class="mx-auto max-w-md">
        <div class="overflow-hidden rounded-[2rem] border border-app bg-white/94 shadow-panel">
            <div class="border-b border-app bg-[var(--gradient-soft)] px-6 py-6">
                <x-application-logo class="h-12 w-auto" />
                <h1 class="mt-5 text-2xl font-semibold tracking-tight text-gray-900">Rientra e riprendi il ritmo dei tuoi contenuti</h1>
                <p class="mt-2 text-sm leading-6 text-muted">
                    Ritrovi subito calendario, contenuti e approvazioni in uno spazio ordinato e facile da leggere.
                </p>
            </div>

            <div class="px-6 py-6">
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input id="email"
                                      class="mt-1 block w-full rounded-xl"
                                      type="email"
                                      name="email"
                                      :value="old('email')"
                                      required autofocus autocomplete="username" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password"
                                      class="mt-1 block w-full rounded-xl"
                                      type="password"
                                      name="password"
                                      required autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-between">
                        <label for="remember_me" class="inline-flex items-center gap-2">
                            <input id="remember_me" type="checkbox"
                                   class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                   name="remember">
                            <span class="text-sm text-gray-600">{{ __('Ricordami') }}</span>
                        </label>

                        @if (Route::has('password.request'))
                            <a class="text-sm text-gray-600 hover:text-gray-900 underline"
                               href="{{ route('password.request') }}">
                                {{ __('Password dimenticata?') }}
                            </a>
                        @endif
                    </div>

                    <button type="submit"
                            class="ui-btn-primary w-full justify-center py-3">
                        {{ __('Accedi') }}
                    </button>

                    @if (Route::has('register'))
                        <div class="text-center text-sm text-muted">
                            Non hai un account?
                            <a class="underline hover:text-gray-900" href="{{ route('register') }}">Crea il tuo workspace</a>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="mt-4 text-center text-xs text-muted">
            Accesso protetto al tuo workspace {{ config('app.name', 'Social AI') }}
        </div>
    </div>
</x-guest-layout>
