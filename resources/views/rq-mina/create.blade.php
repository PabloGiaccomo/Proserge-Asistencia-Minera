@extends('layouts.app')

@section('title', 'RQ Mina - Parada')

@php
$copyData = $copyData ?? null;
$fieldErrors = $errors ?? new \Illuminate\Support\ViewErrorBag;
$formValue = static function (string $field, mixed $default = ''): string {
    $value = old($field, $default);

    return is_scalar($value) ? (string) $value : '';
};
$supervisorValue = static function (string $field, ?array $selected) use ($fieldErrors, $formValue): ?array {
    $id = $formValue($field, $selected['id'] ?? '');
    if ($id === '' || $fieldErrors->has($field)) {
        return null;
    }

    return $id === ($selected['id'] ?? null) ? $selected : ['id' => $id, 'nombre' => 'Supervisor de la selección anterior'];
};
$selectedSupervisor = $supervisorValue('supervisor_id', $copyData['supervisor'] ?? null);
$selectedSupervisorPets = $supervisorValue('supervisor_pets_id', $copyData['supervisor_pets'] ?? null);
$lugares = $lugares ?? [];
$selectedDestino = $formValue('destino_id', ($copyData['destino_tipo'] ?? 'MINA') . '|' . ($copyData['destino_id'] ?? $copyData['mina_id'] ?? ''));
$formMode = $formMode ?? 'create';
$formAction = $formAction ?? route('rq-mina.store');
$formMethod = $formMethod ?? 'POST';
$submitLabel = $submitLabel ?? 'Guardar Parada';
$isEdit = $formMode === 'edit';
$lista = $lista ?? [];
$returnUrl = $isEdit && !empty($copyData['id'])
    ? route('rq-mina.show', ['id' => $copyData['id'], 'lista' => $lista])
    : route('rq-mina.index', $lista);
$rqPermissions = session('user.permissions', []);
$canSubmitRqMina = $isEdit
    ? \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'actualizar')
    : \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'crear');
@endphp

@section('content')
<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">{{ $isEdit ? 'Editar Parada' : 'Nueva Parada' }}</h1>
            <p class="page-subtitle">{{ $isEdit ? 'Editar datos generales de la parada' : 'Registrar lugar, fecha y observaciones de la parada' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ $returnUrl }}" class="btn btn-outline">{{ $isEdit ? 'Volver a la parada' : 'Volver a paradas' }}</a>
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

@if(session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif

@if(session('error') || $fieldErrors->any())
    <div class="alert alert-error" role="alert" tabindex="-1" id="rqParadaErrors">
        @if(session('error'))
            <p>{{ session('error') }}</p>
        @endif
        @if($fieldErrors->any())
            <strong>Revisa estos datos para guardar la parada.</strong>
            <ul>
                @foreach($fieldErrors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
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
                    <select name="destino_id" id="destino_id" class="form-control" required @if($fieldErrors->has('destino_id')) aria-invalid="true" aria-describedby="rqDestinoError" @endif>
                        <option value="">Seleccionar lugar</option>
                        @foreach($lugares as $lugar)
                            @php $value = ($lugar['tipo'] ?? '') . '|' . ($lugar['id'] ?? ''); @endphp
                            <option value="{{ $value }}" {{ $selectedDestino === $value ? 'selected' : '' }}>
                                {{ $lugar['label'] ?? (($lugar['tipo'] ?? 'Lugar') . ' - ' . ($lugar['nombre'] ?? '-')) }}
                            </option>
                        @endforeach
                    </select>
                    @if($fieldErrors->has('destino_id')) <p id="rqDestinoError">{{ $fieldErrors->first('destino_id') }}</p> @endif
                </div>
                <div class="form-group">
                    <label for="area">Nombre de parada</label>
                    <input type="text" name="area" id="area" class="form-control" value="{{ $formValue('area', $copyData['area'] ?? '') }}" placeholder="Ej. Parada Planta Semana 32" data-rq-option-field="rq_mina.parada_nombre" required @if($fieldErrors->has('area')) aria-invalid="true" aria-describedby="rqAreaError" @endif>
                    @if($fieldErrors->has('area')) <p id="rqAreaError">{{ $fieldErrors->first('area') }}</p> @endif
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
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" value="{{ $formValue('fecha_inicio', $copyData['fecha_inicio'] ?? '') }}" required @if($fieldErrors->has('fecha_inicio')) aria-invalid="true" aria-describedby="rqFechaInicioError" @endif>
                    @if($fieldErrors->has('fecha_inicio')) <p id="rqFechaInicioError">{{ $fieldErrors->first('fecha_inicio') }}</p> @endif
                </div>
                <div class="form-group">
                    <label for="fecha_fin">Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" value="{{ $formValue('fecha_fin', $copyData['fecha_fin'] ?? '') }}" required @if($fieldErrors->has('fecha_fin')) aria-invalid="true" aria-describedby="rqFechaFinError" @endif>
                    @if($fieldErrors->has('fecha_fin')) <p id="rqFechaFinError">{{ $fieldErrors->first('fecha_fin') }}</p> @endif
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
            <textarea name="observaciones" aria-label="Observaciones" class="form-control" rows="3" placeholder="Observaciones de la parada" data-rq-option-field="rq_mina.parada_observaciones">{{ $formValue('observaciones', $copyData['observaciones'] ?? '') }}</textarea>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-body">
            @include('rq-mina.partials.supervisor-selector', [
                'selectorId' => 'rqCreateSupervisorSelector',
                'selectedSupervisor' => $selectedSupervisor,
                'title' => 'Supervisor a cargo de herramientas',
                'errorMessage' => $fieldErrors->first('supervisor_id'),
            ])
            <div style="margin-top: 14px;">
                @include('rq-mina.partials.supervisor-selector', [
                    'selectorId' => 'rqCreateSupervisorPetsSelector',
                    'selectedSupervisor' => $selectedSupervisorPets,
                    'title' => 'Supervisor a cargo de PETS',
                    'fieldName' => 'supervisor_pets_id',
                    'emptyText' => 'Sin supervisor PETS seleccionado.',
                    'errorMessage' => $fieldErrors->first('supervisor_pets_id'),
                ])
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ $returnUrl }}" class="btn btn-outline">Cancelar</a>
        @if($canSubmitRqMina)
            <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        @endif
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
    (document.querySelector('.form [aria-invalid="true"]') || document.getElementById('rqParadaErrors'))?.focus();
});
</script>
@endpush
@endsection
