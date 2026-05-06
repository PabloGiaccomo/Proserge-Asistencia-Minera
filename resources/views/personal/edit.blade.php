@extends('layouts.app')

@section('title', 'Editar Trabajador - Proserge')

@section('content')
@php
    $selectedLocations = collect(old('minas', $trabajador['minas'] ?? []))
        ->map(fn ($value) => trim((string) $value))
        ->filter(fn (string $value) => $value !== '')
        ->unique()
        ->values()
        ->all();

    $stateByLocation = old('mina_estado', $trabajador['minas_estado'] ?? []);
    $familyRows = old('familiares', app(\App\Modules\Personal\Services\PersonalFichaService::class)->familyRowsForEdit($ficha));
@endphp
<div class="module-page ficha-workspace">
    @if(session('regularization_link'))
        <div class="ficha-alert" style="margin-bottom:12px;">
            Link temporal habilitado:
            <a href="{{ session('regularization_link') }}" target="_blank" rel="noopener">{{ session('regularization_link') }}</a>
        </div>
    @endif

    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title" style="margin:0;">Editar trabajador</h1>
                <p class="page-subtitle">Actualiza la ficha completa del trabajador y su configuracion interna.</p>
            </div>
            <div class="page-actions" style="gap:8px;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
        @if(!empty($ficha) && (($regularizationSummary['can_regularize'] ?? false) || count($missingRequiredDocuments ?? []) > 0))
            <div class="page-header-top" style="margin-top:10px;">
                <div class="ficha-alert ficha-alert-warning" style="width:100%; margin:0;">
                    Esta ficha todavia tiene informacion pendiente por regularizar. Usa el bloque de regularizacion dentro de la ficha para generar o copiar el link temporal del trabajador.
                </div>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('personal.update', $trabajador['id'] ?? request('id')) }}" enctype="multipart/form-data" class="ficha-workspace" style="display:flex; flex-direction:column; gap:16px;">
        @csrf
        @method('PUT')
        @php
            $currentFieldValue = fn (string $key): string => (string) old('fields.' . $key, $initialFields[$key] ?? '');
        @endphp

        <div class="ficha-card" id="ficha-trabajador-panel" style="order:2;">
            <div class="ficha-card-header">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h2 class="ficha-card-title" style="margin:0;">Ficha del trabajador</h2>
                        <button type="button" class="btn btn-outline btn-sm" data-toggle-panel="ficha-trabajador" style="display:inline-flex; align-items:center; gap:8px;">
                            <span data-toggle-arrow="ficha-trabajador">▼</span>
                        </button>
                    </div>
                    <p class="ficha-card-subtitle">Edita toda la informacion relevante de la ficha desde una sola vista.</p>
                </div>
                <span class="ficha-status">{{ \App\Modules\Personal\Support\PersonalFichaCatalog::stateLabel($ficha?->estado ?? ($trabajador['estado'] ?? 'ACTIVO')) }}</span>
            </div>
            <div class="ficha-card-body" id="ficha-trabajador-panel-body">
                @if(!empty($ficha))
                    @php
                        $fieldCatalog = \App\Modules\Personal\Support\PersonalFichaCatalog::fields();
                        $documentCatalog = \App\Modules\Personal\Support\PersonalFichaCatalog::documentRequirements();
                        $missingFieldLabels = collect($regularizationSummary['missing_fields'] ?? [])
                            ->map(fn ($key) => $fieldCatalog[$key]['label'] ?? $key)
                            ->values();
                        $missingDocumentLabels = collect($regularizationSummary['missing_documents'] ?? [])
                            ->map(fn ($key) => $documentCatalog[$key]['label'] ?? $key)
                            ->values();
                        $activeRegularizationLink = $regularizationSummary['url'] ?? null;
                        $activeRegularizationMeta = $regularizationSummary['link'] ?? null;
                        $hasActiveRegularizationLink = (bool) ($regularizationSummary['has_active_link'] ?? false);
                    @endphp
                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h3 class="ficha-section-title">Regularizacion por link temporal</h3>
                        </div>
                        <div class="ficha-fields">
                            <div class="ficha-field ficha-field-wide">
                                @if(($regularizationSummary['can_regularize'] ?? false))
                                    <div class="ficha-alert ficha-alert-warning" style="margin:0 0 12px 0;">
                                        @if($missingFieldLabels->isNotEmpty())
                                            <div><strong>Datos por completar:</strong> {{ $missingFieldLabels->implode(' | ') }}</div>
                                        @endif
                                        @if($missingDocumentLabels->isNotEmpty())
                                            <div style="margin-top:6px;"><strong>Documentos por adjuntar:</strong> {{ $missingDocumentLabels->implode(' | ') }}</div>
                                        @endif
                                        @if(in_array($ficha->estado, ['OBSERVADO', 'RECHAZADO', 'LINK_VENCIDO'], true))
                                            <div style="margin-top:6px;"><strong>Estado de ficha:</strong> {{ \App\Modules\Personal\Support\PersonalFichaCatalog::stateLabel($ficha->estado) }}</div>
                                        @endif
                                    </div>
                                    @if($hasActiveRegularizationLink)
                                        <div class="ficha-alert" style="margin:0 0 12px 0;">
                                            Este link temporal ya fue habilitado y ya aparece en <strong>Personal temporal y links</strong>.
                                            @if($activeRegularizationMeta?->expires_at)
                                                Vigente hasta {{ $activeRegularizationMeta->expires_at->format('d/m/Y H:i') }}.
                                            @endif
                                        </div>
                                    @else
                                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
                                            <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" onsubmit="return confirm('Se habilitara un link temporal para que el trabajador regularice su ficha.');">
                                                @csrf
                                                <button type="submit" class="btn btn-primary">Habilitar link temporal</button>
                                            </form>
                                        </div>
                                    @endif
                                @else
                                    <div class="ficha-alert" style="margin:0 0 12px 0;">
                                        Esta ficha no tiene pendientes de regularizacion por ahora.
                                    </div>
                                @endif

                                @if($activeRegularizationLink)
                                    <div class="ficha-link-box">
                                        <input id="workerRegularizationLink" class="ficha-input" type="text" value="{{ $activeRegularizationLink }}" readonly>
                                        <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="workerRegularizationLink">Copiar</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>
                @endif

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
                                    $value = $currentFieldValue($key);
                                    $isTextarea = $type === 'textarea';
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
                                        'fecha_fin_contrato' => !in_array($currentFieldValue('contrato'), ['FIJO', 'INTER', 'REG'], true),
                                        'fecha_cese' => $currentFieldValue('contrato') !== 'INDET',
                                        default => false,
                                    };
                                @endphp

                                @if($type === 'hidden')
                                    <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                                    @continue
                                @endif

                                <div class="{{ $fieldClass }}" data-ficha-field="{{ $key }}" style="{{ $conditionalHidden ? 'display:none;' : '' }}">
                                    <label class="ficha-label" for="field_{{ $key }}">
                                        {{ $field['label'] }}
                                        @if($field['required'])
                                            <span class="ficha-required">*</span>
                                        @endif
                                    </label>

                                    @if($type === 'select')
                                        <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" data-current-value="{{ $value }}" {{ $conditionalHidden ? 'disabled' : '' }}>
                                            <option value="">Seleccionar</option>
                                            @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($isTextarea)
                                        <textarea class="ficha-textarea" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" {{ $conditionalHidden ? 'disabled' : '' }}>{{ $value }}</textarea>
                                    @else
                                        <input class="ficha-input" id="field_{{ $key }}" type="{{ $type }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}" {{ $conditionalHidden ? 'disabled' : '' }}>
                                    @endif

                                    @error('fields.' . $key)
                                        <span class="ficha-error">{{ $message }}</span>
                                    @enderror
                                    @if($key === 'contrato')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Regimen, fijo e intermitente pueden usar fin de contrato. Indeterminado usa fecha de cese o cese manual.
                                        </span>
                                    @endif
                                    @if($key === 'fecha_fin_contrato')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Cuando esta fecha vence, el trabajador pasa a cesado.
                                        </span>
                                    @endif
                                    @if($key === 'fecha_cese')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Para indeterminado, desde esta fecha el trabajador aparecera como cesado.
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Remuneraciones percibidas de otros empleadores</h3>
                        <button type="button" class="btn btn-outline btn-sm" id="addEmployerBtn">Agregar empleador</button>
                    </div>
                    <div class="ficha-card-body" style="padding:0;">
                        <div class="ficha-batch-table-wrap">
                            <table class="ficha-batch-table">
                                <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>RUC</th>
                                        <th>Monto anual</th>
                                        <th>Retencion</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="quintaEmployersBody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Familiares o contactos de emergencia</h3>
                        <button type="button" class="btn btn-outline btn-sm" id="addFamilyBtn">Agregar familiar</button>
                    </div>
                    <div class="ficha-card-body" style="padding:0;">
                        <div class="ficha-batch-table-wrap">
                            <table class="ficha-batch-table ficha-family-table">
                                <thead>
                                    <tr>
                                        <th>Parentesco</th>
                                        <th>Apellidos y nombres</th>
                                        <th>Fecha nacimiento</th>
                                        <th>Vive conmigo</th>
                                        <th>Telefono</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="familyTableBody">
                                    @foreach($familyRows as $index => $familiar)
                                        <tr data-family-item>
                                            <td><input class="ficha-input" name="familiares[{{ $index }}][parentesco]" value="{{ $familiar['parentesco'] ?? '' }}"></td>
                                            <td>
                                                <input class="ficha-input" name="familiares[{{ $index }}][nombres_apellidos]" value="{{ $familiar['nombres_apellidos'] ?? '' }}">
                                                <input type="hidden" name="familiares[{{ $index }}][tipo_documento]" value="{{ $familiar['tipo_documento'] ?? 'DNI' }}">
                                                <input type="hidden" name="familiares[{{ $index }}][numero_documento]" value="{{ $familiar['numero_documento'] ?? '' }}">
                                                <input type="hidden" name="familiares[{{ $index }}][contacto_emergencia]" value="{{ ($familiar['contacto_emergencia'] ?? false) ? '1' : '0' }}">
                                            </td>
                                            <td><input class="ficha-input" type="date" name="familiares[{{ $index }}][fecha_nacimiento]" value="{{ $familiar['fecha_nacimiento'] ?? '' }}"></td>
                                            <td style="text-align:center;"><input type="checkbox" name="familiares[{{ $index }}][vive_con_trabajador]" value="1" @checked((bool) ($familiar['vive_con_trabajador'] ?? false))></td>
                                            <td><input class="ficha-input" name="familiares[{{ $index }}][telefono]" value="{{ $familiar['telefono'] ?? '' }}"></td>
                                            <td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('familiares') <span class="ficha-error">{{ $message }}</span> @enderror
                    </div>
                </section>

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Documentos requeridos</h3>
                    </div>
                    <div class="ficha-fields">
                        @foreach(\App\Modules\Personal\Support\PersonalFichaCatalog::documentRequirements() as $docKey => $requirement)
                            @php
                                $storedDoc = $ficha?->archivos?->firstWhere('tipo', $docKey);
                                $docLabel = $requirement['label'] ?? $requirement;
                                $docRequired = (bool) ($requirement['required'] ?? true);
                            @endphp
                            <div class="ficha-field ficha-field-wide">
                                <label class="ficha-label" for="documento_{{ $docKey }}">
                                    {{ $docLabel }}
                                    @if($docRequired)
                                        <span class="ficha-required">*</span>
                                    @endif
                                </label>
                                @if($storedDoc)
                                    <div class="ficha-input" style="height:auto; min-height:42px; margin-bottom:8px;">
                                        <a href="{{ route('personal.fichas.archivos.download', $storedDoc->id) }}">{{ $storedDoc->nombre_original ?: 'Descargar documento actual' }}</a>
                                    </div>
                                @endif
                                <input id="documento_{{ $docKey }}" class="ficha-input" type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                                @error('documentos.' . $docKey)
                                    <span class="ficha-error">{{ $message }}</span>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>

        <div class="ficha-card" id="configuracion-interna-panel" style="order:1;">
            <div class="ficha-card-header">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h2 class="ficha-card-title" style="margin:0;">Configuracion interna</h2>
                        <button type="button" class="btn btn-outline btn-sm" data-toggle-panel="configuracion-interna" style="display:inline-flex; align-items:center; gap:8px;">
                            <span data-toggle-arrow="configuracion-interna">▼</span>
                        </button>
                    </div>
                    <p class="ficha-card-subtitle">Controla el estado, el perfil y las ubicaciones del trabajador.</p>
                </div>
            </div>
            <div class="ficha-card-body" id="configuracion-interna-panel-body">
                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Estado y perfil</h3>
                    </div>
                    <div class="ficha-fields">
                        <div class="ficha-field">
                            <label class="ficha-label" for="estado">Estado <span class="ficha-required">*</span></label>
                            <select class="ficha-select" id="estado" name="estado">
                                @foreach([
                                    'ACTIVO' => 'Activo',
                                    'INACTIVO' => 'Inactivo',
                                    'CESADO' => 'Cesado',
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('estado', $trabajador['estado'] ?? 'ACTIVO') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('estado')
                                <span class="ficha-error">{{ $message }}</span>
                            @enderror
                        </div>
                        @if(($initialFields['contrato'] ?? '') === 'INDET' || old('fields.contrato') === 'INDET')
                            <div class="ficha-field">
                                <label class="ficha-label">Cese rapido</label>
                                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-outline btn-sm" id="set-indet-cese-today">Marcar cese hoy</button>
                                    @if(!empty($trabajador['puede_cesar']))
                                        <form method="POST" action="{{ route('personal.cease', $trabajador['id'] ?? request('id')) }}" onsubmit="return confirm('Se marcara a este trabajador como cesado.');">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-sm">Cesar ahora</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endif
                        <div class="ficha-field">
                            <label class="ficha-label" for="es_supervisor">Es supervisor</label>
                            <select class="ficha-select" id="es_supervisor" name="es_supervisor">
                                <option value="0" @selected(old('es_supervisor', !empty($trabajador['supervisor']) ? '1' : '0') === '0')>No</option>
                                <option value="1" @selected(old('es_supervisor', !empty($trabajador['supervisor']) ? '1' : '0') === '1')>Si</option>
                            </select>
                            @error('es_supervisor')
                                <span class="ficha-error">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </section>

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Minas</h3>
                    </div>
                    <div class="mines-grid">
                        @foreach($catalogMinas as $mina)
                            @php
                                $isSelected = in_array($mina, $selectedLocations, true);
                                $estado = (string) ($stateByLocation[$mina] ?? 'habilitado');
                            @endphp
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $mina }}" id="mina_{{ md5($mina) }}" {{ $isSelected ? 'checked' : '' }}>
                                    <label for="mina_{{ md5($mina) }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $mina }}</span>
                                    </label>
                                </div>
                                <div class="mine-status-select">
                                    <select name="mina_estado[{{ $mina }}]" class="form-control form-control-sm">
                                        <option value="habilitado" {{ $estado === 'habilitado' ? 'selected' : '' }}>Habilitado</option>
                                        <option value="proceso" {{ $estado === 'proceso' ? 'selected' : '' }}>En proceso</option>
                                        <option value="no_habilitado" {{ $estado === 'no_habilitado' ? 'selected' : '' }}>No habilitado</option>
                                    </select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Oficinas y talleres</h3>
                    </div>
                    <div class="mines-grid">
                        @foreach($catalogOficinas as $oficina)
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $oficina }}" id="oficina_{{ md5($oficina) }}" {{ in_array($oficina, $selectedLocations, true) ? 'checked' : '' }}>
                                    <label for="oficina_{{ md5($oficina) }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $oficina }}</span>
                                    </label>
                                </div>
                            </div>
                        @endforeach

                        @foreach($catalogTalleres as $taller)
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $taller }}" id="taller_{{ md5($taller) }}" {{ in_array($taller, $selectedLocations, true) ? 'checked' : '' }}>
                                    <label for="taller_{{ md5($taller) }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $taller }}</span>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach($catalogOficinas as $oficina)
                        <input type="hidden" name="mina_estado[{{ $oficina }}]" value="{{ $stateByLocation[$oficina] ?? 'habilitado' }}">
                    @endforeach
                    @foreach($catalogTalleres as $taller)
                        <input type="hidden" name="mina_estado[{{ $taller }}]" value="{{ $stateByLocation[$taller] ?? 'habilitado' }}">
                    @endforeach
                </section>
            </div>
        </div>

        <div class="ficha-actions-bar">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
    @include('personal.fichas.partials.conditional-fields-script', ['scope' => 'rrhh'])
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const ceseTodayButton = document.getElementById('set-indet-cese-today');
        const fechaCeseInput = document.getElementById('field_fecha_cese');
        const familyTableBody = document.getElementById('familyTableBody');
        const addFamilyBtn = document.getElementById('addFamilyBtn');
        const employerBody = document.getElementById('quintaEmployersBody');
        const employersJson = document.getElementById('field_quinta_otros_empleadores_json');
        const addEmployerBtn = document.getElementById('addEmployerBtn');
        const panels = {
            'configuracion-interna': document.getElementById('configuracion-interna-panel-body'),
            'ficha-trabajador': document.getElementById('ficha-trabajador-panel-body'),
        };

        const syncToggleState = function (key) {
            const panel = panels[key];
            const arrow = document.querySelector('[data-toggle-arrow="' + key + '"]');
            if (!panel || !arrow) {
                return;
            }

            const hidden = panel.style.display === 'none';
            arrow.textContent = hidden ? '▼' : '▲';
        };

        document.querySelectorAll('[data-toggle-panel]').forEach(function (button) {
            button.addEventListener('click', function () {
                const key = button.getAttribute('data-toggle-panel');
                const panel = panels[key];
                if (!panel) {
                    return;
                }

                const hidden = panel.style.display === 'none';
                panel.style.display = hidden ? '' : 'none';
                syncToggleState(key);
            });
        });

        document.querySelectorAll('.js-copy-ficha-link').forEach(function (button) {
            button.addEventListener('click', async function () {
                const input = document.getElementById(button.dataset.target);
                if (!input) {
                    return;
                }

                input.select();
                input.setSelectionRange(0, 99999);

                try {
                    await navigator.clipboard.writeText(input.value);
                    button.textContent = 'Copiado';
                    setTimeout(function () {
                        button.textContent = 'Copiar';
                    }, 1800);
                } catch (error) {
                    document.execCommand('copy');
                }
            });
        });

        if (ceseTodayButton && fechaCeseInput) {
            ceseTodayButton.addEventListener('click', function () {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                fechaCeseInput.value = `${yyyy}-${mm}-${dd}`;
                fechaCeseInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        document.querySelectorAll('input[name="minas[]"]').forEach(function (checkbox) {
            const statusSelect = checkbox.closest('.mine-selection-item')?.querySelector('select');

            if (!statusSelect) {
                return;
            }

            const syncStatusControl = function () {
                statusSelect.disabled = !checkbox.checked;

                if (!checkbox.checked) {
                    statusSelect.value = 'habilitado';
                }
            };

            checkbox.addEventListener('change', syncStatusControl);
            syncStatusControl();
        });

        const escapeHtml = function (value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
            });
        };

        const readEmployers = function () {
            if (!employersJson || !employersJson.value) {
                return [];
            }

            try {
                return JSON.parse(employersJson.value);
            } catch (error) {
                return [];
            }
        };

        const syncEmployers = function () {
            if (!employerBody || !employersJson) {
                return;
            }

            const rows = Array.from(employerBody.querySelectorAll('tr')).map(function (row) {
                return {
                    empresa: row.querySelector('[data-employer="empresa"]')?.value || '',
                    ruc: row.querySelector('[data-employer="ruc"]')?.value || '',
                    monto: row.querySelector('[data-employer="monto"]')?.value || '',
                    retencion: row.querySelector('[data-employer="retencion"]')?.value || '',
                };
            }).filter(function (row) {
                return row.empresa || row.ruc || row.monto || row.retencion;
            });

            employersJson.value = JSON.stringify(rows);
        };

        const addEmployerRow = function (row) {
            if (!employerBody) {
                return;
            }

            const tr = document.createElement('tr');
            tr.innerHTML = '<td><input class="ficha-input" data-employer="empresa" value="' + escapeHtml(row?.empresa || '') + '"></td><td><input class="ficha-input" data-employer="ruc" value="' + escapeHtml(row?.ruc || '') + '"></td><td><input class="ficha-input" data-employer="monto" value="' + escapeHtml(row?.monto || '') + '"></td><td><input class="ficha-input" data-employer="retencion" value="' + escapeHtml(row?.retencion || '') + '"></td><td><button type="button" class="btn btn-outline btn-sm" data-remove-employer>X</button></td>';
            employerBody.appendChild(tr);
        };

        readEmployers().forEach(addEmployerRow);
        if (employerBody && employerBody.children.length === 0) {
            addEmployerRow({});
        }

        employerBody?.addEventListener('input', syncEmployers);
        employerBody?.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-employer]');
            if (!button) {
                return;
            }

            event.preventDefault();
            button.closest('tr')?.remove();
            if (employerBody.children.length === 0) {
                addEmployerRow({});
            }
            syncEmployers();
        });

        addEmployerBtn?.addEventListener('click', function () {
            addEmployerRow({});
            syncEmployers();
        });

        const reindexFamilyRows = function () {
            if (!familyTableBody) {
                return;
            }

            Array.from(familyTableBody.querySelectorAll('tr[data-family-item]')).forEach(function (row, index) {
                row.querySelectorAll('input').forEach(function (input) {
                    input.name = input.name.replace(/familiares\[\d+\]/, 'familiares[' + index + ']');
                });
            });
        };

        if (familyTableBody) {
            familyTableBody.addEventListener('click', function (event) {
                const button = event.target.closest('[data-remove-family]');
                if (!button) {
                    return;
                }

                event.preventDefault();
                button.closest('tr')?.remove();
                reindexFamilyRows();
            });
        }

        if (addFamilyBtn && familyTableBody) {
            addFamilyBtn.addEventListener('click', function () {
                const count = familyTableBody.querySelectorAll('tr[data-family-item]').length;
                const tr = document.createElement('tr');
                tr.setAttribute('data-family-item', '1');
                tr.innerHTML = '<td><input class="ficha-input" name="familiares[0][parentesco]" value="Hijo ' + Math.max(count - 2, 1) + '"></td><td><input class="ficha-input" name="familiares[0][nombres_apellidos]" value=""><input type="hidden" name="familiares[0][tipo_documento]" value="DNI"><input type="hidden" name="familiares[0][numero_documento]" value=""><input type="hidden" name="familiares[0][contacto_emergencia]" value="0"></td><td><input class="ficha-input" type="date" name="familiares[0][fecha_nacimiento]" value=""></td><td style="text-align:center;"><input type="checkbox" name="familiares[0][vive_con_trabajador]" value="1"></td><td><input class="ficha-input" name="familiares[0][telefono]" value=""></td><td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>';
                familyTableBody.appendChild(tr);
                reindexFamilyRows();
            });
        }

        syncEmployers();
        Object.keys(panels).forEach(syncToggleState);
    });
    </script>
@endpush
