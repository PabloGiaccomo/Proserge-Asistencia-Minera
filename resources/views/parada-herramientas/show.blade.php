@extends('layouts.app')

@section('title', 'Lista de Herramientas')

@php
    $puedeEditar = (bool) ($item['puede_editar'] ?? false);
    $puedeActualizarPedido = (bool) ($item['puede_actualizar_pedido'] ?? false);
    $dias = (int) ($item['dias_para_limite'] ?? 0);
    $deadlineClass = $dias < 0 ? 'expired' : ($dias <= 2 ? 'urgent' : 'ok');
    $formAction = $puedeEditar
        ? route('herramientas-parada.save', $item['rq_mina_id'])
        : ($puedeActualizarPedido ? route('herramientas-parada.pedido', $item['rq_mina_id']) : route('herramientas-parada.show', $item['rq_mina_id']));
    $pedidoTotal = 0;
    $pedidoCompleto = 0;
    foreach (($item['grupos'] ?? []) as $grupo) {
        foreach (['base', 'adicional'] as $tipo) {
            foreach (($grupo[$tipo] ?? []) as $row) {
                $pedidoTotal++;
                if (!empty($row['pedido_solicitado_at']) && !empty($row['pedido_llego_at'])) {
                    $pedidoCompleto++;
                }
            }
        }
    }
@endphp

