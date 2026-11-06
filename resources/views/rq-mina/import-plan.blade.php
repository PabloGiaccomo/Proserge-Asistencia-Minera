@extends('layouts.app')

@section('title', 'Importar Plan Operativo')

@php
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $semanaInicio = $fechaInicio ? $fechaInicio->isoWeek() : null;
    $semanaFin = $fechaFin ? $fechaFin->isoWeek() : null;
    $anioSemana = $fechaInicio ? $fechaInicio->isoWeekYear() : null;
    $semanaLabel = $semanaInicio
        ? ($semanaFin && $semanaFin !== $semanaInicio ? 'Semana '.$semanaInicio.' - '.$semanaFin.' / '.$anioSemana : 'Semana '.$semanaInicio.' / '.$anioSemana)
        : '-';
    $selectedPlan = $item['selected_plan'] ?? null;
    $selectedPlanId = (string) ($item['selected_plan_id'] ?? ($selectedPlan['id'] ?? ''));
    $planUrl = route('rq-mina.plan', [
        'id' => $item['id'],
        'plan_id' => $selectedPlanId,
        'lista' => (array) request()->query('lista', []),
    ]);
@endphp

@section('content')

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">Importar Plan Operativo</h1>
            <p class="page-subtitle">{{ $item['lugar'] ?? '-' }} | {{ $semanaLabel }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ $planUrl }}" class="btn btn-outline">Volver al plan operativo</a>
        </div>
    </div>
</div>

<div class="rq-import-grid">
    <div class="rq-import-meta">
        <span>Lugar</span>
        <strong>{{ $item['lugar'] ?? '-' }}</strong>
    </div>
    <div class="rq-import-meta">
        <span>Parada</span>
        <strong>{{ $item['area'] ?? '-' }}</strong>
    </div>
    <div class="rq-import-meta">
        <span>Semana</span>
        <strong>{{ $semanaLabel }}</strong>
    </div>
    <div class="rq-import-meta">
        <span>Fechas</span>
        <strong>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</strong>
    </div>
    <div class="rq-import-meta">
        <span>Plan destino</span>
        <strong>{{ $selectedPlan['codigo'] ?? '-' }} - {{ $selectedPlan['nombre'] ?? '-' }}</strong>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Importación de Excel no disponible</h3>
    </div>
    <div class="card-body">
        <p>Registra las actividades y solicitudes de transporte desde el plan operativo.</p>
    </div>
</div>
@endsection
