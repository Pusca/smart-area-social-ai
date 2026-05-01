<x-guest-layout>
<div class="mx-auto max-w-md">
    <div style="background:#fff;border:1px solid rgba(10,45,111,.1);border-radius:1.5rem;padding:2rem 2rem 1.75rem;">

        <x-application-logo class="h-12 w-auto" />

        <h1 style="margin-top:1.25rem;font-size:1.25rem;font-weight:700;color:#07183F;letter-spacing:-.01em;">
            Conferma la tua email
        </h1>
        <p style="margin-top:.375rem;font-size:.875rem;color:#64748b;line-height:1.5;">
            Prima di iniziare, conferma il tuo indirizzo email dal link che ti abbiamo inviato. Se non lo trovi, richiedine uno nuovo qui sotto.
        </p>

        @if (session('status') == 'verification-link-sent')
            <div style="margin-top:1rem;border-radius:.875rem;border:1px solid rgba(34,197,94,.25);background:rgba(240,253,244,.8);padding:.625rem 1rem;font-size:.8125rem;color:#166534;">
                Ti abbiamo inviato un nuovo link di verifica.
            </div>
        @endif

        <div style="margin-top:1.5rem;display:flex;align-items:center;gap:1rem;">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                        style="display:inline-flex;align-items:center;padding:.625rem 1.25rem;background:#0A2D6F;color:#fff;font-size:.875rem;font-weight:600;border-radius:.875rem;border:none;cursor:pointer;">
                    Invia di nuovo il link
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        style="font-size:.8125rem;color:#64748b;background:none;border:none;cursor:pointer;text-decoration:underline;padding:0;">
                    Esci
                </button>
            </form>
        </div>
    </div>
</div>
</x-guest-layout>
