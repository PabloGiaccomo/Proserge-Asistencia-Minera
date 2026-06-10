@extends('layouts.app')

@section('title', 'Regularizar personal antiguo - Proserge')

@section('content')
@php
    $estadoInterno = strtoupper((string) ($trabajador['estado_interno'] ?? $personal->estado ?? ''));
    $origenActual = old('origen_registro', $personal->origen_registro ?: ($estadoInterno === 'CESADO' ? 'HISTORICO' : 'ANTIGUO'));
    $fechaInicio = old('fecha_inicio', optional($contratoDatos?->fecha_inicio_contrato)->toDateString() ?: optional($personal->fecha_ingreso)->toDateString());
    $fechaFin = old('fecha_fin', optional($contratoDatos?->fecha_fin_contrato)->toDateString() ?: optional($contratoActual?->fecha_fin)->toDateString());
    $fechaFirma = old('fecha_firma', optional($contratoDatos?->fecha_firma)->toDateString() ?: optional($contratoActual?->signed_at)->toDateString());
    $hasLegacySignedFile = $contratoDatos?->signed_at && $contratoDatos?->signed_contract_path;
@endphp

<style>
.legacy-regularize-page {
    display: grid;
    gap: 16px;
}
.legacy-summary-grid,
.legacy-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.legacy-summary-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}
.legacy-summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
}
.legacy-summary-value {
    margin-top: 4px;
    color: #0f172a;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.legacy-note {
    margin: 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.45;
}
.legacy-warning {
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #92400e;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
    font-weight: 700;
}
.legacy-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
</style>

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
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ $fechaInicio }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha fin</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ $fechaFin }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha firma</label>
                    <input type="date" name="fecha_firma" class="form-control" value="{{ $fechaFirma }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Area</label>
                    <input type="text" name="area" class="form-control" value="{{ old('area') }}" maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Mina / sede</label>
                    <input type="text" name="mina" class="form-control" value="{{ old('mina') }}" maxlength="191">
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
                <div class="form-group">
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
@endsection
