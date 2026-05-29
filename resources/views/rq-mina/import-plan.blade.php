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
<style>
.rq-import-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px; }
.rq-import-meta { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; background:#fff; }
.rq-import-meta span { display:block; font-size:11px; color:#64748b; text-transform:uppercase; font-weight:800; }
.rq-import-meta strong { display:block; margin-top:4px; color:#0f172a; font-size:14px; }
.rq-import-drop { border:1px dashed #cbd5e1; border-radius:12px; padding:18px; background:#f8fafc; }
.rq-import-file { width:100%; border:1px solid #dbe4ef; background:#fff; border-radius:10px; padding:11px; }
.rq-import-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; flex-wrap:wrap; }
</style>

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
