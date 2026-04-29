@extends('layouts.app')

@section('title', 'Nuevo Bloqueo - Bienestar')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Nuevo bloqueo</h1>
                <p class="page-subtitle">Registrar indisponibilidad de trabajador</p>
            </div>
            <a href="{{ route('bienestar.index') }}" class="btn btn-outline btn-sm">Volver</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Datos del bloqueo</span></div>
        <div class="card-body">
            <form method="POST" action="{{ route('bienestar.bloqueos.store-general') }}" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; align-items:end;">
                @csrf

                <div style="grid-column:1 / -1;">
                    <label class="filter-label">Trabajador</label>
                    @php
                        $oldPersonalId = old('personal_id');
                        $selectedTrabajador = $trabajadores->firstWhere('id', $oldPersonalId);
                    @endphp
                    <input type="hidden" name="personal_id" id="personalIdInput" value="{{ $oldPersonalId }}" required>
                    <input
                        type="text"
                        id="trabajadorSearchInput"
                        class="form-control"
                        placeholder="Escribe nombre o DNI..."
                        value="{{ $selectedTrabajador ? ($selectedTrabajador->nombre_completo . ' - ' . $selectedTrabajador->dni) : '' }}"
                        autocomplete="off"
                    >
                    <div id="trabajadorSearchResults" class="card" style="margin-top:8px; border:1px solid #e2e8f0; max-height:220px; overflow:auto; display:none;"></div>
                    <small class="text-muted" id="trabajadorSearchHint">Selecciona un trabajador de la lista.</small>
                </div>

                <div>
                    <label class="filter-label">Tipo</label>
                    <select name="tipo" id="tipoBloqueo" class="form-control" required>
                        <option value="vacaciones">Vacaciones</option>
                        <option value="descanso_medico">Descanso médico</option>
                        <option value="inhabilitado">Inhabilitado</option>
                        <option value="restriccion_temporal">Restricción temporal</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
                <div id="otroTipoWrapper" style="display:none;">
                    <label class="filter-label">Otro tipo</label>
                    <input type="text" name="otro_tipo" class="form-control" value="{{ old('otro_tipo') }}" placeholder="Ej: licencia especial">
                </div>
                <div>
                    <label class="filter-label">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ old('fecha_inicio') }}" required>
                </div>
                <div>
                    <label class="filter-label">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ old('fecha_fin') }}" required>
                </div>
                <div style="grid-column:1 / -1;">
                    <label class="filter-label">Motivo</label>
                    <input type="text" name="motivo" class="form-control" value="{{ old('motivo') }}" placeholder="Motivo breve" required>
                </div>
                <div style="grid-column:1 / -1;">
                    <label class="filter-label">Detalle</label>
                    <textarea name="detalle" class="form-control" rows="3" placeholder="Detalle adicional">{{ old('detalle') }}</textarea>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary btn-sm">Registrar bloqueo</button>
                    <a href="{{ route('bienestar.index') }}" class="btn btn-outline btn-sm">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@php
    $trabajadoresSearchData = $trabajadores->map(function ($t) {
        return [
            'id' => $t->id,
            'nombre' => $t->nombre_completo,
            'dni' => $t->dni,
            'estado' => strtoupper((string) $t->estado),
        ];
    })->values();
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipo = document.getElementById('tipoBloqueo');
    const wrapper = document.getElementById('otroTipoWrapper');
    if (!tipo || !wrapper) {
        return;
    }

    const toggle = function () {
        wrapper.style.display = tipo.value === 'otro' ? 'block' : 'none';
    };

    tipo.addEventListener('change', toggle);
    toggle();

    const trabajadores = @json($trabajadoresSearchData);

    const searchInput = document.getElementById('trabajadorSearchInput');
    const personalIdInput = document.getElementById('personalIdInput');
    const resultsWrap = document.getElementById('trabajadorSearchResults');
    const hint = document.getElementById('trabajadorSearchHint');

    if (!searchInput || !personalIdInput || !resultsWrap) {
        return;
    }

    const normalizeText = function(text) {
        return (text || '').toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const filtrarTrabajadores = function(query) {
        if (!query || query.length < 2) {
            return trabajadores.slice(0, 50);
        }

        const q = normalizeText(query);
        const tokens = q.split(' ').filter(function(p) { return p.length > 0; });

        return trabajadores.filter(function(t) {
            const textoCompleto = (t.nombre || '') + ' ' + (t.dni || '') + ' ' + (t.estado || '');
            const textoNorm = normalizeText(textoCompleto);

            return tokens.every(function(token) {
                return textoNorm.includes(token);
            });
        }).slice(0, 50);
    };

    const renderResults = function(items) {
        if (!items.length) {
            resultsWrap.innerHTML = '<div style="padding:10px 12px; color:#64748b;">Sin resultados</div>';
            resultsWrap.style.display = 'block';
            return;
        }

        resultsWrap.innerHTML = items.map(function(t) {
            return '<button type="button" data-id="' + t.id + '" data-label="' + (t.nombre + ' - ' + t.dni).replace(/"/g, '&quot;') + '" style="width:100%; text-align:left; padding:10px 12px; border:none; background:#fff; cursor:pointer; border-bottom:1px solid #f1f5f9;">'
                + '<div style="font-weight:600; color:#0f172a;">' + t.nombre + '</div>'
                + '<div style="font-size:12px; color:#64748b;">DNI: ' + t.dni + ' • ' + (t.estado || 'ACTIVO') + '</div>'
                + '</button>';
        }).join('');

        resultsWrap.style.display = 'block';
    };

    searchInput.addEventListener('input', function() {
        const query = searchInput.value.trim();
        personalIdInput.value = '';
        if (hint) {
            hint.textContent = 'Selecciona un trabajador de la lista.';
        }
        renderResults(filtrarTrabajadores(query));
    });

    searchInput.addEventListener('focus', function() {
        renderResults(filtrarTrabajadores(searchInput.value.trim()));
    });

    resultsWrap.addEventListener('click', function(event) {
        const btn = event.target.closest('button[data-id]');
        if (!btn) {
            return;
        }

        personalIdInput.value = btn.dataset.id || '';
        searchInput.value = btn.dataset.label || '';
        resultsWrap.style.display = 'none';

        if (hint) {
            hint.textContent = 'Trabajador seleccionado.';
        }
    });

    document.addEventListener('click', function(event) {
        if (!resultsWrap.contains(event.target) && event.target !== searchInput) {
            resultsWrap.style.display = 'none';
        }
    });
});
</script>
@endpush
