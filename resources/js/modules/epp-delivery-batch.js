export function initEppDeliveryBatches() {
    document.querySelectorAll('[data-epp-batch-form]').forEach(form => {
        if (form.dataset.batchReady) return;
        form.dataset.batchReady = '1';
        const worker = form.querySelector('[name="personal_id"]');
        const workerSearch = form.querySelector('#eppPersonalSearch');
        const product = form.querySelector('[name="epp_id"]');
        const quantity = form.querySelector('[name="cantidad"]');
        const observation = form.querySelector('[name="observacion"]');
        const attributes = form.querySelector('#eppDeliveryAttributes');
        const summary = form.querySelector('[data-epp-batch-summary]');
        const list = form.querySelector('[data-epp-batch-list]');
        const inputs = form.querySelector('[data-epp-batch-inputs]');
        const error = form.querySelector('[data-epp-batch-error]');
        const initial = JSON.parse(form.querySelector('[data-epp-batch-initial]').textContent || '[]');
        const items = Array.isArray(initial) ? initial : [];
        let busy = false;
        const showError = message => {
            error.textContent = message;
            error.hidden = false;
            error.scrollIntoView({ block: 'nearest' });
        };
        const hiddenInput = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value ?? '';
            inputs.append(input);
        };
        const sync = () => {
            list.replaceChildren();
            inputs.replaceChildren();
            summary.hidden = items.length === 0;
            form.querySelector('[data-epp-batch-count]').textContent = items.length;
            workerSearch.readOnly = items.length > 0;
            product.required = items.length === 0;
            items.forEach((item, index) => {
                const row = document.createElement('div');
                row.className = 'epp-batch-item';
                row.dataset.eppBatchItem = '';
                const text = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = Array.from(product.options).find(option => option.value === item.epp_id)?.textContent || 'EPP no disponible en el catalogo';
                const detail = document.createElement('p');
                detail.textContent = [`Cantidad: ${item.cantidad}`, item.talla && `Talla: ${item.talla}`, item.color && `Color: ${item.color}`, ...(item.atributos || []).map(attribute => `${attribute.nombre}: ${attribute.valor}`), item.observacion].filter(Boolean).join(' · ');
                text.append(name, detail);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'epp-btn epp-btn-ghost';
                remove.dataset.eppRemoveFromBatch = '';
                remove.textContent = 'Quitar';
                remove.setAttribute('aria-label', `Quitar ${name.textContent}`);
                remove.addEventListener('click', () => { items.splice(index, 1); sync(); });
                row.append(text, remove);
                list.append(row);
                ['epp_id', 'cantidad', 'talla', 'color', 'observacion'].forEach(key => hiddenInput(`items[${index}][${key}]`, item[key]));
                (item.atributos || []).forEach((attribute, attributeIndex) => {
                    hiddenInput(`items[${index}][atributos][${attributeIndex}][nombre]`, attribute.nombre);
                    hiddenInput(`items[${index}][atributos][${attributeIndex}][valor]`, attribute.valor);
                });
            });
        };
        const validateWorker = () => {
            workerSearch.setCustomValidity(worker.value ? '' : 'Selecciona al trabajador desde los resultados de busqueda.');
            return workerSearch.reportValidity();
        };
        workerSearch.addEventListener('input', () => workerSearch.setCustomValidity(''));
        const addCurrent = () => {
            error.hidden = true;
            if (items.length >= 50) { showError('Puedes incluir hasta 50 articulos en una firma.'); return false; }
            if (!validateWorker()) return false;
            if (!product.value) { showError('Selecciona el EPP que deseas agregar.'); return false; }
            if (!form.reportValidity()) return false;
            const item = { epp_id: product.value, cantidad: quantity.value, observacion: observation.value, talla: '', color: '', atributos: [] };
            item.talla = attributes.querySelector('[name="talla"]')?.value || '';
            item.color = attributes.querySelector('[name="color"]')?.value || '';
            attributes.querySelectorAll('input[name$="[nombre]"]').forEach(input => {
                const value = attributes.querySelector(`[name="${input.name.replace('[nombre]', '[valor]')}"]`);
                item.atributos.push({ nombre: input.value, valor: value?.value || '' });
            });
            items.push(item);
            product.value = '';
            quantity.value = '1';
            observation.value = '';
            product.dispatchEvent(new Event('change', { bubbles: true }));
            sync();
            return true;
        };
        form.querySelector('[data-epp-add-to-batch]').addEventListener('click', () => {
            if (addCurrent()) product.focus();
        });
        form.addEventListener('submit', event => {
            if (busy) { event.preventDefault(); return; }
            if (!validateWorker() || (product.value && !addCurrent()) || !items.length) {
                event.preventDefault();
                if (!items.length && !product.value) showError('Agrega al menos un EPP antes de continuar a la firma.');
                return;
            }
            sync();
            busy = true;
            form.querySelectorAll('button').forEach(button => { button.disabled = true; });
        });
        sync();
    });
}
