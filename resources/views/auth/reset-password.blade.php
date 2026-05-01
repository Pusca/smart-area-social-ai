<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Scegli una nuova password
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Aggiorna l'accesso del tuo workspace e rientra nel flusso di lavoro.
        </p>

        <form method="POST" action="{{ route('password.store') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="ui-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                       required autofocus autocomplete="username"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <div>
                <label for="password" class="ui-label">Nuova password</label>
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

            <button type="submit"
                    style="display:flex;align-items:center;justify-content:center;padding:.75rem 1.5rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;letter-spacing:.01em;">
                Aggiorna password
            </button>
        </form>
    </div>
</div>
</x-guest-layout>
