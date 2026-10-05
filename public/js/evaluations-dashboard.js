(() => {
    let controller;
    async function refresh(form) {
        if (!form.reportValidity()) return;
        const root = document.getElementById('evaluation-dashboard');
        controller?.abort();
        controller = new AbortController();
        const current = controller;
        const url = new URL(form.action);
        new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        root.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: current.signal, headers: { 'X-Evaluation-Dashboard': '1', Accept: 'application/json' } });
            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                throw new Error(Object.values(body.errors || {}).flat().join(' ') || 'No se pudo actualizar el dashboard.');
            }
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = doc.getElementById('evaluation-dashboard');
            if (!next) throw new Error('La sesion puede haber vencido. Vuelve a ingresar.');
            if (current !== controller) return;
            root.replaceWith(next);
            history.replaceState(null, '', url);
        } catch (error) {
            if (error.name !== 'AbortError') root.querySelector('#ed-feedback').textContent = error.message;
        } finally { if (current === controller) root.removeAttribute('aria-busy'); }
    }
    document.addEventListener('submit', event => { if (event.target.id === 'ed-filters') { event.preventDefault(); refresh(event.target); } });
    document.addEventListener('change', event => {
        const form = event.target.closest('#ed-filters');
        if (!form) return;
        if (event.target.id === 'ed-exact') {
            if (!event.target.value) return;
            form.elements.desde.value = event.target.value;
            form.elements.hasta.value = event.target.value;
        }
        refresh(form);
    });
})();
