<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Crea il tuo workspace
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Parti in pochi minuti e inizia a costruire una presenza social più chiara e continua.
        </p>

        <form method="POST" action="{{ route('register') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            @csrf

            <div>
                <label for="name" class="ui-label">Nome</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}"
                       required autofocus autocomplete="name"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <label for="email" class="ui-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autocomplete="username"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <div>
                <label for="password" class="ui-label">Password</label>
                <input id="password" type="password" name="password"
                       required autocomplete="new-password"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div>
                <label for="password_confirmation" class="ui-label">Conferma password</label>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       required autocomplete="new-password"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
            </div>

            <div style="border-radius:1rem;border:1px solid rgba(10,45,111,.15);background:rgba(239,245,255,.7);padding:.75rem 1rem;font-size:.8rem;color:#0A2D6F;line-height:1.5;">
                Alla registrazione viene creato automaticamente uno spazio dedicato. Poi ti guidiamo nel primo setup del brand.
            </div>

            <button type="submit"
                    style="display:flex;align-items:center;justify-content:center;padding:.75rem 1.5rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;letter-spacing:.01em;">
                Crea account
            </button>

            <p style="text-align:center;font-size:.8125rem;color:#64748b;">
                Hai già un account?
                <a href="{{ route('login') }}" style="color:#0A2D6F;font-weight:600;text-decoration:none;">
                    Accedi
                </a>
            </p>
        </form>
    </div>

    <p style="text-align:center;font-size:.7rem;color:#94a3b8;margin-top:.75rem;letter-spacing:.02em;">
        Setup guidato · {{ config('app.name', 'Social AI') }}
    </p>
</div>
</x-guest-layout>
