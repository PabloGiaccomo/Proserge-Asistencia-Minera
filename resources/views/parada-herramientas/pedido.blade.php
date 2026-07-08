@extends('layouts.app')

@section('title', 'Pedido de herramientas')

@php
    $puedeActualizarPedido = (bool) ($item['puede_actualizar_pedido'] ?? false);
    $puedeCompletarRequerimiento = (bool) ($item['puede_completar_requerimiento'] ?? ($item['puede_editar'] ?? false));
    $modo = in_array(($modo ?? 'entrega'), ['entrega', 'recepcion'], true) ? $modo : 'entrega';
    $esRecepcion = $modo === 'recepcion';
    $tituloPedido = $esRecepcion ? 'Recepcion final' : 'Entregas durante la parada';
    $textoPedido = $esRecepcion
        ? 'Registra que materiales regresaron, cuales quedaron incompletos y cuales no llegaron.'
        : 'Registra cantidades entregadas y anota cambios, devoluciones, roturas o ajustes durante la parada.';
    $fechaInicioParada = !empty($item['fecha_inicio'] ?? null) ? \Illuminate\Support\Carbon::parse($item['fecha_inicio'])->startOfDay() : null;
    $fechaFinParada = !empty($item['fecha_fin'] ?? null) ? \Illuminate\Support\Carbon::parse($item['fecha_fin'])->startOfDay() : null;
    $hoyPedido = now()->startOfDay();
    $paradaIniciada = (bool) ($item['parada_iniciada'] ?? ($fechaInicioParada ? $hoyPedido->gte($fechaInicioParada) : false));
    $paradaFinalizada = (bool) ($item['parada_finalizada'] ?? ($fechaFinParada ? $hoyPedido->gte($fechaFinParada) : false));
    $bloquearEdicionPorFase = (!$esRecepcion && !$paradaIniciada) || ($esRecepcion && !$paradaFinalizada);
    $fechaRecepcion = old('fecha_recepcion', $item['fecha_recepcion_default'] ?? now()->toDateString());
    $buckets = [
        'base' => 'Herramientas',
        'adicional' => 'Herramientas adicionales',
        'consumibles_base' => 'Consumibles',
        'consumibles_adicional' => 'Consumibles adicionales',
    ];
    $recepcionEstados = [
        'PENDIENTE' => 'Pendiente',
        'COMPLETO' => 'Completo',
        'INCOMPLETO' => 'Incompleto',
        'NO_LLEGO' => 'No llego',
    ];
    $totalSolicitado = 0;
    $totalEntregado = 0;
    $totalRecibido = 0;
    $totalFaltante = 0;
    foreach (($item['grupos'] ?? []) as $grupo) {
        foreach (array_keys($buckets) as $bucket) {
            foreach (($grupo[$bucket] ?? []) as $row) {
                $solicitado = (int) ($row['cantidad_solicitada'] ?? 0);
                $entregado = (int) ($row['cantidad_entregada'] ?? 0);
                $recibido = (int) ($row['cantidad_recibida'] ?? 0);
                $totalSolicitado += $solicitado;
                $totalEntregado += $entregado;
                $totalRecibido += $recibido;
                $totalFaltante += $esRecepcion ? max(0, $entregado - $recibido) : max(0, $solicitado - $entregado);
            }
        }
    }
@endphp

