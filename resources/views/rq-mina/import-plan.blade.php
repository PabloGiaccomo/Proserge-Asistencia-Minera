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
@endphp

@section('content')

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">Importar Plan Operativo</h1>
            <p class="page-subtitle">{{ $item['lugar'] ?? '-' }} | {{ $semanaLabel }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('rq-mina.plan', $item['id']) }}" class="btn btn-outline">Volver</a>
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
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Archivo Excel</h3>
    </div>
    <div class="card-body">
        <div class="rq-import-drop">
            <label class="form-label" for="plan_operativo_excel">Plan operativo</label>
            <input id="plan_operativo_excel" class="rq-import-file" type="file" accept=".xlsx,.xls,.csv" disabled>
        </div>
        <div class="rq-import-actions">
            <a href="{{ route('rq-mina.plan', $item['id']) }}" class="btn btn-outline">Cancelar</a>
            <button type="button" class="btn btn-primary" disabled>Importar</button>
        </div>
    </div>
</div>
@endsection
