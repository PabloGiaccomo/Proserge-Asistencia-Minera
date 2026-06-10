@extends('layouts.app')

@section('title', 'Datos de contrato - Proserge')

@section('content')
@php
    $dateValue = fn ($field): string => old($field, optional($datos->{$field})->toDateString() ?? '');
    $textValue = fn ($field): string => (string) old($field, $datos->{$field} ?? '');
    $estadoContrato = strtoupper((string) ($contrato->estado ?? 'PREPARACION'));
@endphp

<style>
.contract-data-page { display: grid; gap: 16px; }
.contract-data-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.contract-data-wide { grid-column: 1 / -1; }
.contract-data-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.contract-data-note { margin: 0; color: #64748b; line-height: 1.45; }
</style>

<div class="module-page contract-data-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Datos de contrato</h1>
                <p class="page-subtitle">
                    {{ $personal->nombre_completo }} - {{ $personal->tipo_documento ?: 'DNI' }} {{ $personal->numero_documento ?: $personal->dni }}
                    · Contrato #{{ $contrato->contrato_numero ?? '-' }} {{ $estadoContrato === 'PREPARACION' ? 'en preparacion' : strtolower($estadoContrato) }}
                </p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('personal.contrato-datos.update', $personal->id) }}" class="card">
        @csrf
        @method('PUT')
        <div class="card-header">
            <span class="card-title">Campos para formatos de contrato</span>
        </div>
        <div class="card-body">
            <p class="contract-data-note" style="margin-bottom:14px;">Estos datos se usan para la vista previa y descarga de formatos de contrato. Puedes corregir el puesto aqui antes de generar el Excel.</p>

            <div class="contract-data-grid">
                <div class="form-group">
                    <label class="form-label">Fecha inicio contrato</label>
                    <input type="date" name="fecha_inicio_contrato" class="form-control" value="{{ $dateValue('fecha_inicio_contrato') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha fin</label>
                    <input type="date" name="fecha_fin_contrato" class="form-control" value="{{ $dateValue('fecha_fin_contrato') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Inicio prueba</label>
                    <input type="date" name="periodo_prueba_inicio" class="form-control" value="{{ $dateValue('periodo_prueba_inicio') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fin prueba</label>
                    <input type="date" name="periodo_prueba_fin" class="form-control" value="{{ $dateValue('periodo_prueba_fin') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_HORA_PARADAS</label>
                    <input type="text" name="sueldo_hora_paradas" class="form-control" value="{{ $textValue('sueldo_hora_paradas') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_HORA_PARADAS_TEXTO</label>
                    <input type="text" name="sueldo_hora_paradas_texto" class="form-control" value="{{ $textValue('sueldo_hora_paradas_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_DIA_TALLER</label>
                    <input type="text" name="sueldo_dia_taller" class="form-control" value="{{ $textValue('sueldo_dia_taller') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_DIA_TALLER_TEXTO</label>
                    <input type="text" name="sueldo_dia_taller_texto" class="form-control" value="{{ $textValue('sueldo_dia_taller_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_NUM</label>
                    <input type="text" name="sueldo_num" class="form-control" value="{{ $textValue('sueldo_num') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_TEXTO</label>
                    <input type="text" name="sueldo_texto" class="form-control" value="{{ $textValue('sueldo_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Puesto</label>
                    <input type="text" name="puesto" class="form-control" value="{{ $textValue('puesto') ?: $personal->puesto }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha en la que firmo</label>
                    <input type="date" name="fecha_firma" class="form-control" value="{{ $dateValue('fecha_firma') }}">
                </div>
                <div class="form-group contract-data-wide">
                    <label class="form-label">FUNCIONES</label>
                    <textarea name="funciones" class="form-control" rows="5">{{ $textValue('funciones') }}</textarea>
                </div>
            </div>
        </div>
        <div class="card-footer contract-data-actions">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar datos</button>
        </div>
    </form>
</div>
@endsection
