@once
<style>
.rq-personal-search-panel {
    position: fixed;
    z-index: 1200;
    display: none;
    max-height: 280px;
    overflow-y: auto;
    padding: 6px;
    border: 1px solid #dbeafe;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.18);
}

.rq-personal-search-panel.is-open {
    display: grid;
    gap: 4px;
}

.rq-personal-search-row {
    display: grid;
    gap: 3px;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: #0f172a;
    text-align: left;
    cursor: pointer;
}

.rq-personal-search-row:hover,
.rq-personal-search-row:focus {
    outline: none;
    background: #ecfeff;
}

.rq-personal-search-row strong {
    font-size: 13px;
    font-weight: 800;
}

.rq-personal-search-row span,
.rq-personal-search-empty {
    font-size: 12px;
    color: #64748b;
}

.rq-personal-search-empty {
    padding: 10px 12px;
}

.rq-personal-selected {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 6px;
    padding: 7px 9px;
    border: 1px solid #99f6e4;
    border-radius: 9px;
    background: #f0fdfa;
    color: #0f766e;
    font-size: 12px;
}

.rq-personal-selected span {
    display: grid;
    gap: 2px;
    min-width: 0;
}

.rq-personal-selected strong {
    color: #115e59;
}

.rq-personal-selected small {
    color: #64748b;
    font-size: 11px;
}

.rq-personal-selected button {
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    color: #0f766e;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
</style>

<script>
window.RQMinaPersonalAutocompleteConfig = {
    searchUrl: @json(route('rq-mina.personal.buscar')),
};

(function() {
    if (window.RQMinaPersonalAutocomplete && window.RQMinaPersonalAutocomplete.ready) {
        return;
    }

    const state = {
        activeInput: null,
        activeItems: [],
        timer: null,
        panel: null,
        requestId: 0,
    };

    function config() {
        return window.RQMinaPersonalAutocompleteConfig || {};
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function ensurePanel() {
        if (state.panel) return state.panel;
        state.panel = document.createElement('div');
        state.panel.className = 'rq-personal-search-panel';
        state.panel.addEventListener('mousedown', function(event) {
            event.preventDefault();
        });
        state.panel.addEventListener('click', handlePanelClick);
        document.body.appendChild(state.panel);
        return state.panel;
    }

    function positionPanel(input) {
        const panel = ensurePanel();
        const rect = input.getBoundingClientRect();
        panel.style.left = Math.max(8, rect.left) + 'px';
        panel.style.top = (rect.bottom + 6) + 'px';
        panel.style.width = Math.max(270, rect.width) + 'px';
    }

    function openPanel(input) {
        state.activeInput = input;
        positionPanel(input);
        ensurePanel().classList.add('is-open');
    }

    function closePanel() {
        if (state.panel) {
            state.panel.classList.remove('is-open');
        }
        state.activeInput = null;
    }

    function personMeta(person) {
        return [person.dni, person.puesto].filter(Boolean).join(' | ') || 'Sin datos adicionales';
    }

    function ensureSelection(input) {
        const next = input.nextElementSibling;
        if (next && next.hasAttribute('data-rq-personal-selected')) {
            return next;
        }

        const selection = document.createElement('div');
        selection.className = 'rq-personal-selected';
        selection.setAttribute('data-rq-personal-selected', '1');
        input.insertAdjacentElement('afterend', selection);
        return selection;
    }

    function clearSelection(input) {
        input.dataset.rqPersonalId = '';
        input.dataset.rqPersonalSelectedName = '';
        input.dataset.rqPersonalSelectedMeta = '';

        const next = input.nextElementSibling;
        if (next && next.hasAttribute('data-rq-personal-selected')) {
            next.remove();
        }
    }

    function renderSelection(input, person) {
        const selection = ensureSelection(input);
        const name = person.nombre || input.value || '';
        const meta = personMeta(person);

        input.dataset.rqPersonalSelectedName = name;
        input.dataset.rqPersonalSelectedMeta = meta;
        selection.innerHTML = '<span><strong>Seleccionado: ' + escapeHtml(name) + '</strong><small>' + escapeHtml(meta) + '</small></span>' +
            '<button type="button" data-rq-personal-clear>Quitar</button>';
    }

    function renderPanel(input, items) {
        const panel = ensurePanel();
        state.activeItems = Array.isArray(items) ? items : [];

        if (!state.activeItems.length) {
            panel.innerHTML = '<div class="rq-personal-search-empty">Sin coincidencias en personal.</div>';
            openPanel(input);
            return;
        }

        panel.innerHTML = state.activeItems.map(function(person, index) {
            return '<button type="button" class="rq-personal-search-row" data-person-index="' + index + '">' +
                '<strong>' + escapeHtml(person.nombre) + '</strong>' +
                '<span>' + escapeHtml(personMeta(person)) + '</span>' +
            '</button>';
        }).join('');

        openPanel(input);
    }

    async function fetchPersonal(input, query, requestId) {
        if (!config().searchUrl) return;
        const type = input.dataset.rqPersonalType || 'personal';
        const params = new URLSearchParams({ q: query, tipo: type, limit: '10' });
        const response = await fetch(config().searchUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok || requestId !== state.requestId || state.activeInput !== input) return;
        const payload = await response.json();
        renderPanel(input, payload.data || []);
    }

    function scheduleSearch(input) {
        clearTimeout(state.timer);
        const query = String(input.value || '').trim();
        state.activeInput = input;

        if (query.length < 2) {
            closePanel();
            return;
        }

        const requestId = ++state.requestId;
        state.timer = setTimeout(function() {
            fetchPersonal(input, query, requestId).catch(function() {});
        }, 180);
    }

    function applyPerson(input, person) {
        input.value = person.nombre || '';
        input.dataset.rqPersonalId = person.id || '';
        renderSelection(input, person);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePanel();
        input.focus();
    }

    function handlePanelClick(event) {
        const row = event.target.closest('[data-person-index]');
        if (!row || !state.activeInput) return;
        const person = state.activeItems[Number(row.dataset.personIndex)];
        if (person) {
            applyPerson(state.activeInput, person);
        }
    }

    function initInput(input) {
        if (!input || input.dataset.rqPersonalReady === '1') return;
        input.dataset.rqPersonalReady = '1';
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('focus', function() {
            if (String(input.value || '').trim().length >= 2) {
                scheduleSearch(input);
            }
        });

        input.addEventListener('input', function() {
            clearSelection(input);
            scheduleSearch(input);
        });

        input.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePanel();
            }
        });
    }

    function refresh(root) {
        const scope = root || document;
        scope.querySelectorAll('[data-rq-personal-search]').forEach(initInput);
    }

    document.addEventListener('DOMContentLoaded', function() {
        refresh(document);
    });

    window.addEventListener('resize', function() {
        if (state.activeInput) positionPanel(state.activeInput);
    });

    document.addEventListener('scroll', function() {
        if (state.activeInput) positionPanel(state.activeInput);
    }, true);

    document.addEventListener('mousedown', function(event) {
        if (!state.panel || state.panel.contains(event.target) || event.target.closest('[data-rq-personal-search]')) {
            return;
        }
        closePanel();
    });

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-rq-personal-clear]');
        if (!button) return;

        const selection = button.closest('[data-rq-personal-selected]');
        const input = selection ? selection.previousElementSibling : null;
        if (!input || !input.matches('[data-rq-personal-search]')) return;

        input.value = '';
        clearSelection(input);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    });

    window.RQMinaPersonalAutocomplete = {
        ready: true,
        refresh: refresh,
    };
})();
</script>
@endonce
