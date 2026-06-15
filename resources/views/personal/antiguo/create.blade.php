@extends('layouts.app')

@section('title', 'Registrar personal antiguo - Proserge')

@section('content')
<style>
.legacy-personnel-page {
    display: grid;
    gap: 16px;
}
.legacy-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.legacy-form-section {
    display: grid;
    gap: 14px;
}
.legacy-note {
    margin: 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.45;
}
.legacy-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
</style>

<div class="module-page legacy-personnel-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <div>
                <h1 class="page-title">Registrar personal antiguo</h1>
                <p class="page-subtitle">Carga manual de trabajador existente con contrato historico o vigente.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('personal.antiguo.store') }}" enctype="multipart/form-data" class="legacy-form-section">
        @csrf

        <div class="card">
            <div class="card-header">
                <span class="card-title">Datos personales base</span>
            </div>
            <div class="card-body legacy-form-grid">
                <div class="form-group">
                    <label class="form-label">Tipo de documento</label>
                    <select name="tipo_documento" class="form-control" required>
                        @foreach(\App\Modules\Personal\Support\PersonalFichaCatalog::DOCUMENT_TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(old('tipo_documento', 'DNI') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Numero de documento</label>
                    <input type="text" name="numero_documento" class="form-control" value="{{ old('numero_documento') }}" required maxlength="40">
                </div>
                <div class="form-group">
                    <label class="form-label">Nombres</label>
                    <input type="text" name="nombres" class="form-control" value="{{ old('nombres') }}" required maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Apellido paterno</label>
                    <input type="text" name="apellido_paterno" class="form-control" value="{{ old('apellido_paterno') }}" required maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Apellido materno</label>
                    <input type="text" name="apellido_materno" class="form-control" value="{{ old('apellido_materno') }}" required maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Telefono celular</label>
                    <input type="text" name="telefono" class="form-control" value="{{ old('telefono') }}" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label">Correo</label>
                    <input type="email" name="correo" class="form-control" value="{{ old('correo') }}" maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Cargo / puesto</label>
                    @include('personal.partials.puesto-autocomplete', [
                        'name' => 'puesto',
                        'value' => old('puesto'),
                        'inputId' => 'legacy_puesto',
                        'listId' => 'puestos_catalogo_antiguo',
                        'class' => 'form-control',
                        'required' => true,
                        'options' => $puestoOptions ?? [],
                    ])
                </div>
                <div class="form-group">
                    <label class="form-label">Ocupacion</label>
                    <input type="text" name="ocupacion" class="form-control" value="{{ old('ocupacion') }}" maxlength="191">
                </div>
                <div class="form-group">
                    <label class="form-label">Supervisor</label>
                    <select name="es_supervisor" class="form-control">
                        <option value="0" @selected(old('es_supervisor', '0') === '0')>No</option>
                        <option value="1" @selected(old('es_supervisor') === '1')>Si</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Situacion laboral y contrato</span>
            </div>
            <div class="card-body legacy-form-section">
                <p class="legacy-note">
                    Si el contrato esta vigente pero no subes el PDF firmado, el trabajador quedara como falta contrato. Solo quedara activo cuando el contrato vigente tenga archivo firmado.
                </p>
                <div class="legacy-form-grid">
                    <div class="form-group">
                        <label class="form-label">Estado laboral actual</label>
                        <select name="estado_laboral" id="legacyEstadoLaboral" class="form-control" required>
                            <option value="ACTIVO" @selected(old('estado_laboral', 'ACTIVO') === 'ACTIVO')>Activo</option>
                            <option value="FALTA_CONTRATO" @selected(old('estado_laboral') === 'FALTA_CONTRATO')>Falta contrato</option>
                            <option value="CESADO" @selected(old('estado_laboral') === 'CESADO')>Cesado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado del contrato</label>
                        <select name="estado_contrato" id="legacyEstadoContrato" class="form-control" required>
                            <option value="VIGENTE" @selected(old('estado_contrato', 'VIGENTE') === 'VIGENTE')>Vigente</option>
                            <option value="CERRADO" @selected(old('estado_contrato') === 'CERRADO')>Cerrado / historico</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipo de contrato</label>
                        <select name="contrato" class="form-control" required>
                            <option value="REG" @selected(old('contrato') === 'REG')>Regimen</option>
                            <option value="FIJO" @selected(old('contrato', 'FIJO') === 'FIJO')>Fijo / servicio especifico</option>
                            <option value="INTER" @selected(old('contrato') === 'INTER')>Intermitente</option>
                            <option value="INDET" @selected(old('contrato') === 'INDET')>Indeterminado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha inicio contrato</label>
                        <input type="date" name="fecha_inicio" class="form-control" value="{{ old('fecha_inicio') }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha fin</label>
                        <input type="date" name="fecha_fin" id="legacyFechaFin" class="form-control" value="{{ old('fecha_fin') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha de firma</label>
                        <input type="date" name="fecha_firma" class="form-control" value="{{ old('fecha_firma') }}">
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
                        <input type="text" name="remuneracion" class="form-control" value="{{ old('remuneracion') }}" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Costo hora</label>
                        <input type="text" name="costo_hora" class="form-control" value="{{ old('costo_hora') }}" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contrato firmado PDF</label>
                        <input type="file" name="contrato_firmado" class="form-control" accept="application/pdf">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Motivo de cese</label>
                        <input type="text" name="motivo_cese" class="form-control" value="{{ old('motivo_cese') }}" maxlength="2000">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observacion historica</label>
                    <textarea name="observacion_historica" class="form-control" rows="4" maxlength="5000" placeholder="Ejemplo: contrato antiguo cargado manualmente, archivo pendiente, datos revisados con RR.HH.">{{ old('observacion_historica') }}</textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                <p class="legacy-note">Despues de guardar podras entrar a Documentos para cargar CV, DNI, Vida Ley y demas archivos disponibles.</p>
                <div class="legacy-actions">
                    <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Registrar personal antiguo</button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const estadoLaboral = document.getElementById('legacyEstadoLaboral');
    const estadoContrato = document.getElementById('legacyEstadoContrato');
    const fechaFin = document.getElementById('legacyFechaFin');

    const syncLegacyState = function () {
        if (!estadoLaboral || !estadoContrato || !fechaFin) return;

        if (estadoLaboral.value === 'CESADO') {
            estadoContrato.value = 'CERRADO';
            fechaFin.required = true;
            return;
        }

        fechaFin.required = false;
    };

    estadoLaboral?.addEventListener('change', syncLegacyState);
    syncLegacyState();
});
</script>
@endpush
