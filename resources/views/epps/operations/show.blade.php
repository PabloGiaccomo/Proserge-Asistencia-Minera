@extends('layouts.app')
@section('title', ($operation->tipo === 'DEVOLUCION' ? 'Resultado devolucion EPP' : 'Resultado entrega EPP').' - Proserge')
@section('content')
<div class="epp-operation">
    <header class="epp-operation-card"><h1>Resultado del lote</h1><p>Estado: <strong>{{ str_replace('_', ' ', $operation->estado) }}</strong></p><p>{{ $operation->motivo }}</p>
        @if(in_array($operation->estado, ['FIRMADO', 'FIRMADO_PARCIAL'], true))<p>{{ $operation->tipo === 'DEVOLUCION' ? 'Devolucion' : 'Entrega' }} registrada. Se confirmaron solo las unidades procesadas.</p>
        @elseif($operation->estado === 'RECHAZADO')<p>No se registro {{ $operation->tipo === 'DEVOLUCION' ? 'devolucion' : 'entrega' }}. El lote fue rechazado.</p>
        @elseif($operation->estado === 'PENDIENTE_STOCK')<p>No se registro entrega. Solicita disponibilidad o conciliacion del almacen antes de preparar un nuevo lote.</p>
        @elseif($operation->estado === 'EXPIRADO')<p>No se registro {{ $operation->tipo === 'DEVOLUCION' ? 'devolucion' : 'entrega' }}. La revision vencio; prepara y revisa un nuevo lote.</p>
        @endif
        @if(in_array($integrityState ?? null, ['EVIDENCIA_INVALIDA', 'FIRMA_INVALIDA', 'ARCHIVO_FALTANTE'], true))<p role="alert">Alerta de integridad: solicita revision de la evidencia. No repitas este lote.</p>@endif
    </header>
    @include('epps.operations.messages')
    @include('epps.operations.summary')
    <div class="epp-operation-actions">
        @if($operation->aceptacion)
            @allowed('epps', 'ver_evidencia')<a class="epp-operation-button" href="{{ route('epps.evidence.verify', $operation->aceptacion->codigo_verificacion) }}">Verificar evidencia</a>@endallowed
            @allowed('epps', 'descargar_constancia')<a class="epp-operation-button" href="{{ route('epps.evidence.receipt', $operation) }}">Descargar constancia PDF</a>@endallowed
        @endif
        @allowed('epps', 'preparar_entrega')<a href="{{ route('epps.operations.create', ['personal_id' => $operation->personal_id, 'almacen_id' => $operation->almacen_id]) }}" class="epp-operation-button">Preparar nueva entrega</a>@endallowed
        @allowed('epps', 'preparar_devolucion')<a href="{{ route('epps.operations.returns.create', ['personal_id' => $operation->personal_id, 'almacen_id' => $operation->almacen_id]) }}" class="epp-operation-button">Preparar devolucion</a>@endallowed
        @if($operation->estado === 'LISTO_PARA_FIRMA')
            @allowed('epps', $operation->tipo === 'DEVOLUCION' ? 'confirmar_devolucion' : 'confirmar_entrega')
                <a href="{{ route('epps.operations.review', $operation) }}" class="epp-operation-button is-primary">Revisar y firmar</a>
            @endallowed
        @endif
        @if(in_array($operation->tipo, ['ENTREGA', 'DEVOLUCION'], true) && in_array($operation->estado, ['FIRMADO', 'FIRMADO_PARCIAL'], true))
            @allowed('epps', 'corregir')
                <details class="epp-operation-card">
                    <summary>Registrar correccion compensatoria</summary>
                    <form method="POST" action="{{ route('epps.operations.correct', $operation) }}">
                        @csrf
                        <input type="hidden" name="submission_uuid" value="{{ old('submission_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                        <p>Indica 0 en las lineas que no necesitan correccion.</p>
                        @foreach($operation->detalles->where('cantidad_procesada', '>', 0) as $index => $detail)
                            @continue(($correctionLimits[$detail->id] ?? 0) <= 0)
                            <input type="hidden" name="lines[{{ $index }}][detalle_origen_id]" value="{{ $detail->id }}">
                            <label>{{ data_get($detail->snapshot_json, 'real.nombre', 'EPP') }}: cantidad a compensar
                                <input type="number" name="lines[{{ $index }}][cantidad]" min="0" max="{{ $correctionLimits[$detail->id] }}" value="0" required>
                            </label>
                        @endforeach
                        <label>Motivo <textarea name="motivo" maxlength="1000" required></textarea></label>
                        <button type="submit" class="epp-operation-button">Registrar correccion</button>
                    </form>
                </details>
            @endallowed
            @allowed('epps', 'anular')
                <form method="POST" action="{{ route('epps.operations.annul', $operation) }}">
                    @csrf
                    <input type="hidden" name="submission_uuid" value="{{ old('submission_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                    <label>Motivo de anulacion <textarea name="motivo" maxlength="1000" required></textarea></label>
                    <button type="submit" class="epp-operation-button">Anular mediante compensacion</button>
                </form>
            @endallowed
        @endif
        <a href="{{ route('logistica.index', ['tab' => 'entregas']) }}" class="epp-operation-button">Volver a Logistica</a>
    </div>
</div>
@endsection
