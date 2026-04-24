<div class="simple-search">
    <div class="simple-search-wrapper">
        <svg class="simple-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.35-4.35"/>
        </svg>
        <input 
            type="text" 
            class="simple-search-input {{ $inputClass ?? '' }}"
            id="{{ $searchId ?? 'simple-search' }}"
            placeholder="{{ $placeholder ?? 'Buscar por nombre, DNI, mina...' }}"
            data-simple-search
        >
        @if($showClear ?? true)
            <button class="simple-search-clear" type="button" data-simple-search-clear title="Limpiar" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        @endif
    </div>
</div>

<style>
.simple-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.simple-search-icon {
    position: absolute;
    left: 12px;
    width: 18px;
    height: 18px;
    color: #94a3b8;
    pointer-events: none;
}
.simple-search-input {
    width: 100%;
    padding: 10px 40px 10px 40px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    color: #334155;
    background: #fff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.simple-search-input:focus {
    outline: none;
    border-color: #19D3C5;
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1);
}
.simple-search-input::placeholder {
    color: #94a3b8;
}
.simple-search-clear {
    position: absolute;
    right: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    transition: all 0.15s ease;
}
.simple-search-clear:hover {
    background: #f1f5f9;
    color: #64748b;
}
</style>

<script>
(function() {
    'use strict';
    
    function normalizeText(text) {
        return (text || '').toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }
    
    function palabrasCoinciden(palabrasBusqueda, textoNormalizado) {
        if (!palabrasBusqueda.length) return true;
        
        return palabrasBusqueda.every(palabra => {
            const partes = palabra.split(' ').filter(p => p.length > 1);
            return partes.some(parte => textoNormalizado.includes(parte));
        });
    }
    
    function fuzzyMatch(query, texto) {
        const q = normalizeText(query);
        const t = normalizeText(texto);
        const palabrasQ = q.split(' ').filter(p => p.length > 1);
        
        if (!palabrasQ.length) return true;
        if (t.includes(q)) return true;
        
        return palabrasQ.every(palabra => {
            const partes = palabra.split('').filter(c => c.length > 0);
            return partes.every(char => t.includes(char));
        });
    }
    
    function filtrarTrabajadores(query, items) {
        if (!query || query.length < 2) {
            items.forEach(item => item.style.display = '');
            return;
        }
        
        const q = normalizeText(query);
        const palabrasQ = q.split(' ').filter(p => p.length > 1);
        
        items.forEach(item => {
            const nombre = item.dataset.nombre || '';
            const dni = item.dataset.dni || '';
            const puesto = item.dataset.puesto || '';
            const contrato = item.dataset.contrato || '';
            const minas = item.dataset.minas || '';
            
            const textoCompleto = `${nombre} ${dni} ${puesto} ${contrato} ${minas}`;
            const textoNorm = normalizeText(textoCompleto);
            
            let match = false;
            
            if (palabrasCoinciden(palabrasQ, textoNorm)) {
                match = true;
            }
            
            if (!match && fuzzyMatch(q, textoCompleto)) {
                match = true;
            }
            
            item.style.display = match ? '' : 'none';
        });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('[data-simple-search]');
        const clearBtn = document.querySelector('[data-simple-search-clear]');
        const items = document.querySelectorAll('.js-person-card');
        
        if (!searchInput || !items.length) return;
        
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            if (clearBtn) {
                clearBtn.style.display = query.length > 0 ? 'flex' : 'none';
            }
            
            filtrarTrabajadores(query, items);
            
            updateResultsCount();
        });
        
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                this.style.display = 'none';
                filtrarTrabajadores('', items);
                updateResultsCount();
                searchInput.focus();
            });
        }
        
        function updateResultsCount() {
            const visibleCount = Array.from(items).filter(item => item.style.display !== 'none').length;
            const countEl = document.querySelector('.card-badge');
            if (countEl) {
                countEl.textContent = visibleCount + ' trabajadores';
            }
        }
        
        updateResultsCount();
    });
})();
</script>