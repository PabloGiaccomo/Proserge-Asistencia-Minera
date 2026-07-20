@php
    $selectorId = $selectorId ?? 'rqSupervisorSelector';
    $selectedSupervisor = $selectedSupervisor ?? null;
    $title = $title ?? 'Supervisor a cargo';
    $fieldName = $fieldName ?? 'supervisor_id';
    $placeholder = $placeholder ?? 'Buscar supervisor por nombre, DNI o puesto';
    $emptyText = $emptyText ?? 'Sin supervisor seleccionado.';
@endphp

@once

<script>
window.rqMinaSupervisorSelectors = window.rqMinaSupervisorSelectors || {};

window.rqMinaSupervisorSelectorSet = function(selectorId, supervisor) {
    const instance = window.rqMinaSupervisorSelectors[selectorId];
    if (instance) {
        instance.setSupervisor(supervisor || null);
    }
};

function initRQMinaSupervisorSelector(root) {
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    const selectorId = root.id;
    const searchUrl = root.dataset.searchUrl;
    const input = root.querySelector('[data-rq-supervisor-search]');
    const hidden = root.querySelector('[data-rq-supervisor-id]');
    const resultsBox = root.querySelector('[data-rq-supervisor-results]');
    const clearButton = root.querySelector('[data-rq-supervisor-clear]');
    const selectedBox = root.querySelector('[data-rq-supervisor-selected]');
    const initialScript = document.querySelector('script[data-rq-supervisor-initial="' + selectorId + '"]');
    let timer = null;
    let selected = null;

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function personLabel(person) {
        return [person.dni, person.puesto].filter(Boolean).join(' | ') || '-';
    }

    function compact(person) {
        if (!person) return null;
        return {
            id: person.id || '',
            nombre: person.nombre || person.nombre_completo || '',
            dni: person.dni || '',
            puesto: person.puesto || '',
            es_supervisor: true,
        };
    }

    function renderSelected() {
        hidden.value = selected ? selected.id : '';
        input.value = selected ? selected.nombre : input.value;
        clearButton.classList.toggle('is-visible', !!selected);
        selectedBox.innerHTML = selected
            ? 'Seleccionado: <strong>' + escapeHtml(selected.nombre) + '</strong> <span>' + escapeHtml(personLabel(selected)) + '</span>'
            : selectedBox.dataset.emptyText;
    }

    function clearSelection(keepText) {
        selected = null;
        hidden.value = '';
        if (!keepText) {
            input.value = '';
        }
        renderSelected();
    }

    async function fetchSupervisors(query) {
        const params = new URLSearchParams({ q: query, tipo: 'supervisor', limit: '10' });
        const response = await fetch(searchUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
        });
        const payload = await response.json();
        return payload.data || [];
    }

    function renderResults(items) {
        resultsBox.innerHTML = '';

        if (!items.length) {
            resultsBox.innerHTML = '<div class="rq-supervisor-empty">Sin coincidencias.</div>';
            resultsBox.classList.add('is-open');
            return;
        }

        items.forEach(function(person) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rq-supervisor-result';
            button.innerHTML = '<strong>' + escapeHtml(person.nombre) + '</strong><span>' + escapeHtml(personLabel(person)) + '</span>';
            button.addEventListener('click', function() {
                selected = compact(person);
                resultsBox.classList.remove('is-open');
                resultsBox.innerHTML = '';
                renderSelected();
            });
            resultsBox.appendChild(button);
        });

        resultsBox.classList.add('is-open');
    }

    input.addEventListener('input', function() {
        clearTimeout(timer);
        clearSelection(true);
        const query = input.value.trim();
        if (query.length < 2) {
            resultsBox.classList.remove('is-open');
            resultsBox.innerHTML = '';
            return;
        }

        timer = setTimeout(async function() {
            renderResults(await fetchSupervisors(query));
        }, 220);
    });

    clearButton.addEventListener('click', function() {
        clearSelection(false);
        resultsBox.classList.remove('is-open');
        resultsBox.innerHTML = '';
        input.focus();
    });

    document.addEventListener('click', function(event) {
        if (!root.contains(event.target)) {
            resultsBox.classList.remove('is-open');
        }
    });

    window.rqMinaSupervisorSelectors[selectorId] = {
        setSupervisor: function(supervisor) {
            selected = compact(supervisor);
            input.value = selected ? selected.nombre : '';
            resultsBox.classList.remove('is-open');
            resultsBox.innerHTML = '';
            renderSelected();
        },
    };

    const initialSupervisor = initialScript ? JSON.parse(initialScript.textContent || 'null') : null;
    window.rqMinaSupervisorSelectors[selectorId].setSupervisor(initialSupervisor);
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-rq-supervisor-selector]').forEach(initRQMinaSupervisorSelector);
});
</script>
@endonce

<div id="{{ $selectorId }}" class="rq-supervisor-selector" data-rq-supervisor-selector data-search-url="{{ route('rq-mina.personal.buscar') }}">
    <div class="rq-supervisor-selector-head">
        <h3 class="rq-supervisor-selector-title">{{ $title }}</h3>
    </div>
    <div class="rq-supervisor-search-wrap">
        <input type="hidden" name="{{ $fieldName }}" data-rq-supervisor-id>
        <input type="text" class="rq-supervisor-search-input" data-rq-supervisor-search placeholder="{{ $placeholder }}" autocomplete="off">
        <button type="button" class="rq-supervisor-clear" data-rq-supervisor-clear aria-label="Quitar supervisor" title="Quitar supervisor">&times;</button>
        <div class="rq-supervisor-results" data-rq-supervisor-results></div>
    </div>
    <div class="rq-supervisor-selected" data-rq-supervisor-selected data-empty-text="{{ $emptyText }}">{{ $emptyText }}</div>
</div>

<script type="application/json" data-rq-supervisor-initial="{{ $selectorId }}">@json($selectedSupervisor)</script>
