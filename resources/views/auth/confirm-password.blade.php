<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Conferma la password
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Questa è un'area protetta. Inserisci la password per continuare.
        </p>

        <form method="POST" action="{{ route('password.confirm') }}" style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            @csrf

            <div>
                <label for="password" class="ui-label">Password</label>
                <input id="password" type="password" name="password"
                       required autocomplete="current-password"
                       class="ui-input mt-1">
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit"
                        style="display:inline-flex;align-items:center;padding:.625rem 1.5rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;letter-spacing:.01em;">
                    Conferma
                </button>
            </div>
        </form>
    </div>
</div>
</x-guest-layout>
