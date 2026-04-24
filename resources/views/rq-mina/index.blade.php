@extends('layouts.app')

@section('title', 'RQ Mina - Proserge')

@php
$items = $data['items'] ?? [];
$minaOptions = $data['minaOptions'] ?? [];
$estadoOptions = $data['estadoOptions'] ?? [];
$creadores = $data['creadores'] ?? [];

$calcPuestos = static function (array $detalle): int {
    return count($detalle);
};

$calcTotal = static function (array $detalle): int {
    return (int) array_sum(array_column($detalle, 'cantidad'));
};
@endphp

@section('content')
<div class="rq-mina-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">RQ Mina</h1>
            <p class="page-subtitle">Requerimientos de personal de mina</p>
        </div>
        <button type="button" class="btn-nuevoRQ" onclick="openModalRQ('create')">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nuevo RQ
        </button>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="filters-bar">
        <form method="GET" action="{{ route('rq-mina.index') }}" class="filters-form">
            <div class="filters-head">
                <span>Filtros de búsqueda</span>
                <button type="button" id="rqMinaToggleFilters" class="btn-filter-toggle" aria-expanded="true" aria-label="Ocultar filtros" title="Ocultar filtros">
                    <span id="rqMinaToggleArrow" aria-hidden="true">▲</span>
                </button>
            </div>

            <div id="rqMinaFiltersBody">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Mina</label>
                        <select name="mina" class="filter-select">
                            <option value="">Todas las minas</option>
                            @foreach($minaOptions as $m)
                                <option value="{{ $m }}" {{ request('mina') === $m ? 'selected' : '' }}>{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Estado</label>
                        <select name="estado" class="filter-select">
                            <option value="">Todos los estados</option>
                            @foreach($estadoOptions as $e)
                                <option value="{{ $e }}" {{ request('estado') === $e ? 'selected' : '' }}>{{ ucfirst($e) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Creador</label>
                        <select name="creador" class="filter-select">
                            <option value="">Todos los creadores</option>
                            @foreach($creadores as $c)
                                <option value="{{ $c }}" {{ request('creador') === $c ? 'selected' : '' }}>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Fecha desde</label>
                        <input type="date" name="fecha_desde" class="filter-input" value="{{ request('fecha_desde') }}">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Fecha hasta</label>
                        <input type="date" name="fecha_hasta" class="filter-input" value="{{ request('fecha_hasta') }}">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">Aplicar</button>
                        <a href="{{ route('rq-mina.index') }}" class="btn-filter-outline">Limpiar</a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header-list">
            <h2 class="card-title-list">Listado de RQ Mina</h2>
            <span class="card-badge">{{ count($items) }} registros</span>
        </div>

        @if(empty($items))
            <div class="empty-state">
                <h3>No hay requerimientos</h3>
                <p>No existen RQ Mina para los filtros seleccionados.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="rq-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Mina</th>
                            <th>Área</th>
                            <th>Fechas</th>
                            <th>Creador</th>
                            <th>Puestos</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $rq)
                            @php
                                $detalle = $rq['detalle'] ?? [];
                            @endphp
                            <tr>
                                <td>#{{ $rq['id'] ?? '-' }}</td>
                                <td>{{ $rq['mina'] ?? '-' }}</td>
                                <td>{{ $rq['area'] ?? '-' }}</td>
                                <td>{{ $rq['fecha_inicio'] ?? '-' }} al {{ $rq['fecha_fin'] ?? '-' }}</td>
                                <td>{{ $rq['creador'] ?? '-' }}</td>
                                <td>{{ $calcPuestos($detalle) }}</td>
                                <td>{{ $calcTotal($detalle) }}</td>
                                <td>
                                    <span class="estado-badge {{ $rq['estado'] ?? 'borrador' }}">{{ ucfirst($rq['estado'] ?? 'borrador') }}</span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a href="{{ route('rq-mina.show', $rq['id']) }}" class="btn-row btn-row-outline">Ver</a>
                                        <button type="button" class="btn-row btn-row-outline" onclick="openModalRQ('edit', '{{ $rq['id'] }}')">Editar</button>
                                        <button type="button" class="btn-row btn-copy" onclick="openModalRQ('copy', '{{ $rq['id'] }}')">Copiar RQ</button>

                                        @if(($rq['estado'] ?? '') === 'borrador')
                                            <form method="POST" action="{{ route('rq-mina.enviar', $rq['id']) }}" onsubmit="return confirm('¿Enviar este RQ?');">
                                                @csrf
                                                <button type="submit" class="btn-row btn-send">Enviar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<!-- Modal Nuevo RQ Mina -->
<div class="modalrq-overlay" id="modalRQ" style="display: none;">
    <div class="modalrq-container">
        <div class="modalrq-header">
            <h2 class="modalrq-title" id="modalRQTitle">Nuevo RQ Mina</h2>
            <button type="button" class="modalrq-close" onclick="closeModalRQ()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="formRQ" class="modalrq-body" method="POST" action="{{ route('rq-mina.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethodOverride" value="POST">
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Mina *</label>
                        <select name="mina" id="inputMina" class="form-select" required>
                            <option value="">Seleccionar mina</option>
                            @foreach($minaOptions as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Área *</label>
                        <input type="text" name="area" id="inputArea" class="form-input" placeholder="Ej: C2, Operación Planta" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fecha inicio *</label>
                        <input type="date" name="fecha_inicio" id="inputFechaInicio" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha fin *</label>
                        <input type="date" name="fecha_fin" id="inputFechaFin" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" id="inputObs" class="form-textarea" rows="3" placeholder="Descripción del requerimiento..."></textarea>
                </div>
            </div>
            <div class="detalle-section">
                <div class="detalle-header">
                    <h3 class="detalle-title">Detalle por puesto / cantidad</h3>
                    <button type="button" class="btn-addfila" onclick="addFila()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                       Agregar fila
                    </button>
                </div>
                <div class="detalle-lista" id="listaDetalle">
                    <div class="fila-detalle">
                        <input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" required>
                        <input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1" required>
                        <button type="button" class="btn-removefila" onclick="this.closest('.fila-detalle').remove()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <div class="modalrq-footer">
            <button type="button" class="btn-cancelarrq" onclick="closeModalRQ()">Cancelar</button>
            <button type="submit" form="formRQ" class="btn-crear" id="modalRQSubmit">Crear BORRADOR</button>
        </div>
    </div>
</div>

<script>
const rqData = @json($items);
const rqStoreUrl = @json(route('rq-mina.store'));
const rqUpdateUrlTemplate = @json(route('rq-mina.update', ['id' => '__ID__']));

function createDetalleRow(puesto, cantidad) {
    return '<div class="fila-detalle"><input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" value="' + (puesto || '') + '" required><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="' + (cantidad || 1) + '" required><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>';
}

function openModalRQ(mode = 'create', rqId = null) {
    document.getElementById('modalRQ').style.display = 'flex';
    const form = document.getElementById('formRQ');
    const title = document.getElementById('modalRQTitle');
    const submit = document.getElementById('modalRQSubmit');
    const methodOverride = document.getElementById('formMethodOverride');
    const detalle = document.getElementById('listaDetalle');

    form.reset();
    detalle.innerHTML = createDetalleRow('', 1);

    if (mode === 'edit' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Editar RQ Mina';
        submit.textContent = 'Guardar Cambios';
        form.action = rqUpdateUrlTemplate.replace('__ID__', String(rq.id));
        methodOverride.value = 'PUT';

        document.getElementById('inputMina').value = rq.mina || '';
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });
    } else if (mode === 'copy' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Copiar RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';

        document.getElementById('inputMina').value = rq.mina || '';
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });
    } else {
        title.textContent = 'Nuevo RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';
    }
}
function closeModalRQ() {
    document.getElementById('modalRQ').style.display = 'none';
}
function addFila() {
    const container = document.getElementById('listaDetalle');
    const row = document.createElement('div');
    row.className = 'fila-detalle';
    row.innerHTML = '<input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" required><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1" required><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    container.appendChild(row);
}
function saveRQ(e) {
    // Reserved for future custom validation hooks.
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModalRQ(); });
document.getElementById('modalRQ').addEventListener('click', e => { if (e.target.id === 'modalRQ') closeModalRQ(); });

document.addEventListener('DOMContentLoaded', function () {
    if (window.ProsergeUI) {
        window.ProsergeUI.initCollapsiblePanel({
            toggleButtonId: 'rqMinaToggleFilters',
            panelBodyId: 'rqMinaFiltersBody',
            iconElementId: 'rqMinaToggleArrow',
            expandedIcon: '▲',
            collapsedIcon: '▼',
            expandedLabel: 'Ocultar filtros',
            collapsedLabel: 'Mostrar filtros',
        });
    }
});
</script>

@endsection
