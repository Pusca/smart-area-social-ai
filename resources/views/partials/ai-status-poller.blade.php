{{-- Se in pagina ci sono item in coda/generazione, controlla lo stato ogni 5s.
     I badge vengono aggiornati sul posto; la pagina si ricarica UNA volta sola
     quando tutti gli item hanno finito (per mostrare caption e immagini). --}}
@php
    $aiStatusMeta = collect(\App\Enums\AiStatus::cases())->mapWithKeys(fn ($c) => [
        $c->value => ['label' => $c->label(), 'class' => $c->badgeClass(), 'busy' => $c->isBusy()],
    ]);
@endphp
<script>
    (() => {
        const badges = Array.from(document.querySelectorAll('[data-ai-status-badge][data-item-id]'));
        if (!badges.length || !badges.some(b => b.dataset.busy === '1')) return;

        const ids = [...new Set(badges.map(b => b.dataset.itemId))];

        const url = new URL(@json(route('ai.status')), window.location.origin);
        ids.forEach(id => url.searchParams.append('ids[]', id));

        const META = {!! $aiStatusMeta->toJson() !!};
        const BASE_CLASS = 'text-xs px-2 py-1 rounded-full whitespace-nowrap ';

        const tick = async () => {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const { items } = await res.json();
                    let stillBusy = 0;

                    badges.forEach(b => {
                        const it = items[b.dataset.itemId];
                        if (!it || !it.status) return;

                        if (b.dataset.status !== it.status && META[it.status]) {
                            b.dataset.status = it.status;
                            b.dataset.busy = META[it.status].busy ? '1' : '0';
                            b.className = BASE_CLASS + META[it.status].class;
                            b.textContent = 'AI: ' + META[it.status].label;
                        }

                        if (it.busy) stillBusy++;
                    });

                    if (stillBusy === 0) {
                        window.location.reload();
                        return;
                    }
                }
            } catch (e) { /* rete assente: riprova al prossimo giro */ }
            setTimeout(tick, 5000);
        };

        setTimeout(tick, 5000);
    })();
</script>
