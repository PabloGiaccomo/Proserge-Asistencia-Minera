<dialog id="update-request-dialog" style="width:min(100% - 2rem, 38rem); padding:1.25rem; border:1px solid #dbe1ea; border-radius:.75rem;">
    <h2 class="ficha-card-title">Solicitar actualización</h2>
    <p>El trabajador podrá proponer cambios de datos personales, nacimiento, contacto, domicilio, estudios, tallas y nuevos familiares. El enlace dura 72 horas y debe compartirse por un canal directo.</p>
    <div class="ficha-actions-bar" style="justify-content:flex-start;">
        <button class="btn btn-primary" type="button" id="issue-update-link" data-url="{{ route('personal.actualizacion.issue', $trabajador['id']) }}">Generar enlace</button>
        <button class="btn btn-outline" type="button" id="close-update-request">Cerrar</button>
    </div>
    <div id="update-request-result" hidden>
        <label class="ficha-label" for="update-link-value">Enlace para compartir</label>
        <input class="ficha-input" id="update-link-value" readonly autocomplete="off">
        <button class="btn btn-outline" type="button" id="copy-update-link">Copiar enlace</button>
    </div>
    <p id="update-request-status" role="status" aria-live="polite"></p>
    <div id="active-update-links">
        @foreach($activeUpdateLinks as $link)
            <p>
                Enlace vigente hasta {{ $link->expires_at->format('d/m/Y H:i') }}
                <button class="btn btn-outline btn-sm" type="button" data-revoke-url="{{ route('personal.actualizacion.revoke', $link->id) }}">Revocar enlace</button>
            </p>
        @endforeach
    </div>
</dialog>
<script>
    (() => {
        const dialog = document.getElementById('update-request-dialog');
        const issue = document.getElementById('issue-update-link');
        const result = document.getElementById('update-request-result');
        const linkInput = document.getElementById('update-link-value');
        const status = document.getElementById('update-request-status');
        const activeLinks = document.getElementById('active-update-links');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const revokeRoute = '{{ route('personal.actualizacion.revoke', ['linkId' => '__link__']) }}';

        async function send(url) {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'No se pudo completar la acción.');
            return payload;
        }

        document.getElementById('open-update-request').addEventListener('click', () => dialog.showModal());
        document.getElementById('close-update-request').addEventListener('click', () => dialog.close());
        issue.addEventListener('click', async () => {
            issue.disabled = true;
            try {
                const link = await send(issue.dataset.url);
                linkInput.value = link.url;
                result.hidden = false;
                result.dataset.linkId = link.link_id;
                const line = document.createElement('p');
                const revoke = document.createElement('button');
                revoke.type = 'button';
                revoke.className = 'btn btn-outline btn-sm';
                revoke.textContent = 'Revocar enlace';
                revoke.dataset.revokeUrl = revokeRoute.replace('__link__', link.link_id);
                revoke.dataset.linkId = link.link_id;
                line.append('Enlace nuevo · 72 horas ', revoke);
                activeLinks.prepend(line);
                status.textContent = 'Enlace listo para copiar.';
            } catch (error) {
                status.textContent = error.message;
            } finally {
                issue.disabled = false;
            }
        });

        document.getElementById('copy-update-link').addEventListener('click', async () => {
            linkInput.select();
            try {
                await navigator.clipboard.writeText(linkInput.value);
                status.textContent = 'Enlace copiado.';
            } catch {
                status.textContent = 'Copia el enlace seleccionado.';
            }
        });
        activeLinks.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-revoke-url]');
            if (!button) return;
            button.disabled = true;
            try {
                await send(button.dataset.revokeUrl);
                button.closest('p').remove();
                if (button.dataset.linkId && button.dataset.linkId === result.dataset.linkId) {
                    result.hidden = true;
                    linkInput.value = '';
                }
                status.textContent = 'Enlace revocado.';
            } catch (error) {
                button.disabled = false;
                status.textContent = error.message;
            }
        });
    })();
</script>
