@push('scripts')
<script>
(function () {
    const button = document.getElementById('mobMarkBtn');
    if (!button) return;

    const feedback = document.getElementById('mobMarkFeedback');
    const checkStatus = document.getElementById('mobCheckStatus');
    const login = document.getElementById('mobLogin');
    const reload = document.getElementById('mobReload');
    const record = document.getElementById('mobRecord');
    const states = @json($estados);
    const headers = { Accept: 'application/json' };
    let pending = false;

    function showMessage(message, tone = 'warning') {
        feedback.className = 'mob-notice ' + tone;
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.focus();
    }

    function showSessionExpired() {
        login.hidden = false;
        reload.hidden = false;
        showMessage('Tu sesión necesita actualizarse. Inicia sesión y recarga esta jornada para continuar.');
    }

    function updateRecord(state, hour) {
        record.textContent = states[state] + (hour && hour !== '00:00:00' ? ' · ' + hour.slice(0, 5) : '');
    }

    async function reconcile(message) {
        checkStatus.hidden = true;
        try {
            const response = await fetch(button.dataset.statusUrl, { headers, cache: 'no-store' });
            if (response.status === 401 || response.status === 419) {
                showSessionExpired();
                return;
            }
            if (response.status === 403 || response.status === 404) {
                showMessage('El grupo ya no está disponible para tu cuenta. Revisa los grupos de la jornada.');
                return;
            }

            const data = await response.json();
            if (!response.ok || typeof data.puede_marcar !== 'boolean') throw new Error();
            const own = data.propio;
            if (own?.id !== button.dataset.detailId || !Object.hasOwn(states, own.estado)) {
                showMessage(data.mensaje || 'Tu asignación cambió. Recarga esta jornada para consultar el grupo.');
                reload.hidden = false;
                return;
            }

            updateRecord(own.estado, own.hora);
            if (['PRESENTE', 'TARDANZA'].includes(own.estado)) {
                button.hidden = true;
                showMessage('Marca confirmada. Tu registro figura como ' + states[own.estado].toLowerCase() + '.', 'success');
                return;
            }
            if (!data.puede_marcar || !['PENDIENTE', 'AUSENTE'].includes(own.estado)) {
                button.hidden = true;
                showMessage(data.mensaje || 'Tu registro figura como ' + states[own.estado].toLowerCase() + '.');
                return;
            }

            button.disabled = false;
            showMessage(message || 'La marca no figura registrada. Puedes volver a intentarlo.');
        } catch {
            checkStatus.hidden = false;
            showMessage('No pudimos verificar la marca. Consulta su estado antes de volver a marcar.');
        }
    }

    button.addEventListener('click', async function () {
        if (pending || button.disabled) return;
        pending = true;
        button.disabled = true;
        showMessage('Consultando el registro…', 'info');

        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    ...headers,
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ grupo_trabajo_detalle_id: button.dataset.detailId, estado: 'PRESENTE' }),
            });

            if (response.status === 401 || response.status === 419) {
                showSessionExpired();
                return;
            }

            const data = await response.json().catch(() => ({}));
            if (response.status === 422) {
                showMessage(Object.values(data.errors || {}).flat()[0] || data.message || 'Revisa los datos de la marca y vuelve a intentar.');
                button.disabled = false;
            } else if (response.ok && data.ok === true && ['PRESENTE', 'TARDANZA'].includes(data.estado)) {
                updateRecord(data.estado, data.hora_marcado);
                button.hidden = true;
                showMessage('Asistencia registrada.', 'success');
            } else {
                await reconcile([403, 404, 409].includes(response.status) ? data.message : null);
            }
        } catch {
            await reconcile();
        } finally {
            pending = false;
        }
    });

    checkStatus.addEventListener('click', async function () {
        if (pending) return;
        pending = true;
        try {
            await reconcile();
        } finally {
            pending = false;
        }
    });
    reload.addEventListener('click', function () { window.location.reload(); });
})();
</script>
@endpush
