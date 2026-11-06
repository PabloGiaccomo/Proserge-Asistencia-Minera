(() => {
    let controller;
    async function refresh(form, resetUrl = null) {
        if (!resetUrl && !form.reportValidity()) return;
        const root = document.getElementById('evaluation-dashboard');
        controller?.abort();
        controller = new AbortController();
        const current = controller;
        const url = new URL(resetUrl || form.action);
        if (!resetUrl) new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        const focusedId = root.contains(document.activeElement) ? document.activeElement.id : '';
        const moreFiltersOpen = root.querySelector('#ed-more-filters')?.open;
        root.querySelector('#ed-feedback').textContent = 'Actualizando resultados…';
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
            if (moreFiltersOpen && !resetUrl) next.querySelector('#ed-more-filters').open = true;
            root.replaceWith(next);
            history.replaceState(null, '', url);
            if (focusedId) document.getElementById(focusedId)?.focus({ preventScroll: true });
            next.querySelector('#ed-feedback').textContent = 'Resultados actualizados.';
        } catch (error) {
            if (error.name !== 'AbortError' && current === controller) root.querySelector('#ed-feedback').textContent = error.message;
        } finally {
            if (current === controller) {
                root.removeAttribute('aria-busy');
                controller = null;
            }
        }
    }
    document.addEventListener('input', event => {
        const form = event.target.closest('#ed-filters');
        if (!form || !['ed-search', 'ed-from', 'ed-to'].includes(event.target.id)) return;
        // A response for older filters must never discard edits still being typed.
        controller?.abort();
        controller = null;
        const root = form.closest('#evaluation-dashboard');
        root.removeAttribute('aria-busy');
        root.querySelector('#ed-feedback').textContent = 'Filtros modificados. Pulsa Buscar evaluaciones para actualizar.';
        if (event.target.id !== 'ed-search') form.querySelector('#ed-exact').value = '';
    });
    document.addEventListener('submit', event => { if (event.target.id === 'ed-filters') { event.preventDefault(); refresh(event.target); } });
    document.addEventListener('click', event => {
        const reset = event.target.closest('#ed-reset');
        if (!reset || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        refresh(document.getElementById('ed-filters'), reset.href);
    });
    document.addEventListener('change', event => {
        const form = event.target.closest('#ed-filters');
        if (!form) return;
        // Submit text and ranges together; changing the first date must not erase the second edit.
        if (['ed-search', 'ed-from', 'ed-to'].includes(event.target.id)) {
            if (event.target.id !== 'ed-search') form.querySelector('#ed-exact').value = '';
            return;
        }
        if (event.target.id === 'ed-exact') {
            if (!event.target.value) return;
            form.elements.desde.value = event.target.value;
            form.elements.hasta.value = event.target.value;
        }
        refresh(form);
    });
})();
