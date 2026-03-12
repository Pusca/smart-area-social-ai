<x-guest-layout>
    <section class="space-y-8">
        <div class="overflow-hidden rounded-[2rem] border border-app bg-white/92 shadow-panel">
            <div class="grid gap-10 p-6 lg:grid-cols-[1.08fr_0.92fr] lg:items-center lg:p-9">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-brand bg-white px-3 py-1 text-xs font-semibold text-brand">
                        <x-application-logo variant="icon" class="h-6 w-6" />
                        Social AI
                    </div>
                    <h1 class="mt-5 max-w-2xl text-4xl font-semibold tracking-tight text-gray-900 sm:text-5xl">
                        Dai al tuo brand una presenza social più forte, più ordinata e molto più facile da gestire.
                    </h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-muted">
                        Social AI ti aiuta a trasformare idee, asset e obiettivi in contenuti pronti da approvare, migliorare e pubblicare con più continuità.
                        Il vantaggio non è solo creare prima: è avere una macchina che impara il tuo stile e ti fa lavorare con più controllo.
                    </p>

                    <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                        @auth
                            <a href="{{ route('dashboard') }}" class="ui-btn-primary px-5 py-3">
                                Apri il tuo workspace
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="ui-btn-primary px-5 py-3">
                                    Attiva Social AI
                                </a>
                            @endif
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="ui-btn-secondary px-5 py-3">
                                    Ho gia un account
                                </a>
                            @endif
                        @endauth
                    </div>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <span class="ui-chip">Più costanza editoriale</span>
                        <span class="ui-chip">Contenuti più coerenti col brand</span>
                        <span class="ui-chip">Un unico spazio per creare e gestire</span>
                    </div>
                </div>

                <div class="space-y-4 rounded-[1.75rem] border border-brand bg-[var(--gradient-soft)] p-5 shadow-card">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand">Primo impatto</p>
                            <h2 class="mt-2 text-xl font-semibold text-gray-900">Una presenza social che alza subito il livello</h2>
                        </div>
                        <x-application-logo variant="icon" class="h-16 w-16 shrink-0" />
                    </div>

                    <div class="space-y-3">
                        <div class="rounded-2xl border border-app bg-white/90 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand">Più velocità</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">Parti senza setup pesanti e vedi subito contenuti con un senso.</p>
                        </div>
                        <div class="rounded-2xl border border-app bg-white/90 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand">Più controllo</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">Approvi, correggi e fai evolvere la qualità contenuto dopo contenuto.</p>
                        </div>
                        <div class="rounded-2xl border border-app bg-white/90 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand">Più risultati</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900">Dai forma a un feed più forte, più leggibile e più vicino a quello che vuoi comunicare.</p>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-app bg-white/88 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand">Cosa ti porta</p>
                        <p class="mt-2 text-sm leading-6 text-muted">
                            Un'esperienza fluida, leggibile e concreta, pensata per aiutarti a pubblicare meglio e con più regolarità, senza perderti nei tecnicismi.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <article class="ui-card">
                <p class="ui-kicker">Più riconoscibilità</p>
                <h2 class="mt-2 text-base font-semibold text-gray-900">Un feed che sembra davvero il tuo</h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    Asset, immagini e preferenze diventano la base per contenuti più allineati al tuo brand e meno generici.
                </p>
            </article>
            <article class="ui-card">
                <p class="ui-kicker">Più qualità</p>
                <h2 class="mt-2 text-base font-semibold text-gray-900">Idee che prendono forma in modo più credibile</h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    Social AI lavora pensando a come i contenuti vengono davvero percepiti sui social, non come semplici materiali statici.
                </p>
            </article>
            <article class="ui-card">
                <p class="ui-kicker">Più continuità</p>
                <h2 class="mt-2 text-base font-semibold text-gray-900">Meno dispersione, più ritmo editoriale</h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    Dalla singola idea al piano editoriale, tutto resta più ordinato e più facile da portare avanti nel tempo.
                </p>
            </article>
        </div>

        <div class="ui-card p-6">
            <div class="grid gap-6 lg:grid-cols-[1.08fr_0.92fr] lg:items-center">
                <div>
                    <p class="ui-kicker">Perché funziona</p>
                    <h2 class="mt-2 text-2xl font-semibold text-gray-900">Ti aiuta a muoverti meglio sui social, senza complicarti il lavoro.</h2>
                    <div class="mt-5 grid gap-3">
                        <div class="rounded-2xl border border-app bg-surface-2 px-4 py-3 text-sm text-gray-700">Hai più chiarezza su cosa pubblicare e quando farlo.</div>
                        <div class="rounded-2xl border border-app bg-surface-2 px-4 py-3 text-sm text-gray-700">Riduci il tempo perso tra brief, correzioni e strumenti sparsi.</div>
                        <div class="rounded-2xl border border-app bg-surface-2 px-4 py-3 text-sm text-gray-700">Migliori coerenza visiva, tono e continuità del brand online.</div>
                        <div class="rounded-2xl border border-app bg-surface-2 px-4 py-3 text-sm text-gray-700">Costruisci una macchina che diventa più utile man mano che la usi.</div>
                    </div>
                </div>

                <div class="rounded-[1.75rem] border border-brand bg-white/88 p-5 shadow-card">
                    <x-application-logo class="h-11 w-auto" />
                    <h3 class="mt-5 text-lg font-semibold text-gray-900">Più valore percepito, più ordine, più contenuti che puoi usare davvero</h3>
                    <p class="mt-2 text-sm leading-6 text-muted">
                        Dal primo accesso alla gestione quotidiana, Social AI è pensato per darti subito la sensazione di una regia più solida, più chiara e più utile sui tuoi contenuti social.
                    </p>
                    <div class="mt-5 flex flex-col gap-2">
                        @auth
                            <a href="{{ route('dashboard') }}" class="ui-btn-primary">
                                Vai alla panoramica
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="ui-btn-primary">
                                    Crea il tuo account
                                </a>
                            @endif
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" class="ui-btn-secondary">
                                    Accedi al workspace
                                </a>
                            @endif
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-guest-layout>
