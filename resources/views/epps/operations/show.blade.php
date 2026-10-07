@extends('layouts.app')
@section('title', 'Resultado entrega EPP - Proserge')
@section('content')
<div class="epp-operation">
    <header class="epp-operation-card"><h1>Resultado del lote</h1><p>Estado: <strong>{{ str_replace('_', ' ', $operation->estado) }}</strong></p><p>{{ $operation->motivo }}</p>
        @if(in_array($operation->estado, ['FIRMADO', 'FIRMADO_PARCIAL'], true))<p>Entrega registrada. Se confirmaron solo las unidades procesadas.</p>
        @elseif($operation->estado === 'RECHAZADO')<p>No se registro entrega. El lote fue rechazado y sus reservas liberadas.</p>
        @elseif($operation->estado === 'PENDIENTE_STOCK')<p>No se registro entrega. Solicita disponibilidad o conciliacion del almacen antes de preparar un nuevo lote.</p>
        @elseif($operation->estado === 'EXPIRADO')<p>No se registro entrega. La reserva vencio; prepara y revisa un nuevo lote.</p>
        @endif
        @if(in_array($integrityState ?? null, ['EVIDENCIA_INVALIDA', 'FIRMA_INVALIDA', 'ARCHIVO_FALTANTE'], true))<p role="alert">Alerta de integridad: solicita revision de la evidencia. No repitas esta entrega.</p>@endif
    </header>
    @include('epps.operations.messages')
    @include('epps.operations.summary')
    <div class="epp-operation-actions">
        @allowed('epps', 'preparar_entrega')<a href="{{ route('epps.operations.create', ['personal_id' => $operation->personal_id, 'almacen_id' => $operation->almacen_id]) }}" class="epp-operation-button">Preparar nueva entrega</a>@endallowed
        @if($operation->estado === 'LISTO_PARA_FIRMA')
            @allowed('epps', 'confirmar_entrega')
                <a href="{{ route('epps.operations.review', $operation) }}" class="epp-operation-button is-primary">Revisar y firmar</a>
            @endallowed
        @endif
        <a href="{{ route('logistica.index', ['tab' => 'entregas']) }}" class="epp-operation-button">Volver a Logistica</a>
    </div>
</div>
@endsection
