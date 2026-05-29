@once
<style>
.rq-personal-search-panel {
    position: fixed;
    z-index: 10000;
    display: none;
    width: 320px;
    max-height: 280px;
    overflow: auto;
    border: 1px solid #dbe4ef;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 36px rgba(15, 23, 42, .16);
}
.rq-personal-search-panel.is-open { display: block; }
.rq-personal-search-row {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 2px;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    background: #fff;
    padding: 10px 11px;
    text-align: left;
    cursor: pointer;
}
.rq-personal-search-row:hover { background: #f8fafc; }
.rq-personal-search-row strong { color: #0f172a; font-size: 12px; line-height: 1.25; }
.rq-personal-search-row span { color: #64748b; font-size: 11px; line-height: 1.25; }
.rq-personal-search-empty {
    padding: 10px 11px;
    color: #64748b;
    font-size: 12px;
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
        input.dispatchEvent(new Event('input', { bubbles: true }));
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
            input.dataset.rqPersonalId = '';
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

    window.RQMinaPersonalAutocomplete = {
        ready: true,
        refresh: refresh,
    };
})();
</script>
@endonce
