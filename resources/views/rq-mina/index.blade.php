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
    const toggleBtn = document.getElementById('rqMinaToggleFilters');
    const toggleArrow = document.getElementById('rqMinaToggleArrow');
    const filtersBody = document.getElementById('rqMinaFiltersBody');

    if (!toggleBtn || !toggleArrow || !filtersBody) {
        return;
    }

    toggleBtn.addEventListener('click', function () {
        const isHidden = filtersBody.style.display === 'none';
        filtersBody.style.display = isHidden ? 'block' : 'none';
        toggleArrow.textContent = isHidden ? '▲' : '▼';
        toggleBtn.setAttribute('aria-label', isHidden ? 'Ocultar filtros' : 'Mostrar filtros');
        toggleBtn.setAttribute('title', isHidden ? 'Ocultar filtros' : 'Mostrar filtros');
        toggleBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    });
});
</script>

<style>
.rq-mina-page { padding: 24px; max-width: 1400px; margin: 0 auto; }
.page-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; }
.page-title { font-size: 28px; font-weight: 700; color: #1e293b; margin: 0; }
.page-subtitle { font-size: 14px; color: #64748b; margin: 4px 0 0; }

.alert { border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; font-size: 14px; }
.alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

.filters-bar { background: white; border-radius: 14px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.filters-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.filters-head span { font-size: 13px; font-weight: 700; color: #51647e; }
.filters-grid { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)) auto; gap: 10px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
.filter-select, .filter-input { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 14px; }
.filter-select:focus, .filter-input:focus { outline: none; border-color: #19d3c5; background: #fff; }
.filter-actions { display: flex; gap: 8px; }
.btn-filter { border: none; padding: 10px 14px; border-radius: 8px; background: #19d3c5; color: #fff; font-weight: 600; cursor: pointer; }
.btn-filter-outline { padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; color: #64748b; text-decoration: none; background: #fff; }
.btn-filter-toggle { border: 1px solid #d7e0ed; background: #fff; color: #3f556f; border-radius: 10px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0; font-size: 14px; font-weight: 700; cursor: pointer; }
.btn-filter-toggle:hover { background: #f8fbff; }

.card { background: #fff; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; }
.card-header-list { display: flex; justify-content: space-between; align-items: center; padding: 16px; border-bottom: 1px solid #f1f5f9; }
.card-title-list { margin: 0; font-size: 16px; font-weight: 700; color: #1e293b; }
.card-badge { font-size: 12px; color: #64748b; }

.table-wrap { overflow-x: auto; }
.rq-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.rq-table th, .rq-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; text-align: left; vertical-align: middle; }
.rq-table th { background: #f8fafc; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; }
.rq-table tbody tr:hover { background: #f8fafc; }

.estado-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.estado-badge.borrador { background: rgba(245,158,11,0.15); color: #d97706; }
.estado-badge.enviado { background: rgba(59,130,246,0.15); color: #2563eb; }

.row-actions { display: flex; gap: 6px; align-items: center; }
.row-actions form { margin: 0; }
.btn-row { border-radius: 8px; padding: 7px 10px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; }
.btn-row-outline { border-color: #e2e8f0; color: #475569; background: #fff; }
.btn-send { background: #19d3c5; color: #fff; border-color: #19d3c5; }
.btn-copy { border-color: #cbd5e1; color: #334155; background: #f8fafc; }

.empty-state { text-align: center; padding: 36px 12px; color: #64748b; }
.empty-state h3 { margin: 0 0 6px; color: #334155; }

@media (max-width: 1024px) {
    .filters-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
}

@media (max-width: 640px) {
    .rq-mina-page { padding: 12px; }
    .page-header-custom { flex-direction: column; align-items: flex-start; }
    .filters-grid { grid-template-columns: 1fr; }
}

/* ========== MODAL NUEVO RQ MINA ========== */
.btn-nuevoRQ { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #f97316; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-nuevoRQ:hover { background: #ea580c; transform: translateY(-1px); }

.modalrq-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 999; padding: 20px; }
.modalrq-container { background: white; border-radius: 20px; width: 100%; max-width: 600px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
.modalrq-header { display: flex; justify-content: space-between; align-items: center; padding: 24px 24px 20px; border-bottom: 1px solid #f1f5f9; }
.modalrq-title { font-size: 20px; font-weight: 700; color: #1e293b; margin: 0; }
.modalrq-close { width: 36px; height: 36px; border-radius: 10px; border: none; background: #f8fafc; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; transition: all 0.2s; }
.modalrq-close:hover { background: #f1f5f9; color: #1e293b; }
.modalrq-body { padding: 24px; overflow-y: auto; flex: 1; }
.form-section { margin-bottom: 24px; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.form-label { font-size: 13px; font-weight: 600; color: #475569; }
.form-select, .form-input, .form-textarea { width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; color: #1e293b; background: #f8fafc; transition: all 0.2s; }
.form-select:focus, .form-input:focus, .form-textarea:focus { outline: none; border-color: #19d3c5; background: white; box-shadow: 0 0 0 3px rgba(25,211,197,0.1); }
.form-textarea { resize: vertical; min-height: 80px; }

.detalle-section { background: #f8fafc; border-radius: 12px; padding: 20px; }
.detalle-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.detalle-title { font-size: 14px; font-weight: 600; color: #1e293b; margin: 0; }
.btn-addfila { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px dashed #19d3c5; background: transparent; color: #19d3c5; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-addfila:hover { background: rgba(25,211,197,0.1); }
.detalle-lista { display: flex; flex-direction: column; gap: 12px; }
.fila-detalle { display: grid; grid-template-columns: 1fr 100px 40px; gap: 12px; align-items: center; }
.input-puesto { padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: white; }
.input-cantidad { padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: white; text-align: center; }
.btn-removefila { width: 40px; height: 40px; border-radius: 10px; border: none; background: #fef2f2; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #dc2626; transition: all 0.2s; }
.btn-removefila:hover { background: #fee2e2; }

.modalrq-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #f1f5f9; background: #fafafa; }
.btn-cancelarrq { padding: 12px 24px; border-radius: 10px; border: 1px solid #e2e8f0; background: white; color: #64748b; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-cancelarrq:hover { border-color: #cbd5e1; color: #1e293b; }
.btn-crear { padding: 12px 28px; border-radius: 10px; border: none; background: linear-gradient(135deg, #f97316, #ea580c); color: white; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(249,115,22,0.3); }
.btn-crear:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(249,115,22,0.4); }

@media (max-width: 640px) {
    .form-row { grid-template-columns: 1fr; }
    .fila-detalle { grid-template-columns: 1fr; gap: 8px; }
    .input-cantidad { text-align: left; }
}
</style>
@endsection
