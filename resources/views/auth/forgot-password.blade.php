<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Recupera l'accesso
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Inserisci la tua email e ti inviamo il link per scegliere una nuova password.
        </p>

        <x-auth-session-status class="mt-4" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            @csrf

            <div>
                <label for="email" class="ui-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <button type="submit"
                    style="display:flex;align-items:center;justify-content:center;padding:.75rem 1.5rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;letter-spacing:.01em;">
                Invia link di recupero
            </button>

            <p style="text-align:center;font-size:.8125rem;color:#64748b;">
                <a href="{{ route('login') }}" style="color:#0A2D6F;font-weight:600;text-decoration:none;">
                    ← Torna al login
                </a>
            </p>
        </form>
    </div>
</div>
</x-guest-layout>
