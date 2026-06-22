@extends('layouts.app')

@section('title', 'Confirmar pedido')

@php
    $puedeActualizarPedido = (bool) ($item['puede_actualizar_pedido'] ?? false);
    $modo = in_array(($modo ?? 'entrega'), ['entrega', 'recepcion'], true) ? $modo : 'entrega';
    $esRecepcion = $modo === 'recepcion';
    $tituloPedido = $esRecepcion ? 'Recepcion final' : 'Entregas del pedido';
    $textoPedido = $esRecepcion
        ? 'Registra las cantidades recibidas al finalizar la parada.'
        : 'Revisa y actualiza las cantidades entregadas durante la parada.';
    $fechaInicioParada = !empty($item['fecha_inicio'] ?? null) ? \Illuminate\Support\Carbon::parse($item['fecha_inicio'])->startOfDay() : null;
    $fechaFinParada = !empty($item['fecha_fin'] ?? null) ? \Illuminate\Support\Carbon::parse($item['fecha_fin'])->startOfDay() : null;
    $hoyPedido = now()->startOfDay();
    $paradaIniciada = $fechaInicioParada ? $hoyPedido->gte($fechaInicioParada) : false;
    $paradaFinalizada = $fechaFinParada ? $hoyPedido->gte($fechaFinParada) : false;
    $bloquearEdicionPorFase = (!$esRecepcion && !$paradaIniciada) || ($esRecepcion && !$paradaFinalizada);
    $buckets = [
        'base' => 'Herramientas',
        'adicional' => 'Herramientas adicionales',
        'consumibles_base' => 'Consumibles',
        'consumibles_adicional' => 'Consumibles adicionales',
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
                $totalSolicitado += $solicitado;
                $totalEntregado += $entregado;
                $totalRecibido += (int) ($row['cantidad_recibida'] ?? 0);
                $totalFaltante += max(0, $solicitado - $entregado);
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
            <a href="{{ route('herramientas-parada.show', $item['rq_mina_id']) }}" class="btn btn-outline">Completar requerimiento</a>
            <a href="{{ route('herramientas-parada.confirmar-pedido', [$item['rq_mina_id'], 'modo' => $esRecepcion ? 'entrega' : 'recepcion']) }}" class="btn btn-outline">
                {{ $esRecepcion ? 'Ver entregas' : 'Recepcion final' }}
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
            <span>Faltante</span>
            <strong id="pedidoTotalFaltante">{{ $totalFaltante }}</strong>
        </div>
    </div>

    @unless($puedeActualizarPedido)
        <div class="alert alert-error">Puedes revisar el pedido, pero no tienes permiso para registrar cantidades entregadas o recibidas.</div>
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

    <form method="POST" action="{{ route('herramientas-parada.pedido', $item['rq_mina_id']) }}" id="pedidoConfirmForm">
        @csrf
        <input type="hidden" name="modo" value="{{ $modo }}">

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
                                <table class="pedido-table">
                                    <thead>
                                        <tr>
                                            <th>Descripcion</th>
                                            <th>Solicitado</th>
                                            <th>Entregado</th>
                                            <th>Recibido</th>
                                            <th>Faltante</th>
                                            <th>Observacion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $rowIndex => $row)
                                            @php
                                                $solicitado = (int) ($row['cantidad_solicitada'] ?? 0);
                                                $entregado = (int) ($row['cantidad_entregada'] ?? 0);
                                                $recibido = (int) ($row['cantidad_recibida'] ?? 0);
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
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_entregada]"
                                                        class="form-control pedido-delivered"
                                                        value="{{ $entregado }}"
                                                        @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase || $esRecepcion)
                                                    >
                                                </td>
                                                <td>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        name="grupos[{{ $groupIndex }}][{{ $bucket }}][{{ $rowIndex }}][cantidad_recibida]"
                                                        class="form-control pedido-received"
                                                        value="{{ $recibido }}"
                                                        @readonly(!$puedeActualizarPedido || $bloquearEdicionPorFase || !$esRecepcion)
                                                    >
                                                </td>
                                                <td><span class="pedido-missing">{{ max(0, $solicitado - $entregado) }}</span></td>
                                                <td>{{ $row['observaciones'] ?? '-' }}</td>
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

function recalculatePedido() {
    let totalSolicitado = 0;
    let totalEntregado = 0;
    let totalRecibido = 0;
    let totalFaltante = 0;

    document.querySelectorAll('.pedido-row').forEach(row => {
        const requestedCell = row.querySelector('.pedido-requested');
        const deliveredInput = row.querySelector('.pedido-delivered');
        const receivedInput = row.querySelector('.pedido-received');
        const missing = row.querySelector('.pedido-missing');

        const requested = parsePedidoNumber(requestedCell?.dataset.requested || '0');
        const delivered = parsePedidoNumber(deliveredInput?.value || '0');
        const received = parsePedidoNumber(receivedInput?.value || '0');

        totalSolicitado += requested;
        totalEntregado += delivered;
        totalRecibido += received;
        totalFaltante += Math.max(0, requested - delivered);

        if (missing) {
            missing.textContent = Math.max(0, requested - delivered);
        }
    });

    document.getElementById('pedidoTotalEntregado').textContent = totalEntregado;
    document.getElementById('pedidoTotalRecibido').textContent = totalRecibido;
    document.getElementById('pedidoTotalFaltante').textContent = totalFaltante;
}

document.querySelectorAll('.pedido-delivered, .pedido-received').forEach(input => {
    input.addEventListener('input', recalculatePedido);
});
</script>
@endsection
