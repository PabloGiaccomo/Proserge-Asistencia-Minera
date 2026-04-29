@extends('layouts.app')

@section('title', 'Revision de ficha - Proserge')

@section('content')
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
                            @endphp
                            <div class="ficha-field ficha-field-wide">
                                <span class="ficha-label">{{ $docLabel }}</span>
                                <div class="ficha-input" style="height:auto;min-height:42px;">
                                    @if($archivo)
                                        <a href="{{ route('personal.fichas.archivos.download', $archivo->id) }}">{{ $archivo->nombre_original ?: 'Descargar documento' }}</a>
                                    @else
                                        No adjuntado
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
            <form method="POST" action="{{ route('personal.fichas.approve', $ficha->id) }}" class="ficha-workspace">
                @csrf
                <div class="ficha-field">
                    <label class="ficha-label" for="observaciones_revision">Observaciones</label>
                    <textarea class="ficha-textarea" id="observaciones_revision" name="observaciones_revision">{{ old('observaciones_revision', $ficha->observaciones_revision) }}</textarea>
                    @error('observaciones_revision') <span class="ficha-error">{{ $message }}</span> @enderror
                </div>
                <div class="ficha-actions-bar">
                    <button type="submit" formaction="{{ route('personal.fichas.observe', $ficha->id) }}" class="btn btn-outline">Marcar observado</button>
                    <button type="submit" class="btn btn-primary">Aprobar trabajador</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
