@extends('layouts.app')

@section('title', 'RQ Mina - Parada')

@php
$copyData = $copyData ?? null;
$selectedSupervisor = $copyData['supervisor'] ?? null;
$selectedSupervisorPets = $copyData['supervisor_pets'] ?? null;
$lugares = $lugares ?? [];
$selectedDestino = (($copyData['destino_tipo'] ?? 'MINA') . '|' . ($copyData['destino_id'] ?? $copyData['mina_id'] ?? ''));
$formMode = $formMode ?? 'create';
$formAction = $formAction ?? route('rq-mina.store');
$formMethod = $formMethod ?? 'POST';
$submitLabel = $submitLabel ?? 'Guardar Parada';
$isEdit = $formMode === 'edit';
@endphp

@section('content')
<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">{{ $isEdit ? 'Editar Parada' : 'Nueva Parada' }}</h1>
            <p class="page-subtitle">{{ $isEdit ? 'Editar datos generales de la parada' : 'Registrar lugar, fecha y observaciones de la parada' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('rq-mina.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if($copyData)
<div class="card" style="margin-bottom: 16px;">
    <div class="card-body">
        <strong>{{ $isEdit ? 'Editando parada #' : 'Copiando parada #' }}{{ $copyData['id'] }}</strong>
        <p style="margin: 6px 0 0; color: #64748b;">{{ $isEdit ? 'Puedes actualizar los datos generales.' : 'Se cargaron los datos generales para registrar una nueva parada.' }}</p>
    </div>
</div>
@endif

@include('rq-mina.partials.field-options')

<form action="{{ $formAction }}" method="POST" class="form">
    @csrf
    @if($formMethod !== 'POST')
        @method($formMethod)
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos de la parada</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="destino_id">Lugar</label>
                    <select name="destino_id" id="destino_id" class="form-control" required>
                        <option value="">Seleccionar lugar</option>
                        @foreach($lugares as $lugar)
                            @php $value = ($lugar['tipo'] ?? '') . '|' . ($lugar['id'] ?? ''); @endphp
                            <option value="{{ $value }}" {{ $selectedDestino === $value ? 'selected' : '' }}>
                                {{ $lugar['label'] ?? (($lugar['tipo'] ?? 'Lugar') . ' - ' . ($lugar['nombre'] ?? '-')) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="area">Nombre de parada</label>
                    <input type="text" name="area" id="area" class="form-control" value="{{ $copyData['area'] ?? '' }}" placeholder="Ej. Parada Planta Semana 32" data-rq-option-field="rq_mina.parada_nombre" required>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Fechas y semana</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="fecha_inicio">Fecha inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" value="{{ $copyData['fecha_inicio'] ?? '' }}" required>
                </div>
                <div class="form-group">
                    <label for="fecha_fin">Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" value="{{ $copyData['fecha_fin'] ?? '' }}" required>
                </div>
                <div id="rqWeekPreview" style="margin-top:10px; padding:10px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; color:#0f172a; font-weight:700;">
                    Semana: -
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <h3 class="card-title">Observaciones</h3>
        </div>
        <div class="card-body">
            <textarea name="observaciones" class="form-control" rows="3" placeholder="Observaciones de la parada" data-rq-option-field="rq_mina.parada_observaciones">{{ $copyData['observaciones'] ?? '' }}</textarea>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-body">
            @include('rq-mina.partials.supervisor-selector', [
                'selectorId' => 'rqCreateSupervisorSelector',
                'selectedSupervisor' => $selectedSupervisor,
                'title' => 'Supervisor a cargo de herramientas',
            ])
            <div style="margin-top: 14px;">
                @include('rq-mina.partials.supervisor-selector', [
                    'selectorId' => 'rqCreateSupervisorPetsSelector',
                    'selectedSupervisor' => $selectedSupervisorPets,
                    'title' => 'Supervisor a cargo de PETS',
                    'fieldName' => 'supervisor_pets_id',
                    'emptyText' => 'Sin supervisor PETS seleccionado.',
                ])
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('rq-mina.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    </div>
</form>

@push('scripts')
<script>
function isoWeekInfo(date) {
    const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNumber = target.getUTCDay() || 7;
    target.setUTCDate(target.getUTCDate() + 4 - dayNumber);
    const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
    const week = Math.ceil((((target - yearStart) / 86400000) + 1) / 7);
    return { week, year: target.getUTCFullYear() };
}

function rqWeekRangeText(startValue, endValue) {
    if (!startValue) {
        return 'Semana: -';
    }

    const startInfo = isoWeekInfo(new Date(startValue + 'T00:00:00'));
    if (!endValue) {
        return 'Semana ' + startInfo.week + ' / ' + startInfo.year;
    }

    const endInfo = isoWeekInfo(new Date(endValue + 'T00:00:00'));
    if (startInfo.week === endInfo.week && startInfo.year === endInfo.year) {
        return 'Semana ' + startInfo.week + ' / ' + startInfo.year;
    }

    if (startInfo.year === endInfo.year) {
        return 'Semana ' + startInfo.week + ' - ' + endInfo.week + ' / ' + startInfo.year;
    }

    return 'Semana ' + startInfo.week + ' / ' + startInfo.year + ' - ' + endInfo.week + ' / ' + endInfo.year;
}

function updateRqWeekPreview() {
    const input = document.getElementById('fecha_inicio');
    const endInput = document.getElementById('fecha_fin');
    const preview = document.getElementById('rqWeekPreview');
    if (!input || !preview) {
        if (preview) preview.textContent = 'Semana: -';
        return;
    }

    preview.textContent = rqWeekRangeText(input.value, endInput ? endInput.value : '');
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('fecha_inicio');
    const endInput = document.getElementById('fecha_fin');
    if (input) input.addEventListener('change', updateRqWeekPreview);
    if (endInput) endInput.addEventListener('change', updateRqWeekPreview);
    updateRqWeekPreview();
});
</script>
@endpush
@endsection
