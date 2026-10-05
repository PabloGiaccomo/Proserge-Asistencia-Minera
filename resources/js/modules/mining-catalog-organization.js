const catalogPage = document.querySelector('[data-mining-catalog]');
if (catalogPage) {
    const rows = [...catalogPage.querySelectorAll('[data-catalog-row]')];
    const search = catalogPage.querySelector('[data-catalog-search]');
    const filter = catalogPage.querySelector('[data-catalog-filter]');
    const applyFilter = () => {
        const text = search.value.trim().toLocaleLowerCase();
        let count = 0;
        for (const row of rows) {
            const matches = (!text || row.dataset.name.includes(text)) && (
                filter.value === 'all' || (filter.value === 'active' && row.dataset.active === '1') ||
                (filter.value === 'history' && row.dataset.active === '0') ||
                (filter.value === 'similar' && row.dataset.similar === '1'));
            row.hidden = !matches;
            if (matches) count++;
        }
        catalogPage.querySelector('[data-catalog-count]').textContent = `${count} exámenes visibles`;
        catalogPage.querySelector('[data-catalog-empty]').hidden = count !== 0;
    };
    search.addEventListener('input', applyFilter);
    filter.addEventListener('change', applyFilter);
    const principal = catalogPage.querySelector('[data-catalog-principal]');
    const action = catalogPage.querySelector('[data-catalog-action]');
    const syncSelection = () => {
        let selected = 0;
        for (const row of rows) {
            const input = row.querySelector('input');
            input.disabled = action.value !== 'asociar' || input.value === principal.value || principal.disabled;
            if (input.disabled) input.checked = false;
            if (input.checked) selected++;
        }
        catalogPage.querySelector('[data-catalog-selection]').textContent = principal.value
            ? `Principal: ${principal.selectedOptions[0].textContent}. ${selected} variante(s) seleccionada(s).`
            : 'Selecciona el principal y marca las variantes en la tabla.';
        const rename = catalogPage.querySelector('[data-catalog-rename]');
        rename.hidden = action.value !== 'renombrar';
        rename.querySelector('input').disabled = rename.hidden;
        rename.querySelector('input').required = !rename.hidden;
        const aliases = catalogPage.querySelector('[data-catalog-aliases]');
        aliases.hidden = action.value === 'pendiente';
        aliases.querySelectorAll('input, textarea, select').forEach(input => { input.disabled = aliases.hidden; });
    };
    principal.addEventListener('change', syncSelection);
    action.addEventListener('change', syncSelection);
    rows.forEach(row => row.querySelector('input').addEventListener('change', syncSelection));
    syncSelection();
    catalogPage.querySelectorAll('[data-catalog-confirm]').forEach(form => {
        const confirmation = form.querySelector('[name="confirmar"]');
        const submit = form.querySelector('button');
        confirmation.addEventListener('change', () => { submit.disabled = !confirmation.checked; });
    });
    if (new URLSearchParams(window.location.search).has('revision')) {
        catalogPage.querySelector('#catalog-review')?.scrollIntoView({ block: 'start' });
    }
}
