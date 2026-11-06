<script>
(function () {
    const panel = document.querySelector('[data-logistics-tab-panel="identificacion"]');
    const form = panel?.querySelector('[data-ident-search-form]');
    if (!form) return;

    const input = form.querySelector('[name="ident_q"]');
    const rows = Array.from(panel.querySelectorAll('[data-ident-row]'));
    const countLabel = panel.querySelector('[data-ident-count]');
    const table = panel.querySelector('[data-ident-table]');
    const empty = panel.querySelector('[data-ident-empty]');
    const emptyText = panel.querySelector('[data-ident-empty-text]');
    const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const searchText = new Map(rows.map(row => [row, normalize(row.dataset.identSearch || '')]));

    function filterItems() {
        const search = input.value.trim();
        const terms = normalize(search).split(/\s+/).filter(Boolean);
        let count = 0;
        rows.forEach(row => {
            const matches = terms.every(term => searchText.get(row).includes(term));
            row.hidden = !matches;
            if (matches) count++;
        });
        countLabel.textContent = count + (count === 1 ? ' ítem encontrado' : ' ítems encontrados');
        if (table) table.hidden = count === 0;
        empty.hidden = count > 0;
        emptyText.textContent = search
            ? 'No hay ítems que coincidan con «' + search + '» en esta categoría. Revisa otra categoría o limpia la búsqueda.'
            : 'No hay ítems registrados en el catálogo de ' + form.dataset.identCategoryLabel + '.';

        function setSearch(url) {
            if (search) url.searchParams.set('ident_q', search);
            else url.searchParams.delete('ident_q');
            return url;
        }
        panel.querySelectorAll('.lgt-ident-category-tab').forEach(link => {
            link.href = setSearch(new URL(link.href)).href;
        });
        const url = setSearch(new URL(window.location.href));
        if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url);
    }

    input.addEventListener('input', filterItems);
    form.addEventListener('submit', event => {
        event.preventDefault();
        filterItems();
    });
    form.querySelector('[data-ident-search-clear]').addEventListener('click', event => {
        event.preventDefault();
        input.value = '';
        filterItems();
        input.focus();
    });
    filterItems();
})();
</script>
