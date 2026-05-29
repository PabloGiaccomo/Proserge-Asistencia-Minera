@extends('layouts.app')

@section('title', 'Plan Operativo Semanal')

@php
    $planOperativo = $item['plan_operativo'] ?? [];
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $semana = $fechaInicio ? $fechaInicio->isoWeek() : null;
    $anioSemana = $fechaInicio ? $fechaInicio->isoWeekYear() : null;
@endphp

@section('content')
<style>
.rqm-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.rqm-meta-item { border:1px solid #f1f5f9; border-radius:10px; padding:10px; }
.rqm-meta-label { display:block; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.rqm-meta-value { font-size:14px; color:#0f172a; font-weight:600; margin-top:4px; }
</style>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">Plan Operativo Semanal</h1>
            <p class="page-subtitle">
                {{ $item['lugar'] ?? '-' }}
                @if($semana)
                    | Semana {{ $semana }} / {{ $anioSemana }}
                @endif
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('rq-mina.plan.importar', $item['id']) }}" class="btn btn-primary">Importar plan operativo</a>
            <a href="{{ route('rq-mina.show', $item['id']) }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@include('rq-mina.partials.field-options')
@include('rq-mina.partials.personal-autocomplete')

<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <h3 class="card-title">Parada registrada</h3>
    </div>
    <div class="card-body">
        <div class="rqm-meta-grid">
            <div class="rqm-meta-item">
                <span class="rqm-meta-label">Lugar</span>
                <span class="rqm-meta-value">{{ $item['lugar'] ?? '-' }}</span>
            </div>
            <div class="rqm-meta-item">
                <span class="rqm-meta-label">Parada</span>
                <span class="rqm-meta-value">{{ $item['area'] ?? '-' }}</span>
            </div>
            <div class="rqm-meta-item">
                <span class="rqm-meta-label">Semana</span>
                <span class="rqm-meta-value">{{ $semana ? 'Semana '.$semana.' / '.$anioSemana : '-' }}</span>
            </div>
            <div class="rqm-meta-item">
                <span class="rqm-meta-label">Fechas</span>
                <span class="rqm-meta-value">{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</span>
            </div>
            <div class="rqm-meta-item" style="grid-column:1/-1;">
                <span class="rqm-meta-label">Observaciones</span>
                <span class="rqm-meta-value" style="font-weight:500;">{{ $item['observaciones'] ?: 'Sin observaciones.' }}</span>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('rq-mina.plan.update', $item['id']) }}">
    @csrf
    @method('PUT')

    @include('rq-mina.partials.plan-operativo-editor', [
        'editorId' => 'rqPlanOperativoEditor',
        'planOperativo' => $planOperativo,
        'weekNumber' => $semana,
        'weekYear' => $anioSemana,
    ])

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('rq-mina.show', $item['id']) }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar plan operativo</button>
    </div>
</form>
@endsection