@section('content')
<div class="tools-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">{{ $tituloPedido }}</h1>
            <p class="page-subtitle">{{ $item['lugar'] ?? '-' }} | Semana {{ $item['semana'] ?? '-' }} | {{ $textoPedido }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('herramientas-parada.index') }}" class="btn btn-outline">Volver</a>
            @if($puedeCompletarRequerimiento)
                <a href="{{ route('herramientas-parada.show', $item['rq_mina_id']) }}" class="btn btn-outline">Completar requerimiento</a>
            @else
                <span class="btn btn-outline is-disabled" aria-disabled="true" title="El limite de envio vencio o la lista ya fue enviada.">Requerimiento cerrado</span>
            @endif
            <a href="{{ route('herramientas-parada.confirmar-pedido', [$item['rq_mina_id'], 'modo' => $esRecepcion ? 'entrega' : 'recepcion']) }}" class="btn btn-outline">
                {{ $esRecepcion ? 'Ver entregas' : 'Registrar recepcion' }}
            </a>
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
            <span>Fechas</span>
            <strong>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</strong>
        </div>
        <div class="summary-item">
            <span>Solicitado</span>
            <strong>{{ $totalSolicitado }}</strong>
        </div>
        <div class="summary-item">
            <span>Entregado</span>
            <strong id="pedidoTotalEntregado">{{ $totalEntregado }}</strong>
        </div>
        <div class="summary-item">
            <span>Recibido</span>
            <strong id="pedidoTotalRecibido">{{ $totalRecibido }}</strong>
        </div>
        <div class="summary-item">
            <span>{{ $esRecepcion ? 'Falta regresar' : 'Falta entregar' }}</span>
            <strong id="pedidoTotalFaltante">{{ $totalFaltante }}</strong>
        </div>
    </div>

    <div class="pedido-phase-note">
        <strong>{{ $esRecepcion ? 'Cierre de parada' : 'Seguimiento durante parada' }}</strong>
        <span>
            @if($esRecepcion)
                Usa la fecha de recepcion y marca cada item como completo, incompleto o no llego. La descripcion es opcional.
            @else
                Puedes registrar entregas y dejar una incidencia opcional si algo se rompio, se devolvio o cambio durante la parada.
            @endif
        </span>
    </div>

    @unless($puedeActualizarPedido)
        <div class="alert alert-error">Puedes revisar el pedido, pero no tienes permiso para registrar entregas o recepciones.</div>
    @endunless
    @if($bloquearEdicionPorFase)
        <div class="alert alert-error">
            @if($esRecepcion)
                La recepcion final se habilita cuando termina la parada.
            @else
                Las entregas se habilitan cuando inicia la parada.
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('herramientas-parada.pedido', $item['rq_mina_id']) }}" id="pedidoConfirmForm" data-mode="{{ $modo }}">
        @csrf
        <input type="hidden" name="modo" value="{{ $modo }}">

        @if($esRecepcion)
            <div class="tools-card pedido-reception-date">
                <div>
                    <label class="form-label" for="fechaRecepcion">Fecha de recepcion</label>
                    <p>Se coloca la fecha de hoy por defecto, pero puedes cambiarla si el retorno fue otro dia.</p>
                </div>
                <input
                    id="fechaRecepcion"
                    type="date"
                    name="fecha_recepcion"
                    class="form-control"
                    value="{{ $fechaRecepcion }}"
                    @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                >
            </div>
        @endif

        @foreach(($item['grupos'] ?? []) as $groupIndex => $group)
            <section class="tools-card pedido-card">
                <div class="tools-card-header">
                    <div>
                        <h2>{{ $group['nombre'] ?? 'Grupo' }}</h2>
                        <span>{{ $group['observaciones'] ?? 'Sin observaciones del grupo' }}</span>
                    </div>
                </div>

                @foreach($buckets as $bucket => $label)
                    @php $rows = $group[$bucket] ?? []; @endphp
                    @if(!empty($rows))
                        <div class="pedido-section">
                            <h3>{{ $label }}</h3>
                            <div class="pedido-table-wrap">
                                <table class="pedido-table {{ $esRecepcion ? 'is-reception' : 'is-delivery' }}">
                                    <thead>
                                        <tr>
                                            <th>Descripcion</th>
                                            <th>Solicitado</th>
                                            <th>Entregado</th>
                                            @if($esRecepcion)
                                                <th>Estado recepcion</th>
                                                <th>Recibido</th>
                                                <th>Faltante</th>
                                                <th>Descripcion recepcion</th>
                                            @else
                                                <th>Faltante</th>
                                                <th>Incidencia durante parada</th>
                                                <th>Observacion base</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $rowIndex => $row)
                                            @php
                                                $solicitado = (int) ($row['cantidad_solicitada'] ?? 0);
                                                $entregado = (int) ($row['cantidad_entregada'] ?? 0);
                                                $recibido = (int) ($row['cantidad_recibida'] ?? 0);
                                                $estadoRecepcion = $row['recepcion_estado'] ?? 'PENDIENTE';
                                            @endphp
                                            <tr class="pedido-row">
                                                <td>
                                                    <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][id]" value="{{ $row['id'] ?? '' }}">
                                                    <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][pedido_solicitado_at]" value="{{ $row['pedido_solicitado_at'] ?? '' }}">
                                                    <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][pedido_llego_at]" value="{{ $row['pedido_llego_at'] ?? '' }}">
                                                    <strong>{{ $row['descripcion'] ?? '-' }}</strong>
                                                    @if(!empty($row['unidad']))
                                                        <span>{{ $row['unidad'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="pedido-requested" data-requested="{{ $solicitado }}">{{ $solicitado }}</td>
                                                <td>
                                                    @if($esRecepcion)
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_entregada]" value="{{ $entregado }}">
                                                        <span class="pedido-static-number pedido-delivered-static" data-delivered="{{ $entregado }}">{{ $entregado }}</span>
                                                    @else
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_entregada]"
                                                            class="form-control pedido-delivered"
                                                            value="{{ $entregado }}"
                                                            @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                        >
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_recibida]" value="{{ $recibido }}">
                                                    @endif
                                                </td>
                                                @if($esRecepcion)
                                                    <td>
                                                        <select
                                                            name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_estado]"
                                                            class="form-control pedido-state-select"
                                                            @disabled(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                        >
                                                            @foreach($recepcionEstados as $estado => $label)
                                                                <option value="{{ $estado }}" @selected($estadoRecepcion === $estado)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                            <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_estado]" value="{{ $estadoRecepcion }}">
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_recibida]"
                                                            class="form-control pedido-received"
                                                            value="{{ $recibido }}"
                                                            @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                        >
                                                    </td>
                                                    <td><span class="pedido-missing">{{ max(0, $entregado - $recibido) }}</span></td>
                                                    <td>
                                                        <textarea
                                                            name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_observacion]"
                                                            class="form-control pedido-reception-note"
                                                            rows="2"
                                                            placeholder="Descripcion opcional"
                                                            @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                        >{{ $row['recepcion_observacion'] ?? '' }}</textarea>
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_fecha]" value="{{ $row['recepcion_fecha'] ?? '' }}">
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][incidencia_durante_parada]" value="{{ $row['incidencia_durante_parada'] ?? '' }}">
                                                    </td>
                                                @else
                                                    <td><span class="pedido-missing">{{ max(0, $solicitado - $entregado) }}</span></td>
                                                    <td>
                                                        <textarea
                                                            name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][incidencia_durante_parada]"
                                                            class="form-control pedido-incidence"
                                                            rows="2"
                                                            placeholder="Roto, devuelto, cambio de cantidad..."
                                                            @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase)
                                                        >{{ $row['incidencia_durante_parada'] ?? '' }}</textarea>
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_estado]" value="{{ $estadoRecepcion }}">
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_fecha]" value="{{ $row['recepcion_fecha'] ?? '' }}">
                                                        <input type="hidden" name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][recepcion_observacion]" value="{{ $row['recepcion_observacion'] ?? '' }}">
                                                    </td>
                                                    <td>{{ $row['observaciones'] ?? '-' }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach

        @if($puedeActualizarPedido && !$bloquearEdicionPorFase)
            <div class="form-actions tools-actions pedido-actions">
                <button type="submit" class="btn btn-primary">{{ $esRecepcion ? 'Guardar recepcion' : 'Guardar entregas' }}</button>
            </div>
        @endif
    </form>
</div>

<script>
function parsePedidoNumber(value) {
    const number = parseInt(value, 10);
    return Number.isFinite(number) && number > 0 ? number : 0;
}

function rowDelivered(row) {
    const deliveredInput = row.querySelector('.pedido-delivered');
    const deliveredStatic = row.querySelector('.pedido-delivered-static');

    return parsePedidoNumber(deliveredInput?.value || deliveredStatic?.dataset.delivered || '0');
}

function recalculatePedido() {
    const form = document.getElementById('pedidoConfirmForm');
    const isReception = form?.dataset.mode === 'recepcion';
    let totalSolicitado = 0;
    let totalEntregado = 0;
    let totalRecibido = 0;
    let totalFaltante = 0;

    document.querySelectorAll('.pedido-row').forEach(row => {
        const requestedCell = row.querySelector('.pedido-requested');
        const receivedInput = row.querySelector('.pedido-received');
        const missing = row.querySelector('.pedido-missing');

        const requested = parsePedidoNumber(requestedCell?.dataset.requested || '0');
        const delivered = rowDelivered(row);
        const received = parsePedidoNumber(receivedInput?.value || '0');
        const missingAmount = isReception ? Math.max(0, delivered - received) : Math.max(0, requested - delivered);

        totalSolicitado += requested;
        totalEntregado += delivered;
        totalRecibido += received;
        totalFaltante += missingAmount;

        if (missing) {
            missing.textContent = missingAmount;
        }
    });

    document.getElementById('pedidoTotalEntregado').textContent = totalEntregado;
    document.getElementById('pedidoTotalRecibido').textContent = totalRecibido;
    document.getElementById('pedidoTotalFaltante').textContent = totalFaltante;
}

document.querySelectorAll('.pedido-delivered, .pedido-received').forEach(input => {
    input.addEventListener('input', recalculatePedido);
});

document.querySelectorAll('.pedido-state-select').forEach(select => {
    select.addEventListener('change', function () {
        const row = this.closest('.pedido-row');
        const receivedInput = row?.querySelector('.pedido-received');
        if (!(receivedInput instanceof HTMLInputElement)) {
            return;
        }

        if (this.value === 'COMPLETO') {
            receivedInput.value = rowDelivered(row);
        }

        if (this.value === 'NO_LLEGO') {
            receivedInput.value = 0;
        }

        recalculatePedido();
    });
});
</script>
@endsection
