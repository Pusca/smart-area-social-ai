{{-- Se in pagina ci sono item in coda/generazione, controlla lo stato ogni 5s e ricarica quando cambia qualcosa. --}}
<script>
    (() => {
        const badges = Array.from(document.querySelectorAll('[data-ai-status-badge][data-item-id]'));
        if (!badges.length || !badges.some(b => b.dataset.busy === '1')) return;

        const ids = badges.map(b => b.dataset.itemId);
        const initial = ids.map(id => badges.find(b => b.dataset.itemId === id)?.dataset.status ?? '').join(',');

        const url = new URL(@json(route('ai.status')), window.location.origin);
        ids.forEach(id => url.searchParams.append('ids[]', id));

        const tick = async () => {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const { items } = await res.json();
                    const current = ids.map(id => items[id]?.status ?? '').join(',');
                    if (current !== initial) {
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
