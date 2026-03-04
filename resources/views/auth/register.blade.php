<x-guest-layout>
    <div class="mx-auto max-w-md">
        <div class="overflow-hidden rounded-3xl border bg-white shadow-sm">
            <div class="border-b bg-gray-50 px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-2xl bg-gray-900 font-bold tracking-tight text-white">
                        SA
                    </div>
                    <div>
                        <div class="text-sm font-semibold">{{ config('app.name', 'Social AI') }}</div>
                        <div class="text-xs text-gray-500">Crea il tuo workspace</div>
                    </div>
                </div>
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

                    <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-800">
                        Alla registrazione viene creato automaticamente un tenant/workspace dedicato.
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white hover:bg-gray-800"
                    >
                        {{ __('Crea account') }}
                    </button>

                    <div class="text-center text-sm text-gray-600">
                        Hai gia un account?
                        <a class="underline hover:text-gray-900" href="{{ route('login') }}">{{ __('Accedi') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-4 text-center text-xs text-gray-500">
            Secure access - {{ config('app.name', 'Social AI') }}
        </div>
    </div>
</x-guest-layout>
