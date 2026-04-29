@extends('layouts.app')

@section('title', 'Confirmar datos detectados - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Confirmar trabajador</h1>
                <p class="page-subtitle">Revisa los datos detectados, corrige lo necesario y define que campos verificara el trabajador.</p>
            </div>
            <div class="page-actions">
                <form id="cancelImportForm" method="POST" action="{{ route('personal.fichas.cancel-import') }}">
                    @csrf
                    <input type="hidden" name="session_key" value="{{ $sessionKey }}">
                </form>
                <button type="submit" form="cancelImportForm" class="btn btn-outline">Cancelar</button>
            </div>
        </div>
    </div>

    @if(count($warnings ?? []) > 0)
        <div class="ficha-alert ficha-alert-warning">
            <strong>Advertencias:</strong>
            {{ implode(' ', $warnings) }}
        </div>
    @endif

    @if(count($missing ?? []) > 0)
        <div class="ficha-alert">
            Hay campos obligatorios sin detectar. Quedaran marcados para que RRHH o el trabajador los complete antes del envio final.
        </div>
    @endif

    @if(isset($availability) && !($availability['available'] ?? true))
        <div class="ficha-alert ficha-alert-warning">
            {{ $availability['message'] ?? 'Ya existe un trabajador con este documento.' }}
        </div>
    @endif

    <form method="POST" action="{{ route('personal.fichas.generate-link') }}" class="ficha-workspace">
        @csrf
        <input type="hidden" name="session_key" value="{{ $sessionKey }}">
        @php
            $currentFieldValue = fn (string $key): string => (string) old('fields.' . $key, $fields[$key] ?? '');
        @endphp

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Datos detectados</h2>
                    <p class="ficha-card-subtitle">Contrato detectado: {{ $contractSummary }}</p>
                </div>
                <span class="ficha-status ficha-status-pending">Pendiente completar ficha</span>
            </div>
            <div class="ficha-card-body">
                @foreach($sections as $section)
                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h3 class="ficha-section-title">{{ $section['title'] }}</h3>
                        </div>
                        <div class="ficha-fields">
                            @foreach($section['fields'] as $field)
                                @php
                                    $key = $field['key'];
                                    $type = $field['type'];
                                    $value = old('fields.' . $key, $fields[$key] ?? '');
                                    if ($key === 'tipo_documento' && trim((string) $value) === '') {
                                        $value = 'DNI';
                                    }
                                    if ($key === 'pais_nacimiento' && trim((string) $value) === '') {
                                        $value = 'Peru';
                                    }
                                    if ($key === 'domicilio_tipo' && trim((string) $value) === '') {
                                        $value = 'Peru';
                                    }
                                    $isTextarea = $type === 'textarea';
                                    $verifyChecked = in_array($key, old('verify_fields', $defaultVerify ?? []), true) || in_array($key, $missing ?? [], true);
                                    $fieldClass = $isTextarea ? 'ficha-field ficha-field-wide' : 'ficha-field';
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
                                    $fieldDisabled = $conditionalHidden;
                                @endphp
                                @if($type === 'hidden')
                                    <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                                    @continue
                                @endif
                                <div class="{{ $fieldClass }}" data-ficha-field="{{ $key }}" style="{{ $conditionalHidden ? 'display:none;' : '' }}">
                                    <div class="ficha-label-row">
                                        <label class="ficha-label" for="field_{{ $key }}">
                                            {{ $field['label'] }}
                                            @if($field['required'])
                                                <span class="ficha-required">*</span>
                                            @endif
                                        </label>
                                        <label class="ficha-check">
                                            <input type="checkbox" name="verify_fields[]" value="{{ $key }}" {{ $verifyChecked ? 'checked' : '' }} {{ $fieldDisabled ? 'disabled' : '' }} data-ficha-verify="{{ $key }}">
                                            Verifica
                                        </label>
                                    </div>

                                    @if($type === 'select')
                                        <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" data-current-value="{{ $value }}" {{ $fieldDisabled ? 'disabled' : '' }}>
                                            <option value="">Seleccionar</option>
                                            @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($isTextarea)
                                        <textarea class="ficha-textarea" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" {{ $fieldDisabled ? 'disabled' : '' }}>{{ $value }}</textarea>
                                    @else
                                        <input class="ficha-input" id="field_{{ $key }}" type="{{ $type }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}" {{ $fieldDisabled ? 'disabled' : '' }}>
                                    @endif

                                    @error('fields.' . $key)
                                        <span class="ficha-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <div class="ficha-actions-bar">
            <button type="submit" form="cancelImportForm" class="btn btn-outline">Cancelar</button>
            <button type="submit" class="btn btn-primary">Generar link temporal</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
    @include('personal.fichas.partials.conditional-fields-script', ['scope' => 'rrhh'])
@endpush
