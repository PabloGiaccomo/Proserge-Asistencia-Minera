<script>
(() => {
    const root = document.querySelector('[data-transport-planning]');
    if (!root) return;

    const activeError = root.querySelector('[data-transport-error]:not([hidden])');
    if (activeError) activeError.focus();
    if (!window.fetch || !window.FormData) return;

    const pending = new WeakSet();
    const saved = new WeakSet();
    const dirty = new Set();
    if (activeError) dirty.add(activeError.closest('[data-transport-form]'));
    const uncertain = 'No se pudo comprobar si se guardó. Consulta el servicio antes de volver a enviar.';

    for (const eventName of ['input', 'change']) {
        root.addEventListener(eventName, event => {
            const form = event.target.closest('[data-transport-form]');
            if (form && !saved.has(form)) dirty.add(form);
        });
    }

    function showMessage(element, message, { review = false, success = false } = {}) {
        element.textContent = message;
        if (review) {
            const link = document.createElement('a');
            link.href = root.dataset.returnPath + location.search + '#transporte-servicios';
            link.textContent = 'Consultar servicios en otra pestaña';
            link.target = '_blank';
            link.rel = 'noopener';
            element.append(' ', link);
        }
        element.toggleAttribute('data-success', success);
        element.setAttribute('role', success ? 'status' : 'alert');
        element.hidden = false;
        element.focus();
    }

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('[data-transport-form]')) return;
        event.preventDefault();
        if (pending.has(form) || saved.has(form)) return;

        const fields = new FormData(form);
        const controls = [...form.querySelectorAll('input:not([type="hidden"]), select, textarea, button[type="submit"]')]
            .filter(control => !control.disabled);
        const errorBox = form.querySelector('[data-transport-error]');
        pending.add(form);
        dirty.add(form);
        form.setAttribute('aria-busy', 'true');
        controls.forEach(control => { control.disabled = true; });
        errorBox.hidden = true;
        let redirecting = false;

        try {
            const response = await fetch(form.action, {
                method: form.method.toUpperCase(),
                body: fields,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                redirect: 'error',
            });
            if ([401, 403, 419].includes(response.status)) {
                showMessage(errorBox, response.status === 403
                    ? 'Tu usuario no tiene permiso para completar esta acción.'
                    : 'Tu sesión venció. Vuelve a iniciar sesión en otra pestaña para conservar lo escrito.');
                return;
            }
            if (!response.headers.get('content-type')?.includes('application/json')) {
                showMessage(errorBox, uncertain, { review: true });
                return;
            }
            const result = await response.json();
            if (response.status === 422) {
                const details = Object.values(result.errors ?? {}).flat().filter(item => typeof item === 'string');
                showMessage(errorBox, details.join(' ') || result.message || 'Revisa los datos ingresados.');
                return;
            }
            const destination = typeof result.redirect === 'string' ? new URL(result.redirect, location.href) : null;
            if (!response.ok || result.ok !== true || !destination
                || destination.origin !== location.origin || destination.pathname !== root.dataset.returnPath) {
                showMessage(errorBox, uncertain, { review: true });
                return;
            }
            dirty.delete(form);
            if (dirty.size > 0) {
                saved.add(form);
                showMessage(errorBox, 'Guardado. Puedes consultar los servicios sin perder lo escrito en el otro formulario.', { review: true, success: true });
                return;
            }
            location.assign(destination.href);
            redirecting = true;
        } catch {
            showMessage(errorBox, uncertain, { review: true });
        } finally {
            if (!redirecting) {
                form.removeAttribute('aria-busy');
                if (!saved.has(form)) controls.forEach(control => { control.disabled = false; });
                pending.delete(form);
            }
        }
    });
})();
</script>
