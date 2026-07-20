@extends('layouts.app')

@section('title', 'Regularizar personal antiguo - Proserge')

@section('content')
@php
    $estadoInterno = strtoupper((string) ($trabajador['estado_interno'] ?? $personal->estado ?? ''));
    $origenActual = old('origen_registro', $personal->origen_registro ?: ($estadoInterno === 'CESADO' ? 'HISTORICO' : 'ANTIGUO'));
    $fechaInicio = old('fecha_inicio', optional($contratoDatos?->fecha_inicio_contrato)->toDateString() ?: optional($personal->fecha_ingreso)->toDateString());
    $fechaFin = old('fecha_fin', optional($contratoDatos?->fecha_fin_contrato)->toDateString() ?: optional($contratoActual?->fecha_fin)->toDateString());
    $fechaFirma = $fechaInicio;
    $hasLegacySignedFile = $contratoDatos?->signed_at && $contratoDatos?->signed_contract_path;
@endphp

<div class="module-page legacy-regularize-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <div>
                <h1 class="page-title">Regularizar personal antiguo</h1>
                <p class="page-subtitle">{{ $personal->nombre_completo }} - {{ $personal->tipo_documento ?: 'DNI' }} {{ $personal->numero_documento ?: $personal->dni }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.edit', $personal->id) }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <span class="card-title">Estado actual del trabajador</span>
        </div>
        <div class="card-body legacy-summary-grid">
            <div class="legacy-summary-item">
                <div class="legacy-summary-label">Estado laboral</div>
                <div class="legacy-summary-value">{{ $trabajador['estado_label'] ?? $personal->estado }}</div>
            </div>
            <div class="legacy-summary-item">
                <div class="legacy-summary-label">Origen actual</div>
                <div class="legacy-summary-value">{{ $personal->origen_registro ?: 'Sin origen' }}</div>
            </div>
            <div class="legacy-summary-item">
                <div class="legacy-summary-label">Contrato firmado vigente</div>
                <div class="legacy-summary-value">{{ $hasSignedContract ? 'Si' : 'No' }}</div>
            </div>
            <div class="legacy-summary-item">
                <div class="legacy-summary-label">Pendiente regularizacion</div>
                <div class="legacy-summary-value">{{ $personal->pendiente_regularizacion ? 'Si' : 'No' }}</div>
            </div>
        </div>
    </div>

    @if($estadoInterno === 'ACTIVO' && !$hasSignedContract)
        <div class="legacy-warning">
            Este trabajador figura activo, pero no tiene contrato vigente firmado asociado correctamente. Puedes regularizarlo subiendo/asociando el contrato firmado o marcarlo como pendiente.
        </div>
    @endif

    <form method="POST" action="{{ route('personal.antiguo.regularize.update', $personal->id) }}" enctype="multipart/form-data" class="card">
        @csrf
        <div class="card-header">
            <span class="card-title">Datos de regularizacion</span>
        </div>
        <div class="card-body" style="display:grid;gap:16px;">
            <div class="legacy-form-grid">
                <div class="form-group">
                    <label class="form-label">Nuevo origen</label>
                    <select name="origen_registro" class="form-control" required>
                        <option value="ANTIGUO" @selected($origenActual === 'ANTIGUO')>Antiguo</option>
                        <option value="HISTORICO" @selected($origenActual === 'HISTORICO')>Historico</option>
                        <option value="IMPORTADO" @selected($origenActual === 'IMPORTADO')>Importado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Estado del contrato a sincronizar</label>
                    <select name="estado_contrato" id="regularizeEstadoContrato" class="form-control" required>
                        <option value="VIGENTE" @selected(old('estado_contrato', $estadoInterno === 'CESADO' ? 'CERRADO' : 'VIGENTE') === 'VIGENTE')>Vigente</option>
                        <option value="CERRADO" @selected(old('estado_contrato', $estadoInterno === 'CESADO' ? 'CERRADO' : 'VIGENTE') === 'CERRADO')>Cerrado / historico</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo de contrato</label>
                    <select name="contrato" class="form-control">
                        @foreach(['REG' => 'Regimen', 'FIJO' => 'Fijo / servicio especifico', 'INTER' => 'Intermitente', 'INDET' => 'Indeterminado'] as $key => $label)
                            <option value="{{ $key }}" @selected(old('contrato', $personal->contrato) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha inicio contrato</label>
                    <input type="date" name="fecha_inicio" id="regularizeFechaInicio" class="form-control" value="{{ $fechaInicio }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha fin</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $fechaFin }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha firma</label>
                    <input type="date" name="fecha_firma" id="regularizeFechaFirma" class="form-control" value="{{ $fechaFirma }}" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Area o cargo</label>
                    <input type="text" name="area" class="form-control" value="{{ old('area') }}" maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Remuneracion</label>
                    <input type="text" name="remuneracion" class="form-control" value="{{ old('remuneracion', $contratoDatos?->sueldo_num) }}" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Costo hora</label>
                    <input type="text" name="costo_hora" class="form-control" value="{{ old('costo_hora', $contratoDatos?->sueldo_hora_paradas) }}" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Contrato firmado PDF</label>
                    <input type="file" name="contrato_firmado" class="form-control" accept="application/pdf">
                    @if($hasLegacySignedFile)
                        <p class="legacy-note" style="margin-top:6px;">Existe un contrato firmado anterior en datos de contrato; si sincronizas, se asociara al contrato laboral.</p>
                    @endif
                </div>
                <div class="form-group" data-regularize-motivo-cese>
                    <label class="form-label">Motivo de cese</label>
                    <input type="text" name="motivo_cese" class="form-control" value="{{ old('motivo_cese', $personal->motivo_cese) }}" maxlength="2000">
                </div>
            </div>

            <div style="display:grid;gap:10px;">
                <label style="display:flex;align-items:flex-start;gap:8px;">
                    <input type="checkbox" name="sincronizar_contrato" value="1" @checked(old('sincronizar_contrato', true))>
                    <span>Crear o sincronizar contrato laboral desde los datos actuales si no existe uno equivalente.</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;">
                    <input type="checkbox" name="pendiente_regularizacion" value="1" @checked(old('pendiente_regularizacion', $personal->pendiente_regularizacion))>
                    <span>Marcar como pendiente de regularizacion.</span>
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">Observacion historica</label>
                <textarea name="observacion_historica" class="form-control" rows="4" maxlength="5000">{{ old('observacion_historica', $personal->observacion_historica) }}</textarea>
            </div>

            <div class="legacy-actions">
                <a href="{{ route('personal.edit', $personal->id) }}" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">Confirmar regularizacion</button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const startInput = document.getElementById('regularizeFechaInicio');
    const signInput = document.getElementById('regularizeFechaFirma');
    const contractState = document.getElementById('regularizeEstadoContrato');
    const ceaseReasonGroup = document.querySelector('[data-regularize-motivo-cese]');
    const ceaseReasonInput = ceaseReasonGroup ? ceaseReasonGroup.querySelector('input[name="motivo_cese"]') : null;

    if (!startInput || !signInput) {
        return;
    }

    const syncSignDate = function () {
        signInput.value = startInput.value || '';
    };

    syncSignDate();
    startInput.addEventListener('input', syncSignDate);
    startInput.addEventListener('change', syncSignDate);

    const syncCeaseReason = function () {
        if (!contractState || !ceaseReasonGroup || !ceaseReasonInput) {
            return;
        }

        const shouldShow = String(contractState.value || '').toUpperCase() === 'CERRADO';
        ceaseReasonGroup.classList.toggle('legacy-field-hidden', !shouldShow);
        ceaseReasonInput.disabled = !shouldShow;

        if (!shouldShow) {
            ceaseReasonInput.value = '';
        }
    };

    syncCeaseReason();
    contractState?.addEventListener('change', syncCeaseReason);
});
</script>
@endsection
