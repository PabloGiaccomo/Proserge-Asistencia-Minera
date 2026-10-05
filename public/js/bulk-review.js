(() => {
    const filter = document.getElementById('bulk-filter');
    if (!filter) return;
    const search = document.getElementById('bulk-search');
    const type = document.getElementById('bulk-type');
    const size = document.getElementById('bulk-size');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    const rows = Array.from(document.querySelectorAll('[data-bulk-categories]')).map(element => ({element, categories: element.dataset.bulkCategories.split(' '), issues: element.dataset.bulkIssues === '1', type: element.dataset.bulkType, text: normalize(element.textContent)}));
    const buttons = document.querySelectorAll('[data-bulk-category]');
    let page = 1;
    function render() {
        const words = normalize(search.value).split(' ').filter(Boolean);
        const matches = rows.filter(row => (filter.value === 'all' || (['changes','unchanged'].includes(filter.value) ? row.element.dataset.bulkDisposition === filter.value : filter.value === 'issues' ? row.issues : filter.value === 'clean' ? !row.issues : row.categories.includes(filter.value))) && (type.value === 'all' || row.type === type.value) && words.every(word => row.text.includes(word)));
        const limit = Number(size.value);
        const pages = Math.max(1, Math.ceil(matches.length / limit));
        page = Math.min(page, pages);
        const visible = new Set(matches.slice((page - 1) * limit, page * limit));
        rows.forEach(row => { row.element.hidden = !visible.has(row); if (row.element.hidden) row.element.open = false; });
        buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.bulkCategory === filter.value)));
        document.getElementById('bulk-filter-empty').hidden = matches.length !== 0;
        document.getElementById('bulk-count').textContent = matches.length ? `Mostrando ${(page - 1) * limit + 1}-${Math.min(page * limit, matches.length)} de ${matches.length} filas` : '0 filas encontradas';
        document.getElementById('bulk-page').textContent = `${page} / ${pages}`;
        document.getElementById('bulk-prev').disabled = page === 1;
        document.getElementById('bulk-next').disabled = page === pages;
    }
    const resetPage = () => { page = 1; render(); };
    search.addEventListener('input', resetPage);
    [filter, type, size].forEach(control => control.addEventListener('change', resetPage));
    buttons.forEach(button => button.addEventListener('click', () => { filter.value = button.dataset.bulkCategory; resetPage(); }));
    document.getElementById('bulk-reset').addEventListener('click', () => { search.value = ''; filter.value = 'all'; type.value = 'all'; resetPage(); });
    document.getElementById('bulk-prev').addEventListener('click', () => { page--; render(); });
    document.getElementById('bulk-next').addEventListener('click', () => { page++; render(); });
    render();
})();
