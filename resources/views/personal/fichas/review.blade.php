@extends('layouts.app')

@section('title', 'Revision de ficha - Proserge')

@section('content')
@php
    $fechaInicioContrato = old('fecha_inicio_contrato', $data['fecha_ingreso'] ?? '');
    $fechaFinContrato = old('fecha_fin_contrato', $data['fecha_fin_contrato'] ?? '');
    $periodoPruebaInicio = old('periodo_prueba_inicio', $data['periodo_prueba_inicio'] ?? $fechaInicioContrato);
    $periodoPruebaFin = old('periodo_prueba_fin', $data['periodo_prueba_fin'] ?? '');

    if ($periodoPruebaFin === '' && $periodoPruebaInicio !== '') {
        try {
            $periodoPruebaFin = \Illuminate\Support\Carbon::parse($periodoPruebaInicio)->addMonthsNoOverflow(3)->subDay()->toDateString();
            if ($fechaFinContrato !== '' && $fechaFinContrato < $periodoPruebaFin) {
                $periodoPruebaFin = $fechaFinContrato;
            }
        } catch (\Throwable) {
            $periodoPruebaFin = '';
        }
    }
@endphp
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Revision de ficha</h1>
                <p class="page-subtitle">{{ $ficha->personal?->nombre_completo ?? 'Trabajador pendiente' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Personal</a>
                <a href="{{ route('personal.fichas.pdf', $ficha->id) }}" class="btn btn-outline">Exportar PDF</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="ficha-alert ficha-alert-warning">{{ session('error') }}</div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">{{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}</h2>
                <p class="ficha-card-subtitle">
                    Enviada: {{ optional($ficha->submitted_at)->format('d/m/Y H:i') ?: '-' }}
                </p>
            </div>
            @php
                $statusClass = match($ficha->estado) {
                    'FICHA_ENVIADA' => 'ficha-status-sent',
                    'APROBADO' => 'ficha-status-approved',
                    'LINK_VENCIDO', 'RECHAZADO' => 'ficha-status-expired',
                    default => 'ficha-status-pending',
                };
            @endphp
            <span class="ficha-status {{ $statusClass }}">{{ $estadoLabel }}</span>
        </div>
        <div class="ficha-card-body">
            @php
                $currentFieldValue = fn (string $key): string => (string) ($data[$key] ?? '');
            @endphp
            @foreach($sections as $section)
                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">{{ $section['title'] }}</h3>
                    </div>
                    <div class="ficha-fields">
                        @foreach($section['fields'] as $field)
                            @php
                                $key = $field['key'];
                                $type = $field['type'] ?? 'text';
                                $paisNacimientoActual = $currentFieldValue('pais_nacimiento') ?: 'Peru';
                                $domicilioPaisActual = $currentFieldValue('domicilio_tipo') ?: 'Peru';
                                $bancoActual = $currentFieldValue('banco');
                                $conditionalHidden = match ($key) {
                                    'estado_civil_otro' => $currentFieldValue('estado_civil') !== 'Otro',
                                    'nacionalidad_otra' => $currentFieldValue('nacionalidad') !== 'Otra',
                                    'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual !== 'Otro',
                                    'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual === 'Otro',
                                    'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual !== 'Extranjero',
                                    'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual === 'Extranjero',
                                    'numero_cuenta' => !in_array($bancoActual, ['BCP', 'Interbank'], true),
                                    'banco_otro', 'cci' => $bancoActual !== 'Otro',
                                    'tipo_comision', 'tipo_afp', 'cuspp' => $currentFieldValue('sistema_pensionario') !== 'Sistema Privado de Pensiones',
                                    'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $currentFieldValue('quinta_empleador_principal') !== 'Otra empresa',
                                    default => false,
                                };
                                if ($type === 'hidden' || $conditionalHidden) {
                                    continue;
                                }
                                $value = $data[$key] ?? '';
                                if ($key === 'contrato') {
                                    $value = \App\Modules\Personal\Support\PersonalNormalizer::contractLabel($value);
                                }
                                if ($type === 'select') {
                                    $value = ($field['options'][$value] ?? $value);
                                }
                            @endphp
                            <div class="{{ $type === 'textarea' ? 'ficha-field ficha-field-wide' : 'ficha-field' }}">
                                <span class="ficha-label">{{ $field['label'] }}</span>
                                <div class="ficha-input" style="height:auto;min-height:42px;">{{ $value !== '' ? $value : '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <section class="ficha-section">
                <div class="ficha-section-header">
                    <h3 class="ficha-section-title">Familiares o contactos</h3>
                </div>
                <div class="ficha-card-body">
                    @forelse($ficha->familiares as $familiar)
                        <div class="ficha-family-item">
                            <strong>{{ $familiar->nombres_apellidos }}</strong>
                            <div class="ficha-card-subtitle">
                                {{ $familiar->parentesco ?: '-' }} |
                                {{ $familiar->tipo_documento ?: '-' }} {{ $familiar->numero_documento ?: '-' }} |
                                {{ $familiar->telefono ?: '-' }}
                            </div>
                            <div class="ficha-status-row" style="margin-top:8px;">
                                @if($familiar->vive_con_trabajador)
                                    <span class="ficha-status">Vive con el trabajador</span>
                                @endif
                                @if($familiar->contacto_emergencia)
                                    <span class="ficha-status ficha-status-sent">Contacto de emergencia</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="ficha-alert ficha-alert-warning">No se registraron familiares.</div>
                    @endforelse
                </div>
            </section>

            <section class="ficha-section">
                <div class="ficha-section-header">
                    <h3 class="ficha-section-title">Documentos requeridos</h3>
                </div>
                <div class="ficha-card-body">
                    <div class="ficha-fields" style="padding:0;">
                        @foreach(\App\Modules\Personal\Support\PersonalFichaCatalog::documentRequirements() as $docKey => $requirement)
                            @php
                                $archivo = $ficha->archivos->firstWhere('tipo', $docKey);
                                $docLabel = $requirement['label'] ?? $requirement;
                                $docRequired = (bool) ($requirement['required'] ?? true);
                            @endphp
                            <div class="ficha-field ficha-field-wide">
                                <span class="ficha-label">
                                    {{ $docLabel }}
                                    @if($docRequired)
                                        <span class="ficha-status" style="padding:2px 7px;font-size:10px;">Obligatorio</span>
                                    @else
                                        <span class="ficha-status ficha-status-pending" style="padding:2px 7px;font-size:10px;">Opcional</span>
                                    @endif
                                </span>
                                <div class="ficha-input" style="height:auto;min-height:42px;">
                                    @if($archivo)
                                        <a href="{{ route('personal.fichas.archivos.download', $archivo->id) }}">{{ $archivo->nombre_original ?: 'Descargar documento' }}</a>
                                    @else
                                        {{ $docRequired ? 'No adjuntado' : 'No adjuntado (opcional)' }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="ficha-section">
                <div class="ficha-section-header">
                    <h3 class="ficha-section-title">Firma y huella</h3>
                </div>
                <div class="ficha-card-body">
                    <div class="ficha-grid">
                        <div style="grid-column:span 6;">
                            <span class="ficha-label">Firma digital</span><br>
                            @if($ficha->firma_base64)
                                <img class="ficha-preview-image" src="{{ $ficha->firma_base64 }}" alt="Firma digital">
                            @else
                                <div class="ficha-alert ficha-alert-warning">Sin firma registrada.</div>
                            @endif
                        </div>
                        <div style="grid-column:span 6;">
                            <span class="ficha-label">Huella digital</span><br>
                            @if($huellaDataUrl)
                                <img class="ficha-preview-image" src="{{ $huellaDataUrl }}" alt="Huella digital">
                            @else
                                <div class="ficha-alert ficha-alert-warning">Sin huella registrada.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">Decision de RRHH</h2>
                <p class="ficha-card-subtitle">Aprobar activa al trabajador en Personal.</p>
            </div>
        </div>
        <div class="ficha-card-body">
            @if($ficha->estado === \App\Models\PersonalFicha::ESTADO_OBSERVADO)
                <div class="ficha-alert ficha-alert-warning">
                    <strong>Ficha observada.</strong>
                    El trabajador debe revisar sus datos. Puedes reenviar el correo con la observacion registrada.
                    @if($ficha->observaciones_revision)
                        <div style="margin-top:8px;">{{ $ficha->observaciones_revision }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('personal.fichas.resend-observation', $ficha->id) }}" style="margin-bottom:16px;">
                    @csrf
                    <button type="submit" class="btn btn-primary">Reenviar correo</button>
                </form>
            @endif
            <form id="approveFichaForm" method="POST" action="{{ route('personal.fichas.approve', $ficha->id) }}" class="ficha-workspace">
                @csrf
                <div class="ficha-field">
                    <label class="ficha-label" for="observaciones_revision">Observaciones</label>
                    <textarea class="ficha-textarea" id="observaciones_revision" name="observaciones_revision">{{ old('observaciones_revision', $ficha->observaciones_revision) }}</textarea>
                    @error('observaciones_revision') <span class="ficha-error">{{ $message }}</span> @enderror
                    @error('fecha_inicio_contrato') <span class="ficha-error">{{ $message }}</span> @enderror
                    @error('fecha_fin_contrato') <span class="ficha-error">{{ $message }}</span> @enderror
                    @error('periodo_prueba_inicio') <span class="ficha-error">{{ $message }}</span> @enderror
                    @error('periodo_prueba_fin') <span class="ficha-error">{{ $message }}</span> @enderror
                </div>
                <div class="ficha-actions-bar">
                    <button type="submit" formaction="{{ route('personal.fichas.observe', $ficha->id) }}" class="btn btn-outline">Marcar observado</button>
                    <button type="button" class="btn btn-primary" onclick="openApproveFichaModal()">Aprobar trabajador</button>
                </div>
            </form>
        </div>
    </div>

    <div id="approveFichaModal" class="modal" style="display:none;" onclick="if (event.target === this) closeApproveFichaModal()">
        <div class="modal-backdrop" onclick="closeApproveFichaModal()"></div>
        <div class="modal-content" style="width:min(560px, calc(100vw - 32px)); border-radius:14px;">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Verificar fecha de contrato</h2>
                    <p class="modal-subtitle">Confirma las fechas antes de aprobar al trabajador.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeApproveFichaModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body" style="display:grid; gap:14px;">
                <div class="ficha-fields" style="padding:0;">
                    <div class="ficha-field">
                        <label class="ficha-label" for="fecha_inicio_contrato">Inicio de contrato <span class="ficha-required">*</span></label>
                        <input form="approveFichaForm" id="fecha_inicio_contrato" class="ficha-input" type="date" name="fecha_inicio_contrato" value="{{ $fechaInicioContrato }}">
                    </div>
                    <div class="ficha-field">
                        <label class="ficha-label" for="fecha_fin_contrato">Fin de contrato</label>
                        <input form="approveFichaForm" id="fecha_fin_contrato" class="ficha-input" type="date" name="fecha_fin_contrato" value="{{ $fechaFinContrato }}">
                    </div>
                </div>
                <section class="ficha-section" style="margin:0;">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Periodo de prueba</h3>
                    </div>
                    <div class="ficha-fields" style="padding:0;">
                        <div class="ficha-field">
                            <label class="ficha-label" for="periodo_prueba_inicio">Inicio <span class="ficha-required">*</span></label>
                            <input form="approveFichaForm" id="periodo_prueba_inicio" class="ficha-input" type="date" name="periodo_prueba_inicio" value="{{ $periodoPruebaInicio }}">
                        </div>
                        <div class="ficha-field">
                            <label class="ficha-label" for="periodo_prueba_fin">Fin <span class="ficha-required">*</span></label>
                            <input form="approveFichaForm" id="periodo_prueba_fin" class="ficha-input" type="date" name="periodo_prueba_fin" value="{{ $periodoPruebaFin }}">
                        </div>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeApproveFichaModal()">Cancelar</button>
                <button type="submit" form="approveFichaForm" class="btn btn-primary">Confirmar trabajador</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openApproveFichaModal() {
    openModal('approveFichaModal');
}

function closeApproveFichaModal() {
    closeModal('approveFichaModal');
}

document.addEventListener('DOMContentLoaded', function () {
    const startInput = document.getElementById('fecha_inicio_contrato');
    const endInput = document.getElementById('fecha_fin_contrato');
    const trialStartInput = document.getElementById('periodo_prueba_inicio');
    const trialEndInput = document.getElementById('periodo_prueba_fin');

    const isoDate = function (date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
        return date.toISOString().slice(0, 10);
    };

    const suggestedTrialEnd = function (value) {
        if (!value) return '';
        const date = new Date(value + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return '';
        date.setMonth(date.getMonth() + 3);
        date.setDate(date.getDate() - 1);
        const contractEnd = endInput?.value ? new Date(endInput.value + 'T00:00:00') : null;
        if (contractEnd && !Number.isNaN(contractEnd.getTime()) && contractEnd < date) {
            return isoDate(contractEnd);
        }
        return isoDate(date);
    };

    startInput?.addEventListener('change', function () {
        if (trialStartInput && (!trialStartInput.value || trialStartInput.value === trialStartInput.dataset.autoValue)) {
            trialStartInput.value = startInput.value;
        }
        if (trialEndInput && (!trialEndInput.value || trialEndInput.value === trialEndInput.dataset.autoValue)) {
            trialEndInput.value = suggestedTrialEnd(trialStartInput?.value || startInput.value);
            trialEndInput.dataset.autoValue = trialEndInput.value;
        }
    });

    trialStartInput?.addEventListener('change', function () {
        if (trialEndInput) {
            trialEndInput.value = suggestedTrialEnd(trialStartInput.value);
            trialEndInput.dataset.autoValue = trialEndInput.value;
        }
    });

    endInput?.addEventListener('change', function () {
        if (trialEndInput) {
            trialEndInput.value = suggestedTrialEnd(trialStartInput?.value || startInput?.value);
            trialEndInput.dataset.autoValue = trialEndInput.value;
        }
    });

    if (trialEndInput && trialEndInput.value) {
        trialEndInput.dataset.autoValue = trialEndInput.value;
    }
});
</script>
@endpush
