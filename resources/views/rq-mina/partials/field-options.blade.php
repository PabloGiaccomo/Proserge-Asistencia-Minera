@once
<style>
.rq-field-options-panel {
    position: fixed;
    z-index: 9999;
    display: none;
    width: 320px;
    max-height: 280px;
    overflow: auto;
    border: 1px solid #dbe4ef;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 36px rgba(15, 23, 42, .16);
}
.rq-field-options-panel.is-open { display: block; }
.rq-field-options-row {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border: 0;
    background: #fff;
    color: #0f172a;
    padding: 9px 10px;
    text-align: left;
    cursor: pointer;
    font-size: 12px;
}
.rq-field-options-row:hover { background: #f8fafc; }
.rq-field-options-value { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rq-field-options-add { color: #0f766e; font-weight: 800; }
.rq-field-options-delete {
    flex: 0 0 auto;
    width: 20px;
    height: 20px;
    border-radius: 999px;
    border: 1px solid #fecaca;
    background: #fff7f7;
    color: #b91c1c;
    line-height: 18px;
    text-align: center;
    font-size: 13px;
    cursor: pointer;
}
.rq-field-options-delete:hover { background: #fee2e2; }
.rq-field-options-empty {
    padding: 10px;
    color: #64748b;
    font-size: 12px;
}
</style>

<script>
window.RQMinaFieldOptionsConfig = {
    indexUrl: @json(route('rq-mina.opciones-campo.index')),
    storeUrl: @json(route('rq-mina.opciones-campo.store')),
    deleteUrlTemplate: @json(route('rq-mina.opciones-campo.destroy', ['optionId' => '__ID__'])),
    csrf: @json(csrf_token()),
};

(function() {
    if (window.RQMinaFieldOptions && window.RQMinaFieldOptions.ready) {
        return;
    }

    const state = {
        activeInput: null,
        activeOptions: [],
        query: '',
        timer: null,
        panel: null,
    };

    function config() {
        return window.RQMinaFieldOptionsConfig || {};
    }

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function csrfToken() {
        const fromInput = document.querySelector('input[name="_token"]');
        return (fromInput && fromInput.value) || config().csrf || '';
    }

    function ensurePanel() {
        if (state.panel) return state.panel;
        state.panel = document.createElement('div');
        state.panel.className = 'rq-field-options-panel';
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
        panel.style.width = Math.max(260, rect.width) + 'px';
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

    function renderPanel(input, options, query) {
        const panel = ensurePanel();
        state.activeOptions = Array.isArray(options) ? options : [];
        state.query = query || '';

        const normalizedQuery = normalize(query);
        const hasExact = state.activeOptions.some(option => normalize(option.value) === normalizedQuery);
        let html = '';

        state.activeOptions.forEach(function(option) {
            html += '<div class="rq-field-options-row" data-option-id="' + String(option.id) + '">' +
                '<span class="rq-field-options-value">' + escapeHtml(option.value) + '</span>' +
                '<button type="button" class="rq-field-options-delete" data-delete-option-id="' + String(option.id) + '" aria-label="Quitar opcion" title="Quitar opcion">&times;</button>' +
            '</div>';
        });

        if (normalizedQuery && !hasExact) {
            html += '<div class="rq-field-options-row rq-field-options-add" data-add-option="1">Agregar "' + escapeHtml(query) + '"</div>';
        }

        if (!html) {
            html = '<div class="rq-field-options-empty">Sin opciones guardadas todavia.</div>';
        }

        panel.innerHTML = html;
        openPanel(input);
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    async function fetchOptions(input) {
        const field = input.dataset.rqOptionField || '';
        if (!field || !config().indexUrl) return;

        const query = input.value || '';
        const params = new URLSearchParams({ field: field, q: query, limit: '12' });
        const response = await fetch(config().indexUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) return;
        const payload = await response.json();
        if (state.activeInput === input) {
            renderPanel(input, payload.data || [], query);
        }
    }

    function scheduleFetch(input) {
        clearTimeout(state.timer);
        state.timer = setTimeout(function() {
            fetchOptions(input).catch(function() {});
        }, 160);
    }

    async function saveOption(input, value) {
        const field = input.dataset.rqOptionField || '';
        const text = String(value || input.value || '').replace(/\s+/g, ' ').trim();
        if (!field || !text || normalize(input.dataset.rqOptionSavedValue) === normalize(text)) {
            return null;
        }

        const response = await fetch(config().storeUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ field: field, value: text }),
        });

        if (!response.ok) return null;
        const payload = await response.json();
        input.dataset.rqOptionSavedValue = text;
        return payload.data || null;
    }

    async function deleteOption(optionId) {
        if (!optionId || !config().deleteUrlTemplate) return;
        const url = config().deleteUrlTemplate.replace('__ID__', encodeURIComponent(optionId));
        await fetch(url, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
    }

    function applyValue(input, value) {
        input.value = value;
        input.dataset.rqOptionSavedValue = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closePanel();
        input.focus();
    }

    function handlePanelClick(event) {
        const input = state.activeInput;
        if (!input) return;

        const deleteButton = event.target.closest('[data-delete-option-id]');
        if (deleteButton) {
            deleteOption(deleteButton.dataset.deleteOptionId)
                .then(function() { return fetchOptions(input); })
                .catch(function() {});
            return;
        }

        const addRow = event.target.closest('[data-add-option]');
        if (addRow) {
            saveOption(input, state.query)
                .then(function(option) {
                    applyValue(input, (option && option.value) || state.query);
                })
                .catch(function() {});
            return;
        }

        const optionRow = event.target.closest('[data-option-id]');
        if (!optionRow) return;
        const option = state.activeOptions.find(item => String(item.id) === String(optionRow.dataset.optionId));
        if (option) {
            applyValue(input, option.value);
        }
    }

    function initInput(input) {
        if (!input || input.dataset.rqOptionReady === '1') return;
        input.dataset.rqOptionReady = '1';
        input.setAttribute('autocomplete', 'off');

        input.addEventListener('focus', function() {
            state.activeInput = input;
            scheduleFetch(input);
        });

        input.addEventListener('input', function() {
            state.activeInput = input;
            scheduleFetch(input);
        });

        input.addEventListener('blur', function() {
            saveOption(input).catch(function() {});
            setTimeout(closePanel, 160);
        });
    }

    function refresh(root) {
        const scope = root || document;
        scope.querySelectorAll('[data-rq-option-field]').forEach(initInput);
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
        if (!state.panel || state.panel.contains(event.target) || event.target.closest('[data-rq-option-field]')) {
            return;
        }
        closePanel();
    });

    window.RQMinaFieldOptions = {
        ready: true,
        refresh: refresh,
        saveOption: saveOption,
    };
})();
</script>
@endonce
