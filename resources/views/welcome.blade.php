<x-guest-layout>
    <div class="relative flex min-h-[calc(100vh-4rem)] items-center justify-center overflow-hidden">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(0,231,255,0.18),transparent_35%),radial-gradient(circle_at_80%_5%,rgba(106,92,255,0.18),transparent_32%),radial-gradient(circle_at_85%_80%,rgba(182,76,255,0.2),transparent_38%)]"></div>
        <div class="pointer-events-none absolute inset-0 opacity-30 [background-image:linear-gradient(rgba(11,15,26,0.08)_1px,transparent_1px),linear-gradient(90deg,rgba(11,15,26,0.08)_1px,transparent_1px)] [background-size:44px_44px]"></div>

        <section class="relative w-full max-w-3xl">
            <div class="rounded-[2.25rem] border border-white/60 bg-white/75 p-8 shadow-[0_28px_90px_-40px_rgba(11,15,26,0.7)] backdrop-blur-xl sm:p-12">
                <p class="text-center text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
                    Futuristic Content Studio
                </p>
                <h1 class="mt-4 text-center text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    Crea. Automatizza. Pubblica.
                </h1>

                <div class="mt-8 flex justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 980 220" role="img" aria-label="Social Ai logo" class="h-20 w-auto sm:h-24">
                        <defs>
                            <linearGradient id="homeTech" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#00E7FF"/>
                                <stop offset="0.55" stop-color="#6A5CFF"/>
                                <stop offset="1" stop-color="#B64CFF"/>
                            </linearGradient>
                            <filter id="homeSoftGlow" x="-40%" y="-40%" width="180%" height="180%">
                                <feGaussianBlur stdDeviation="2.6" result="b"/>
                                <feColorMatrix in="b" type="matrix"
                                    values="1 0 0 0 0
                                            0 1 0 0 0
                                            0 0 1 0 0
                                            0 0 0 .28 0" result="g"/>
                                <feMerge>
                                    <feMergeNode in="g"/>
                                    <feMergeNode in="SourceGraphic"/>
                                </feMerge>
                            </filter>
                        </defs>

                        <text x="490" y="140"
                            text-anchor="middle"
                            font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Inter, Roboto, Arial"
                            font-size="112"
                            font-weight="760"
                            letter-spacing="-2.4">
                            <tspan fill="#0B0F1A">Social </tspan>
                            <tspan fill="url(#homeTech)" filter="url(#homeSoftGlow)" font-weight="920">Ai</tspan>
                        </text>
                    </svg>
                </div>

                <div class="mt-10 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}"
                           class="inline-flex min-w-44 items-center justify-center rounded-2xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sa-ring)]">
                            Accedi
                        </a>
                    @endif

                    @if (Route::has('register'))
                        <a href="{{ route('register') }}"
                           class="inline-flex min-w-44 items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sa-ring)]">
                            Registrati
                        </a>
                    @endif
                </div>
            </div>
        </section>
    </div>
</x-guest-layout>
