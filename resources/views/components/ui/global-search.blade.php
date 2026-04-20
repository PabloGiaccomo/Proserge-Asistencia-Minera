<div class="global-search" data-search-id="{{ $searchId ?? 'default' }}" data-search-url="{{ $searchUrl ?? '/api/v1/search' }}">
    <div class="global-search-input-wrapper">
        <svg class="global-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.35-4.35"/>
        </svg>
        <input 
            type="text" 
            class="global-search-input {{ $inputClass ?? '' }}"
            placeholder="{{ $placeholder ?? 'Buscar...' }}"
            value="{{ $value ?? '' }}"
            data-search-input
            @if(isset($minChars)) data-min-chars="{{ $minChars }}" @endif
        >
        @if(($showClear ?? true))
            <button class="global-search-clear" type="button" data-search-clear title="Limpiar" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        @endif
    </div>
    <div class="global-search-dropdown" data-search-dropdown style="display: none;">
        <div class="global-search-results" data-search-results style="display: none;"></div>
        <div class="global-search-loading" data-search-loading style="display: none;">
            <div class="global-search-spinner"></div>
            <span>Buscando...</span>
        </div>
        <div class="global-search-empty" data-search-empty style="display: none;">
            <span>Sin resultados</span>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const SearchManager = {
        instances: new Map(),
        searchTimeout: null,
        minChars: 2,

        init(inputElement) {
            const wrapper = inputElement.closest('.global-search');
            if (!wrapper) return;

            const searchId = wrapper.dataset.searchId || 'default';
            const searchUrl = wrapper.dataset.searchUrl || '/api/v1/search';
            const minChars = parseInt(inputElement.dataset.minChars) || this.minChars;

            this.instances.set(searchId, {
                wrapper,
                input: inputElement,
                dropdown: wrapper.querySelector('[data-search-dropdown]'),
                results: wrapper.querySelector('[data-search-results]'),
                loading: wrapper.querySelector('[data-search-loading]'),
                empty: wrapper.querySelector('[data-search-empty]'),
                clearBtn: wrapper.querySelector('[data-search-clear]'),
                searchUrl,
                minChars,
                isOpen: false
            });

            this.bindEvents(searchId);
        },

        bindEvents(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            instance.input.addEventListener('input', (e) => this.handleInput(searchId, e.target.value));
            instance.input.addEventListener('focus', () => this.handleFocus(searchId));
            instance.input.addEventListener('blur', () => this.handleBlur(searchId));
            instance.input.addEventListener('keydown', (e) => this.handleKeydown(searchId, e));

            if (instance.clearBtn) {
                instance.clearBtn.addEventListener('click', () => this.clearSearch(searchId));
            }

            document.addEventListener('click', (e) => {
                if (!instance.wrapper.contains(e.target)) {
                    this.closeDropdown(searchId);
                }
            });
        },

        handleInput(searchId, value) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            if (instance.clearBtn) {
                instance.clearBtn.style.display = value.length > 0 ? 'flex' : 'none';
            }

            clearTimeout(this.searchTimeout);
            
            if (value.length < instance.minChars) {
                this.closeDropdown(searchId);
                return;
            }

            this.searchTimeout = setTimeout(() => {
                this.performSearch(searchId, value);
            }, 300);
        },

        handleFocus(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            if (instance.input.value.length >= instance.minChars) {
                this.openDropdown(searchId);
            }
        },

        handleBlur(searchId) {
            setTimeout(() => this.closeDropdown(searchId), 200);
        },

        handleKeydown(searchId, e) {
            const instance = this.instances.get(searchId);
            if (!instance || !instance.isOpen) return;

            const items = instance.results.querySelectorAll('[data-search-item]');
            const currentIndex = Array.from(items).findIndex(item => item.classList.contains('selected'));

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < items.length - 1) {
                        items[currentIndex]?.classList.remove('selected');
                        items[currentIndex + 1]?.classList.add('selected');
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        items[currentIndex]?.classList.remove('selected');
                        items[currentIndex - 1]?.classList.add('selected');
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    const selected = instance.results.querySelector('[data-search-item].selected');
                    if (selected) {
                        this.selectItem(searchId, selected);
                    }
                    break;
                case 'Escape':
                    this.closeDropdown(searchId);
                    instance.input.blur();
                    break;
            }
        },

        performSearch(searchId, query) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            this.showLoading(searchId);

            setTimeout(() => {
                const results = this.getDemoResults(query);
                this.renderResults(searchId, results);
            }, 200);
        },

        getDemoResults(query) {
            const q = query.toLowerCase();
            const workers = [
                { id: '1', nombre: 'Carlos Alberto Mendoza Sánchez', puesto: 'Operador de Equipos Pesados', dni: '74856231', tipo: 'Indeterminado', supervisor: false, mina: 'Mina 1' },
                { id: '2', nombre: 'María Elena Quispe Flores', puesto: 'Supervisor de Turno', dni: '61245874', tipo: 'Indeterminado', supervisor: true, mina: 'Mina 1' },
                { id: '3', nombre: 'Juan Pedro Huamán Torres', puesto: 'Técnico de Mantenimiento', dni: '89562341', tipo: 'Fijo', supervisor: false, mina: 'Taller' },
                { id: '4', nombre: 'Rosa Luz García Rivera', puesto: 'Asistente Administrativa', dni: '74589123', tipo: 'Régimen', supervisor: false, mina: 'Oficina' },
                { id: '5', nombre: 'Pedro Miguel Asto Yupanqui', puesto: 'Conductor de Camión', dni: '70125489', tipo: 'Intermitente', supervisor: false, mina: 'Mina 3' },
                { id: '6', nombre: 'Luis Fernando Cóndor Huanca', puesto: 'Jefe de Seguridad', dni: '45678231', tipo: 'Indeterminado', supervisor: true, mina: 'Mina 1' },
                { id: '7', nombre: 'Ana María Lucero Pérez', puesto: 'Enfermera Industrial', dni: '82365412', tipo: 'Fijo', supervisor: false, mina: 'Mina 1' },
                { id: '8', nombre: 'Roberto Carlos Mendoza', puesto: 'Mecánico', dni: '98745123', tipo: 'Indeterminado', supervisor: false, mina: 'Taller' },
                { id: '9', nombre: 'Sánchez Pablo Paredes', puesto: 'Geólogo', dni: '12345678', tipo: 'Indeterminado', supervisor: false, mina: 'Mina 2' },
                { id: '10', nombre: 'Diana Lucía Flores Mamani', puesto: 'Contadora', dni: '56782345', tipo: 'Régimen', supervisor: false, mina: 'Oficina' },
            ];

            return workers.filter(w => {
                const searchString = `${w.nombre} ${w.puesto} ${w.dni} ${w.tipo} ${w.mina}`.toLowerCase();
                const queryParts = q.split(' ').filter(p => p.length > 0);
                
                if (searchString.includes(q)) return true;
                
                const allPartsMatch = queryParts.every(part => searchString.includes(part));
                if (allPartsMatch) return true;
                
                const words = searchString.split(' ');
                const partialMatch = queryParts.some(part => {
                    return words.some(word => word.startsWith(part) || word.endsWith(part));
                });
                
                return partialMatch;
            }).slice(0, 8);
        },

        renderResults(searchId, results) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            if (results.length === 0) {
                this.showEmpty(searchId);
                return;
            }

            const html = results.map((r, index) => `
                <div class="global-search-item" data-search-item data-index="${index}" data-result='${JSON.stringify(r)}'>
                    <div class="global-search-item-avatar">
                        ${this.getInitials(r.nombre)}
                    </div>
                    <div class="global-search-item-content">
                        <div class="global-search-item-name">${this.highlightMatch(r.nombre, instance.input.value)}</div>
                        <div class="global-search-item-meta">
                            <span>${r.puesto}</span>
                            <span class="separator">•</span>
                            <span>DNI: ${r.dni}</span>
                        </div>
                    </div>
                    ${r.supervisor ? '<span class="global-search-item-badge">Supervisor</span>' : ''}
                </div>
            `).join('');

            instance.results.innerHTML = html;
            instance.results.style.display = 'block';
            instance.loading.style.display = 'none';
            instance.empty.style.display = 'none';
            instance.isOpen = true;

            instance.results.querySelectorAll('[data-search-item]').forEach(item => {
                item.addEventListener('click', () => this.selectItem(searchId, item));
            });
        },

        highlightMatch(text, query) {
            if (!query) return text;
            const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
            return text.replace(regex, '<strong>$1</strong>');
        },

        escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        getInitials(name) {
            return name.split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
        },

        showLoading(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            instance.results.style.display = 'none';
            instance.loading.style.display = 'flex';
            instance.empty.style.display = 'none';
            instance.dropdown.style.display = 'block';
            instance.isOpen = true;
        },

        showEmpty(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            instance.results.style.display = 'none';
            instance.loading.style.display = 'none';
            instance.empty.style.display = 'flex';
            instance.dropdown.style.display = 'block';
            instance.isOpen = true;
        },

        openDropdown(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            if (instance.input.value.length >= instance.minChars) {
                instance.dropdown.style.display = 'block';
                instance.isOpen = true;
            }
        },

        closeDropdown(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            instance.dropdown.style.display = 'none';
            instance.isOpen = false;
        },

        clearSearch(searchId) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            instance.input.value = '';
            this.closeDropdown(searchId);
            
            if (instance.clearBtn) {
                instance.clearBtn.style.display = 'none';
            }

            instance.input.dispatchEvent(new CustomEvent('search:clear', { bubbles: true }));
        },

        selectItem(searchId, itemElement) {
            const instance = this.instances.get(searchId);
            if (!instance) return;

            const resultData = JSON.parse(itemElement.dataset.result);
            
            instance.input.dispatchEvent(new CustomEvent('search:select', { 
                bubbles: true,
                detail: { 
                    item: resultData,
                    searchId
                }
            }));

            instance.input.value = resultData.nombre;
            if (instance.clearBtn) {
                instance.clearBtn.style.display = 'flex';
            }
            this.closeDropdown(searchId);
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-search-input]').forEach(input => {
            SearchManager.init(input);
        });
    });

    window.GlobalSearch = SearchManager;
})();
</script>
