@extends('layouts.app')
@section('title', 'Verificacion EPP - Proserge')
@section('content')
<div class="epp-operation"><section class="epp-operation-card">
<h1>Verificacion de constancia EPP</h1>
<p role="status">{{ $verification['valid'] ? 'FIRMADO_VALIDO' : 'EVIDENCIA_INVALIDA' }}</p>
<p>Codigo: {{ $acceptance->codigo_verificacion }}</p>
<p>Operacion: {{ $operation->id }} — {{ $operation->tipo }}</p>
<p>Trabajador: {{ data_get($acceptance->contenido_json, 'worker.name') }}</p>
<p>Firmante: {{ $acceptance->firmante_nombre }} — {{ $acceptance->tipo_firmante }}</p>
<p>Fecha: {{ $acceptance->firmado_at }} {{ $acceptance->zona_horaria }}</p>
@unless($verification['valid'])<p role="alert">La evidencia requiere revision. No repitas la operacion para corregir esta alerta.</p>@endunless
@foreach(data_get($acceptance->contenido_json, 'details', []) as $detail)<p>{{ data_get($detail, 'snapshot.real.nombre', $detail['epp_name'] ?? 'EPP') }}: {{ $detail['processed_quantity'] }} unidades procesadas; {{ $detail['pending_quantity'] ?? 0 }} pendientes.</p>@endforeach
@foreach($operation->operacionesCompensatorias as $correction)<p>{{ $correction->tipo }}: {{ $correction->id }} — {{ $correction->motivo }}</p>@endforeach
@allowed('epps', 'descargar_constancia')<a class="epp-operation-button" href="{{ route('epps.evidence.receipt', $operation) }}">Descargar constancia PDF</a>@endallowed
</section></div>
@endsection
