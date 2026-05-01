<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Rientra e riprendi il ritmo
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Ritrovi subito calendario, contenuti e approvazioni in uno spazio ordinato.
        </p>

        <x-auth-session-status class="mt-4" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            @csrf

            <div>
                <label for="email" class="ui-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <div>
                <label for="password" class="ui-label">Password</label>
                <input id="password" type="password" name="password"
                       required autocomplete="current-password"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                <label style="display:inline-flex;align-items:center;gap:.5rem;cursor:pointer;">
                    <input type="checkbox" name="remember"
                           style="width:1rem;height:1rem;accent-color:#0A2D6F;border-radius:.25rem;">
                    <span style="font-size:.8125rem;color:#64748b;">Ricordami</span>
                </label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}"
                       style="font-size:.8125rem;color:#64748b;text-decoration:underline;">
                        Password dimenticata?
                    </a>
                @endif
            </div>

            <button type="submit"
                    style="display:flex;align-items:center;justify-content:center;padding:.75rem 1.5rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;letter-spacing:.01em;margin-top:.25rem;">
                Accedi
            </button>

            @if (Route::has('register'))
                <p style="text-align:center;font-size:.8125rem;color:#64748b;">
                    Non hai un account?
                    <a href="{{ route('register') }}" style="color:#0A2D6F;font-weight:600;text-decoration:none;">
                        Crea il tuo workspace
                    </a>
                </p>
            @endif
        </form>
    </div>

    <p style="text-align:center;font-size:.7rem;color:#94a3b8;margin-top:.75rem;letter-spacing:.02em;">
        Accesso protetto · {{ config('app.name', 'Social AI') }}
    </p>
</div>
</x-guest-layout>
