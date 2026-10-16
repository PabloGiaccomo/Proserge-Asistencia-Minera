export function createPersonalLiveResults({ fetchResults = fetch, onResults, onBusy = () => {}, onError = () => {} }) {
    let controller;
    let revision = 0;

    function cancel() {
        revision += 1;
        controller?.abort();
        onBusy(false);
    }

    async function load(url) {
        cancel();
        const requestRevision = revision;
        controller = new AbortController();
        onBusy(true);
        let failure;
        try {
            const response = await fetchResults(url.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok || response.redirected) throw new Error('No se pudo actualizar el listado. Reintenta la busqueda o revisa tu sesion.');
            const result = await response.json();
            if (requestRevision !== revision) return;
            if (typeof result.html !== 'string' || !result.pagination) throw new Error('No se pudo actualizar el listado. Intenta nuevamente.');
            onResults(result, url);
        } catch (error) {
            if (requestRevision === revision && error.name !== 'AbortError') failure = error.message;
        } finally {
            if (requestRevision === revision) {
                onBusy(false);
                if (failure) onError(failure);
            }
        }
    }

    return { load, cancel };
}
