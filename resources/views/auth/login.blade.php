<x-guest-layout>
    <div class="mx-auto max-w-md">
        <div class="rounded-3xl border bg-white shadow-sm overflow-hidden">
            <div class="border-b bg-gray-50 px-6 py-5">
                <div class="flex items-center gap-3">
                    <div>
                        <x-application-logo class="h-8 w-auto" />
                        <div class="text-xs text-gray-500">Accedi al workspace</div>
                    </div>
                </div>
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
                            class="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white hover:bg-gray-800">
                        {{ __('Accedi') }}
                    </button>

                    @if (Route::has('register'))
                        <div class="text-center text-sm text-gray-600">
                            Non hai un account?
                            <a class="underline hover:text-gray-900" href="{{ route('register') }}">Registrati</a>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="mt-4 text-center text-xs text-gray-500">
            Secure access · {{ config('app.name', 'Social AI') }}
        </div>
    </div>
</x-guest-layout>
