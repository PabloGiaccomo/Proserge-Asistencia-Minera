export function invalidateOperationSignature(root, pads) {
    root.addEventListener('operation:lines-changed', () => {
        pads.forEach(pad => pad.clear());
        root.querySelectorAll('[data-review-identity]').forEach(input => { input.value = ''; });
    });
}

export function initEppOperations(root = document) {
    root.querySelectorAll('[data-epp-operation]').forEach(page => {
        if (page.dataset.initialized) return;
        page.dataset.initialized = '1';
        const pads = [...page.querySelectorAll('[data-signature-pad]')].map(canvas => canvas.signaturePad).filter(Boolean);
        invalidateOperationSignature(page, pads);
        const lineChanged = () => page.dispatchEvent(new Event('operation:lines-changed'));
        page.querySelectorAll('[data-return-line]').forEach(line => {
            const selected = line.querySelector('[data-return-select]');
            const quantity = line.querySelector('[data-return-quantity]');
            const condition = line.querySelector('[data-return-condition]');
            const destination = line.querySelector('[data-return-destination]');
            const refresh = () => {
                line.querySelectorAll('[data-return-field]').forEach(field => { field.disabled = !selected.checked; });
                if (condition && destination) {
                    const good = condition.value === 'BUENO';
                    line.querySelector('textarea').required = condition.value === 'OTRO';
                    [...destination.options].forEach(option => { option.disabled = good ? option.value !== 'DISPONIBLE' : option.value === 'DISPONIBLE'; });
                    if (destination.selectedOptions[0].disabled) destination.value = good ? 'DISPONIBLE' : 'CUARENTENA';
                }
                const remaining = Number(line.dataset.balance) - (selected.checked ? Number(quantity.value || 0) : 0);
                line.querySelector('[data-return-remaining]').textContent = `Saldo restante: ${remaining} unidades.`;
                const error = page.querySelector('[data-return-error]');
                if (error && page.querySelector('[data-return-select]:checked')) error.hidden = true;
                lineChanged();
            };
            line.addEventListener('input', refresh);
            line.addEventListener('change', refresh);
            refresh();
        });
        page.querySelector('[data-return-form]')?.addEventListener('submit', event => {
            if (!page.querySelector('[data-return-select]:checked')) {
                event.preventDefault();
                page.querySelector('[data-return-error]').hidden = false;
            }
        });
        page.querySelector('[data-return-search]')?.closest('form').addEventListener('input', () => {
            const form = page.querySelector('[data-return-form]');
            if (form) {
                form.inert = true;
                const error = page.querySelector('[data-return-error]');
                if (error) { error.textContent = page.hasAttribute('data-catalog-return') ? 'Pulsa Ver saldos por devolver para actualizar el trabajador.' : 'Pulsa Ver saldos por devolver para actualizar el trabajador y almacen.'; error.hidden = false; }
            }
        });
        const catalog = JSON.parse(page.querySelector('[data-operation-catalog]')?.textContent || '[]');
        const existences = JSON.parse(page.querySelector('[data-operation-existences]')?.textContent || '[]');
        const lines = page.querySelector('[data-operation-lines]');
        let nextIndex = lines?.children.length || 0;
        const product = line => catalog.find(item => item.id === (line.querySelector('[data-actual-product]').value || line.querySelector('[data-requested-product]').value));
        const availability = line => {
            const item = product(line), status = line.querySelector('[data-line-availability]');
            if (!item) { status.textContent = 'Selecciona el articulo y su variante.'; return; }
            if (!item.control_inventario) { status.textContent = 'Sin control de inventario: se revisara la cantidad solicitada.'; return; }
            const attributes = Object.fromEntries([...line.querySelectorAll('[data-attribute]')].filter(input => input.value.trim()).map(input => [input.dataset.attribute, input.value.trim().toUpperCase()]));
            const existence = existences.find(row => row.epp_id === item.id && JSON.stringify(Object.entries(row.atributos_json || {}).sort()) === JSON.stringify(Object.entries(attributes).sort()));
            status.textContent = !existence?.conciliado_at ? 'Stock sin conciliar. Esta variante requiere conteo del almacen.' : `Disponibles: ${existence.cantidad_disponible} unidades. La cantidad definitiva se calcula al preparar.`;
        };
        const attributes = (line, initial = {}) => {
            const item = product(line), container = line.querySelector('[data-line-attributes]');
            container.replaceChildren();
            if (!item) { availability(line); return; }
            const fields = [];
            if (item.requiere_talla) fields.push({ nombre: 'TALLA', valores: item.tallas });
            if (item.requiere_color) fields.push({ nombre: 'COLOR', valores: item.colores });
            fields.push(...(item.otros_atributos || []));
            if (item.requiere_serie) fields.push({ nombre: 'SERIE', valores: null });
            fields.forEach(field => {
                const name = field.nombre.trim().toUpperCase();
                const label = document.createElement('label'); label.textContent = field.nombre;
                const input = document.createElement(field.valores ? 'select' : 'input');
                input.dataset.attribute = name;
                input.name = `lines[${line.dataset.lineIndex}][atributos][${name}]`;
                input.required = true;
                if (field.valores) {
                    input.append(new Option('Seleccionar', ''));
                    field.valores.forEach(value => input.append(new Option(value, value)));
                } else { input.type = 'text'; input.maxLength = 200; }
                input.value = initial[name] || '';
                label.append(input); container.append(label);
            });
            availability(line);
        };
        const setupLine = line => {
            attributes(line, JSON.parse(line.querySelector('[data-line-attributes]').dataset.initialAttributes || '{}'));
            line.addEventListener('change', event => {
                if (event.target.matches('[data-actual-product], [data-requested-product]')) attributes(line);
                availability(line); lineChanged();
            });
            line.addEventListener('input', lineChanged);
        };
        page.querySelectorAll('[data-operation-line]').forEach(setupLine);
        page.addEventListener('click', event => {
            const add = event.target.closest('[data-add-line]');
            if (add) {
                if (lines.children.length >= 100) return;
                const template = page.querySelector('[data-operation-template]');
                const holder = document.createElement('div');
                holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                const line = holder.firstElementChild;
                line.querySelector('[data-line-number]').textContent = String(nextIndex);
                lines.append(line); setupLine(line); lineChanged();
            }
            const remove = event.target.closest('[data-remove-line]');
            if (remove && lines.children.length > 1) { remove.closest('[data-operation-line]').remove(); lineChanged(); }
            const step = event.target.closest('[data-quantity-step]');
            if (step) {
                const input = step.parentElement.querySelector('input');
                input.value = Math.min(1000000, Math.max(1, Number(input.value || 1) + Number(step.dataset.quantityStep)));
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        const search = page.querySelector('[data-worker-search]'), results = page.querySelector('[data-worker-results]'), workerId = page.querySelector('[data-worker-id]');
        let requestNumber = 0, timer;
        search?.addEventListener('input', () => {
            workerId.value = '';
            results.replaceChildren();
            page.querySelector('[data-worker-selected]').textContent = 'Selecciona un trabajador de los resultados.';
            lineChanged(); clearTimeout(timer);
            const number = ++requestNumber;
            const query = search.value.trim();
            if (query.length < 2) return;
            timer = setTimeout(async () => {
                try {
                    const response = await fetch(`${search.dataset.searchUrl}?q=${encodeURIComponent(query)}${search.hasAttribute('data-return-search') ? '&devolucion=1' : ''}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                    if (!response.ok) throw new Error();
                    const data = await response.json();
                    if (number !== requestNumber) return;
                    results.replaceChildren();
                    data.items.forEach(worker => {
                        const button = document.createElement('button'); button.type = 'button'; button.className = 'epp-operation-button';
                        const name = worker.nombre_completo ?? worker.nombre ?? worker.label;
                        button.textContent = `${name} · ${worker.dni ?? worker.documento ?? ''}`;
                        button.addEventListener('click', () => { workerId.value = worker.id; search.value = name; page.querySelector('[data-worker-selected]').textContent = button.textContent; results.replaceChildren(); });
                        results.append(button);
                    });
                    if (!data.items.length) results.textContent = 'No hay trabajadores disponibles para tu alcance.';
                } catch { if (number === requestNumber) results.textContent = 'No se pudo buscar. Intenta nuevamente.'; }
            }, 250);
        });
        page.querySelector('[data-operation-form]')?.addEventListener('submit', event => {
            if (!workerId.value) { event.preventDefault(); search.focus(); results.textContent = 'Busca y selecciona al trabajador antes de preparar.'; }
        });
        page.querySelectorAll('[data-signer-field]').forEach(input => input.addEventListener('input', () => pads.forEach(pad => pad.clear())));
        page.querySelector('[data-operation-sign]')?.addEventListener('submit', event => {
            if (event.defaultPrevented || !pads[0]?.hasInk()) { event.preventDefault(); return; }
            const payload = pads[0].toPayload();
            page.querySelector('[data-ink="point_count"]').value = payload.ink.point_count;
            Object.entries(payload.ink.bounds).forEach(([name, value]) => { page.querySelector(`[data-ink="${name}"]`).value = value; });
        });
    });
}
