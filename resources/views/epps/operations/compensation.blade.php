@extends('layouts.app')
@section('title', 'Compensacion EPP - Proserge')
@section('content')
<div class="epp-operation"><section class="epp-operation-card">
<h1>Compensacion administrativa</h1>
@include('epps.operations.messages')
<p>{{ $operation->tipo }} — {{ $operation->id }}</p>
<p>{{ $operation->personal->nombre_completo }} — {{ $operation->almacen->nombre }}</p>
<p>Motivo: {{ $operation->motivo }}</p>
<p>Esta compensacion conserva el historial original. No contiene una nueva firma del trabajador.</p>
@foreach($operation->detalles as $detail)<p>{{ $detail->epp->nombre }}: {{ $detail->cantidad_procesada }} unidades compensadas. @if($detail->entrega_origen_id)Entrega origen: {{ $detail->entrega_origen_id }}.@endif</p>@endforeach
@if($operation->operacion_origen_id)<a class="epp-operation-button" href="{{ route('epps.operations.show', $operation->operacion_origen_id) }}">Ver operacion original</a>@endif
<a class="epp-operation-button" href="{{ route('logistica.index', ['tab' => 'entregas']) }}">Volver a entregas</a>
</section></div>
@endsection