@section('content')
<div class="tools-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">Lista de Herramientas</h1>
            <p class="page-subtitle">{{ $item['lugar'] ?? '-' }} | Semana {{ $item['semana'] ?? '-' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('herramientas-parada.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="tools-summary">
        <div class="summary-item">
            <span>Parada</span>
            <strong>{{ $item['area'] ?? '-' }}</strong>
        </div>
        <div class="summary-item">
            <span>Semana ISO</span>
            <strong>{{ $item['semana'] ?? '-' }} / {{ $item['anio_semana'] ?? '-' }}</strong>
        </div>
        <div class="summary-item">
            <span>Fechas</span>
            <strong>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</strong>
        </div>
        <div class="summary-item deadline {{ $deadlineClass }}">
            <span>Limite envio</span>
            <strong>{{ $item['fecha_limite_envio'] ?? '-' }}</strong>
            <small>
                @if($dias < 0)
                    Vencido hace {{ abs($dias) }} dia(s)
                @elseif($dias === 0)
                    Vence hoy
                @else
                    Faltan {{ $dias }} dia(s)
                @endif
            </small>
        </div>
        <div class="summary-item">
            <span>Estado</span>
            <strong>{{ ucfirst(strtolower($item['estado_lista'] ?? 'borrador')) }}</strong>
        </div>
        <div class="summary-item">
            <span>Pedido completado</span>
            <strong>{{ $pedidoCompleto }} / {{ $pedidoTotal }}</strong>
        </div>
        <div class="summary-item">
            <span>Supervisor responsable</span>
            <strong>{{ $item['supervisor_responsable']['nombre'] ?? '-' }}</strong>
            <small>{{ $item['supervisor_responsable']['correo'] ?? 'Sin correo' }}</small>
        </div>
    </div>

    @unless($puedeEditar || $puedeActualizarPedido)
        <div class="alert alert-error">Esta lista no esta editable porque fue enviada o ya vencio el plazo.</div>
    @endunless

    <form method="POST" action="{{ $formAction }}" id="toolsForm">
        @csrf

        <div class="tools-card">
            <div class="tools-card-header">
                <div>
                    <h2>Grupos y herramientas</h2>
                    <span>Equipos, herramientas y utillaje solicitados</span>
                </div>
                @if($puedeEditar)
                    <button type="button" class="btn-filter-outline" onclick="addToolGroup()">Agregar grupo</button>
                @endif
            </div>

            <div class="tools-groups" id="toolsGroups">
                @foreach(($item['grupos'] ?? []) as $groupIndex => $group)
                    <div class="tool-group" data-group-index="{{ $groupIndex }}">
                            <div class="tool-group-head">
                                <div class="group-name-fields">
                                <input type="hidden" name="grupos[{{ $groupIndex }}][grupo_trabajo_id]" value="{{ $group['grupo_trabajo_id'] ?? '' }}">
                                <div class="form-group">
                                    <label class="form-label">Grupo</label>
                                    <input type="text" name="grupos[{{ $groupIndex }}][nombre]" class="form-control" value="{{ $group['nombre'] ?? '' }}" @readonly(!$puedeEditar)>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Observaciones grupo</label>
                                    <input type="text" name="grupos[{{ $groupIndex }}][observaciones]" class="form-control" value="{{ $group['observaciones'] ?? '' }}" @readonly(!$puedeEditar)>
                                </div>
                                </div>
                                <button
                                    type="submit"
                                    form="toolReminderForm-{{ $group['id'] }}"
                                    class="btn-row btn-row-outline"
                                    onclick="return confirm('Enviar correo al supervisor responsable para este grupo?');"
                                >
                                    Correo supervisor
                                </button>
                                @if($puedeEditar)
                                    <button type="button" class="btn-row btn-danger" onclick="this.closest('.tool-group').remove()">Quitar grupo</button>
                                @endif
                        </div>

                        <div class="tool-list-block">
                            <div class="tool-list-title">
                                <h3>Equipos / herramientas / utillaje</h3>
                                @if($puedeEditar)
                                    <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'base')">Agregar fila</button>
                                @endif
                            </div>
                            <div class="tool-list" data-list-type="base">
                                @php $baseRows = !empty($group['base'] ?? []) ? $group['base'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'observaciones' => '']]; @endphp
                                @foreach($baseRows as $rowIndex => $row)
                                    @include('parada-herramientas.partials.tool-row', [
                                        'groupIndex' => $groupIndex,
                                        'type' => 'base',
                                        'rowIndex' => $rowIndex,
                                        'row' => $row,
                                        'puedeEditar' => $puedeEditar,
                                        'puedeActualizarPedido' => $puedeActualizarPedido,
                                    ])
                                @endforeach
                            </div>
                        </div>

                        <div class="tool-list-block additional">
                            <div class="tool-list-title">
                                <h3>Herramientas adicionales</h3>
                                @if($puedeEditar)
                                    <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'adicional')">Agregar fila</button>
                                @endif
                            </div>
                            <div class="tool-list" data-list-type="adicional">
                                @php $additionalRows = !empty($group['adicional'] ?? []) ? $group['adicional'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'observaciones' => '']]; @endphp
                                @foreach($additionalRows as $rowIndex => $row)
                                    @include('parada-herramientas.partials.tool-row', [
                                        'groupIndex' => $groupIndex,
                                        'type' => 'adicional',
                                        'rowIndex' => $rowIndex,
                                        'row' => $row,
                                        'puedeEditar' => $puedeEditar,
                                        'puedeActualizarPedido' => $puedeActualizarPedido,
                                    ])
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="tools-card notes-card">
            <div class="form-group">
                <label class="form-label">Observaciones generales</label>
                <textarea name="observaciones" class="form-control" rows="3" @readonly(!$puedeEditar)>{{ old('observaciones', $item['observaciones'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="form-actions tools-actions">
            @if($puedeEditar)
                <button type="submit" class="btn btn-primary">Guardar borrador</button>
            @elseif($puedeActualizarPedido)
                <button type="submit" class="btn btn-primary">Actualizar pedido</button>
            @endif
        </div>
    </form>

    @if($puedeEditar)
        <form method="POST" action="{{ route('herramientas-parada.enviar', $item['rq_mina_id']) }}" class="send-form" onsubmit="return confirm('Enviar esta lista de herramientas?');">
            @csrf
            <button type="submit" class="btn btn-primary">Enviar lista</button>
        </form>
    @endif

    @foreach(($item['grupos'] ?? []) as $group)
        <form id="toolReminderForm-{{ $group['id'] }}" method="POST" action="{{ route('herramientas-parada.recordar-supervisor', [$item['rq_mina_id'], $group['id']]) }}" style="display:none;">
            @csrf
        </form>
    @endforeach
</div>

@if($puedeEditar)
<script>
let toolGroupIndex = {{ count($item['grupos'] ?? []) }};
const pedidoReadonlyAttr = {{ $puedeActualizarPedido ? "''" : "'readonly'" }};

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
}

function toolRowTemplate(groupIndex, type, rowIndex, row = {}) {
    const prefix = 'grupos[' + groupIndex + '][' + type + '][' + rowIndex + ']';
    return `
        <div class="tool-row">
            <input type="hidden" name="${prefix}[id]" value="${escapeHtml(row.id || '')}">
            <input type="text" name="${prefix}[descripcion]" class="form-control" placeholder="Descripcion" value="${escapeHtml(row.descripcion || '')}">
            <input type="number" name="${prefix}[cantidad_solicitada]" class="form-control qty" min="1" value="${escapeHtml(row.cantidad_solicitada || 1)}">
            <input type="text" name="${prefix}[observaciones]" class="form-control" placeholder="Observaciones" value="${escapeHtml(row.observaciones || '')}">
            <input type="date" name="${prefix}[pedido_solicitado_at]" class="form-control" value="${escapeHtml(row.pedido_solicitado_at || '')}" ${pedidoReadonlyAttr}>
            <input type="date" name="${prefix}[pedido_llego_at]" class="form-control" value="${escapeHtml(row.pedido_llego_at || '')}" ${pedidoReadonlyAttr}>
            <span class="tools-status pending">Pedido pendiente</span>
            <button type="button" class="btn-remove-tool" onclick="this.closest('.tool-row').remove()">Quitar</button>
        </div>
    `;
}

function addToolRow(button, type) {
    const group = button.closest('.tool-group');
    const groupIndex = group.dataset.groupIndex;
    const list = group.querySelector('[data-list-type="' + type + '"]');
    const rowIndex = list.querySelectorAll('.tool-row').length;
    list.insertAdjacentHTML('beforeend', toolRowTemplate(groupIndex, type, rowIndex));
}

function addToolGroup() {
    const groupIndex = toolGroupIndex++;
    const html = `
        <div class="tool-group" data-group-index="${groupIndex}">
            <div class="tool-group-head">
                <div class="group-name-fields">
                    <input type="hidden" name="grupos[${groupIndex}][grupo_trabajo_id]" value="">
                    <div class="form-group">
                        <label class="form-label">Grupo</label>
                        <input type="text" name="grupos[${groupIndex}][nombre]" class="form-control" value="Grupo ${groupIndex + 1}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Observaciones grupo</label>
                        <input type="text" name="grupos[${groupIndex}][observaciones]" class="form-control">
                    </div>
                </div>
                <button type="button" class="btn-row btn-danger" onclick="this.closest('.tool-group').remove()">Quitar grupo</button>
            </div>
            <div class="tool-list-block">
                <div class="tool-list-title">
                    <h3>Equipos / herramientas / utillaje</h3>
                    <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'base')">Agregar fila</button>
                </div>
                <div class="tool-list" data-list-type="base">${toolRowTemplate(groupIndex, 'base', 0)}</div>
            </div>
            <div class="tool-list-block additional">
                <div class="tool-list-title">
                    <h3>Herramientas adicionales</h3>
                    <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'adicional')">Agregar fila</button>
                </div>
                <div class="tool-list" data-list-type="adicional">${toolRowTemplate(groupIndex, 'adicional', 0)}</div>
            </div>
        </div>
    `;

    document.getElementById('toolsGroups').insertAdjacentHTML('beforeend', html);
}
</script>
@endif
@endsection
