export function initUsuarioAltaLote(root = document) {
    root.querySelectorAll('[data-usuario-alta-lote]').forEach((workspace) => {
        if (workspace.dataset.loteReady) return;

        const search = workspace.querySelector('[data-user-worker-search]');
        const form = workspace.querySelector('[data-lote-form]');
        const list = workspace.querySelector('[data-lote-rows]');
        const template = workspace.querySelector('[data-lote-template]');
        const empty = workspace.querySelector('[data-lote-empty]');
        const submit = workspace.querySelector('[data-lote-submit]');
        const jump = workspace.querySelector('[data-lote-jump]');
        const feedback = workspace.querySelector('[data-lote-feedback]');
        const maxRows = Number(workspace.dataset.maxFilas);

        if (!search || !form || !list || !template || !empty || !submit || !jump || !feedback || !Number.isInteger(maxRows) || maxRows < 1) return;

        workspace.dataset.loteReady = 'true';
        const rows = () => Array.from(list.querySelectorAll('[data-lote-row]'));
        const role = form.querySelector('select[name="rol_id"]');
        let nextIndex = rows().reduce((next, row) => {
            const field = row.querySelector('[data-lote-personal]');
            const index = Number(field.name.match(/^filas\[(\d+)\]/)?.[1] ?? 0);
            return Math.max(next, index + 1);
        }, 0);

        function updateSelection() {
            const count = rows().length;
            empty.hidden = count > 0;
            submit.disabled = count === 0 || role?.options.length === 1;
            submit.textContent = count === 1 ? 'Crear 1 usuario' : `Crear ${count} usuarios`;
            jump.textContent = `Ver selección (${count})`;
        }

        search.addEventListener('usuario:trabajador', ({ detail: worker }) => {
            if (!worker?.id) return;

            if (worker.tiene_usuario) {
                feedback.textContent = 'Este trabajador ya tiene un usuario.';
                return;
            }

            const selected = rows();
            if (selected.some((row) => row.querySelector('[data-lote-personal]').value === worker.id)) {
                feedback.textContent = 'Este trabajador ya está en la selección.';
                return;
            }
            if (selected.length >= maxRows) {
                feedback.textContent = `Puedes crear hasta ${maxRows} usuarios por lote. Quita un trabajador o crea este lote primero.`;
                return;
            }

            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('[data-lote-name]').textContent = worker.nombre;
            row.querySelector('[data-lote-meta]').textContent = [worker.dni ? `DNI ${worker.dni}` : '', worker.puesto].filter(Boolean).join(' · ');
            row.querySelector('[data-lote-remove]').setAttribute('aria-label', `Quitar a ${worker.nombre} de la selección`);

            const personal = row.querySelector('[data-lote-personal]');
            const email = row.querySelector('[data-lote-email]');
            const label = row.querySelector('[data-lote-email-label]');
            personal.name = `filas[${nextIndex}][personal_id]`;
            personal.value = worker.id;
            email.id = `lote-email-${nextIndex}`;
            email.name = `filas[${nextIndex}][email]`;
            email.value = worker.correo_sugerido ?? '';
            label.htmlFor = email.id;
            nextIndex++;

            list.append(row);
            feedback.textContent = `${worker.nombre} se agregó a la selección.`;
            updateSelection();
        });

        list.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-lote-remove]');
            if (!remove) return;

            const row = remove.closest('[data-lote-row]');
            const remaining = rows();
            const index = remaining.indexOf(row);
            row.remove();
            feedback.textContent = 'Trabajador quitado de la selección.';
            updateSelection();

            const nextRow = remaining[index + 1] ?? remaining[index - 1];
            (nextRow?.querySelector('[data-lote-remove]') ?? search.querySelector('input[type="search"], input[type="text"]') ?? jump).focus();
        });

        form.addEventListener('submit', () => {
            rows().forEach((row, index) => {
                row.querySelector('[data-lote-personal]').name = `filas[${index}][personal_id]`;
                row.querySelector('[data-lote-email]').name = `filas[${index}][email]`;
            });
        });

        updateSelection();
    });
}
