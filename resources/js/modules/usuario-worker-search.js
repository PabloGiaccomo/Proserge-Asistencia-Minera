export function createUsuarioWorkerSearchLoader({ fetchWorkers = fetch, onResults, onError = () => {}, onBusy = () => {} }) {
    let pending;

    function cancel() {
        pending?.abort();
        pending = undefined;
        onBusy(false);
    }

    async function load(url) {
        cancel();
        const request = new AbortController();
        pending = request;
        onBusy(true);
        try {
            const response = await fetchWorkers(url, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            if (!response.ok || response.redirected) throw new Error('Búsqueda no disponible');

            const { trabajadores } = await response.json();
            if (!Array.isArray(trabajadores)) throw new Error('Respuesta de búsqueda no válida');
            if (pending === request) onResults(trabajadores);
        } catch {
            if (pending === request) onError();
        } finally {
            if (pending === request) {
                pending = undefined;
                onBusy(false);
            }
        }
    }

    return { load, cancel };
}

function resultCard(worker, isBatch) {
    const card = document.createElement('div');
    card.className = 'card';

    const body = document.createElement('div');
    body.className = 'card-body';
    Object.assign(body.style, { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '8px' });
    card.append(body);

    const identity = document.createElement('div');
    identity.style.cssText = 'min-width: 0; overflow-wrap: anywhere;';
    const name = document.createElement('strong');
    name.textContent = worker.nombre;
    const details = document.createElement('span');
    details.style.cssText = 'color: var(--color-text-secondary); font-size: 13px;';
    details.textContent = 'DNI: ' + worker.dni + ' · ' + (worker.puesto || 'Sin puesto');
    identity.append(name, document.createElement('br'), details);
    body.append(identity);

    if (worker.tiene_usuario) {
        const badge = document.createElement('span');
        badge.className = 'badge badge-danger';
        badge.textContent = 'Ya tiene usuario';
        body.append(badge);
    } else if (isBatch) {
        const pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'btn btn-sm btn-primary';
        pick.textContent = 'Agregar';
        pick.setAttribute('aria-label', 'Agregar a ' + worker.nombre);
        pick.dataset.userWorkerPick = '';
        pick.dataset.worker = JSON.stringify(worker);
        body.append(pick);
    } else {
        const pick = document.createElement('a');
        pick.href = worker.seleccionar_url;
        pick.className = 'btn btn-sm btn-primary';
        pick.textContent = 'Seleccionar';
        pick.setAttribute('aria-label', 'Seleccionar a ' + worker.nombre);
        body.append(pick);
    }

    return card;
}

export function initUsuarioWorkerSearch() {
    document.querySelectorAll('[data-user-worker-search]').forEach(root => {
        if (root.dataset.searchReady === '1') return;
        const input = root.querySelector('[data-user-worker-search-input]');
        const status = root.querySelector('[data-user-worker-search-status]');
        const results = root.querySelector('[data-user-worker-search-results]');
        const form = root.querySelector('[data-user-worker-search-form]');
        const isBatch = root.dataset.mode === 'lote';
        if (!input || !status || !results || !root.dataset.searchUrl) return;

        root.dataset.searchReady = '1';
        let timer;
        const loader = createUsuarioWorkerSearchLoader({
            onResults(workers) {
                results.replaceChildren(...workers.map(worker => resultCard(worker, isBatch)));
                status.textContent = workers.length === 0
                    ? 'No se encontraron trabajadores.'
                    : workers.length + (workers.length === 1 ? ' resultado.' : ' resultados.');
            },
            onError() {
                status.textContent = 'No se pudo buscar. Presiona Enter para reintentar.';
            },
            onBusy(busy) {
                results.setAttribute('aria-busy', String(busy));
                if (busy) status.textContent = 'Buscando trabajadores…';
            },
        });

        function search(delay = 0) {
            clearTimeout(timer);
            loader.cancel();
            results.replaceChildren();
            const value = input.value.trim();
            if (Array.from(value).length < 2) {
                status.textContent = 'Escribe al menos 2 caracteres.';
                return;
            }

            status.textContent = 'Buscando trabajadores…';
            const url = new URL(root.dataset.searchUrl, document.baseURI);
            url.searchParams.set('search', value);
            if (delay) timer = setTimeout(() => loader.load(url), delay);
            else loader.load(url);
        }

        input.addEventListener('input', event => {
            if (!event.isComposing) search(250);
        });
        input.addEventListener('compositionend', () => search(250));
        form?.addEventListener('submit', event => {
            event.preventDefault();
            search();
        });
        if (isBatch) {
            input.addEventListener('keydown', event => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                if (!event.isComposing) search();
            });
            results.addEventListener('click', event => {
                const button = event.target.closest('[data-user-worker-pick]');
                if (!button) return;
                root.dispatchEvent(new CustomEvent('usuario:trabajador', {
                    detail: JSON.parse(button.dataset.worker),
                    bubbles: true,
                }));
            });
        }
    });
}
