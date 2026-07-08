@extends('layouts.public')

@section('title', 'Ficha del colaborador - Proserge')

@section('content')
<style>
    .public-ficha-guide .ficha-card-body {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .public-guide-steps {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .public-guide-step {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 10px;
        padding: 14px;
        border: 1px solid #dbeafe;
        border-radius: 8px;
        background: #f8fbff;
    }

    .public-guide-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 999px;
        background: #0f766e;
        color: #fff;
        font-size: 13px;
        font-weight: 800;
    }

    .public-guide-step strong,
    .public-guide-docs h3 {
        display: block;
        margin: 0 0 6px;
        color: #0f172a;
        font-size: 13px;
        font-weight: 800;
    }

    .public-guide-step p,
    .public-guide-docs li,
    .public-guide-photo {
        margin: 0;
        color: #475569;
        font-size: 13px;
        line-height: 1.55;
    }

    .public-guide-link,
    .public-guide-docs a {
        color: #0f766e;
        font-weight: 700;
        text-decoration: none;
    }

    .public-guide-link:hover,
    .public-guide-docs a:hover {
        text-decoration: underline;
    }

    .public-guide-details {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        overflow: hidden;
    }

    .public-guide-details summary {
        cursor: pointer;
        padding: 13px 14px;
        color: #172033;
        font-size: 13px;
        font-weight: 800;
        background: #f8fafc;
    }

    .public-guide-docs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        padding: 14px;
        border-top: 1px solid #e2e8f0;
    }

    .public-guide-docs ul {
        margin: 0;
        padding-left: 18px;
    }

    .public-guide-docs li + li {
        margin-top: 6px;
    }

    .public-guide-photo {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #ccfbf1;
        border-radius: 8px;
        background: #f0fdfa;
        color: #0f766e;
    }

    .public-guide-warning {
        margin: 0;
    }

    .public-signature-help {
        padding: 12px 14px;
        border: 1px solid #dbeafe;
        border-radius: 8px;
        background: #f8fbff;
        color: #334155;
        font-size: 13px;
        line-height: 1.55;
    }

    .public-signature-help strong {
        display: block;
        margin-bottom: 6px;
        color: #0f172a;
        font-weight: 800;
    }

    .public-signature-help ul {
        margin: 0;
        padding-left: 18px;
    }

    .public-signature-help li + li {
        margin-top: 4px;
    }

    @media (max-width: 1024px) {
        .public-guide-steps,
        .public-guide-docs {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .public-guide-step {
            grid-template-columns: 30px minmax(0, 1fr);
            padding: 12px;
        }

        .public-guide-number {
            width: 28px;
            height: 28px;
        }
    }
</style>

<div class="public-ficha-container ficha-workspace">
    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h1 class="ficha-card-title">Ficha del colaborador</h1>
                <p class="ficha-card-subtitle">
                    @if($ficha)
                        {{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}
                    @else
                        Link no disponible
                    @endif
                </p>
            </div>
            @if($mode === 'edit')
                <span class="ficha-status ficha-status-pending">Pendiente</span>
            @elseif($mode === 'readonly')
                <span class="ficha-status ficha-status-sent">Ficha enviada</span>
            @elseif(in_array($mode, ['expired', 'disabled', 'invalid'], true))
                <span class="ficha-status ficha-status-expired">No disponible</span>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ str_contains((string) session('success'), 'Ficha enviada') ? session('success') : 'Ficha enviada correctamente. RRHH revisara tu informacion.' }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="ficha-alert ficha-alert-danger">
            <strong>No se pudo enviar la ficha.</strong>
            Revisa los campos marcados y vuelve a intentarlo.
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($mode === 'edit')
        <div class="ficha-alert" id="localDraftNotice" style="display:none;">
            Se recupero un borrador local de esta ficha en este dispositivo.
        </div>
    @endif

    @if(in_array($mode, ['invalid', 'expired', 'disabled'], true))
        <div class="ficha-card">
            <div class="ficha-card-body">
                <div class="ficha-alert ficha-alert-danger">
                    Este link no esta habilitado. Solicita a RRHH un nuevo enlace si necesitas completar la ficha.
                </div>
            </div>
        </div>
    @else
        @php
            $readonly = $mode !== 'edit';
            $familyRows = old('familiares');
            if (!is_array($familyRows)) {
                $familyRows = $familiares->count() > 0
                    ? $familiares->map(fn ($item) => [
                        'nombres_apellidos' => $item->nombres_apellidos,
                        'parentesco' => $item->parentesco,
                        'fecha_nacimiento' => optional($item->fecha_nacimiento)->toDateString(),
                        'tipo_documento' => $item->tipo_documento,
                        'numero_documento' => $item->numero_documento,
                        'telefono' => $item->telefono,
                        'vive_con_trabajador' => $item->vive_con_trabajador,
                        'estudia' => $item->estudia,
                        'contacto_emergencia' => $item->contacto_emergencia,
                    ])->values()->all()
                    : collect(['Padre', 'Madre', 'Conyuge'])->map(fn ($parentesco) => [
                        'nombres_apellidos' => '',
                        'parentesco' => $parentesco,
                        'fecha_nacimiento' => '',
                        'tipo_documento' => 'DNI',
                        'numero_documento' => '',
                        'telefono' => '',
                        'vive_con_trabajador' => false,
                        'estudia' => false,
                        'contacto_emergencia' => false,
                    ])->all();
            }
            $currentFieldValue = fn (string $key): string => (string) old('fields.' . $key, $data[$key] ?? '');
        @endphp

        @if(!$readonly)
            <div class="ficha-card public-ficha-guide">
                <div class="ficha-card-header">
                    <div>
                        <h2 class="ficha-card-title">Guia para completar tu ficha</h2>
                        <p class="ficha-card-subtitle">Lee estos pasos antes de enviar tu informacion.</p>
                    </div>
                </div>
                <div class="ficha-card-body">
                    @include('ficha-public.partials.quick-guide')
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('ficha-colaborador.submit', ['token' => $token]) }}" enctype="multipart/form-data" class="ficha-workspace" id="workerFichaForm">
            @csrf

            <div class="ficha-card">
                <div class="ficha-card-body">
                    @foreach($sections as $section)
                        <section class="ficha-section">
                            <div class="ficha-section-header">
                                <h2 class="ficha-section-title">{{ $section['title'] }}</h2>
                            </div>
                            @if(($section['key'] ?? '') === 'quinta_categoria')
                                <div class="ficha-card-body" style="padding-bottom:0;">
                                    <div class="ficha-alert" style="background:#fff;border-color:#e2e8f0;color:#334155;">
                                        Por la presente cumplo con informar cual es mi empleador principal para la retencion de Quinta Categoria. Si P&S PROSERGE S.R.L. es mi empleador principal, declaro si percibo o no otras remuneraciones y, de corresponder, registro los otros empleadores en la tabla inferior.
                                    </div>
                                </div>
                            @endif
                            <div class="ficha-fields">
                                @foreach($section['fields'] as $field)
                                    @php
                                        $key = $field['key'];
                                        $type = $field['type'];
                                        $value = old('fields.' . $key, $data[$key] ?? '');
                                        $locked = (bool) ($field['locked_public'] ?? false);
                                        $fieldReadonly = $readonly || $locked;
                                        $isTextarea = $type === 'textarea';
                                        $isVerify = in_array($key, $verifyFields ?? [], true);
                                        $fieldClass = $isTextarea ? 'ficha-field ficha-field-wide' : 'ficha-field';
                                        $paisNacimientoActual = $currentFieldValue('pais_nacimiento') ?: 'Peru';
                                        $domicilioPaisActual = $currentFieldValue('domicilio_tipo') ?: 'Peru';
                                        $bancoActual = $currentFieldValue('banco');
                                        $sistemaPensionarioActual = $currentFieldValue('sistema_pensionario');
                                        $quintaEmpleadorActual = $currentFieldValue('quinta_empleador_principal');
                                        $isConditionallyRequired = match ($key) {
                                            'estado_civil_otro' => $currentFieldValue('estado_civil') === 'Otro',
                                            'nacionalidad_otra' => $currentFieldValue('nacionalidad') === 'Otra',
                                            'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual === 'Otro',
                                            'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual !== 'Otro',
                                            'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual === 'Extranjero',
                                            'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual !== 'Extranjero',
                                            'numero_cuenta' => in_array($bancoActual, ['BCP', 'Interbank'], true),
                                            'banco_otro' => $bancoActual === 'Otro',
                                            'cci' => $bancoActual === 'Otro',
                                            'tipo_comision', 'tipo_afp', 'cuspp' => $sistemaPensionarioActual === 'Sistema Privado de Pensiones',
                                            'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $quintaEmpleadorActual === 'Otra empresa',
                                            default => false,
                                        };
                                        $isRequired = (bool) ($field['required'] ?? false) || $isConditionallyRequired;
                                        $conditionalHidden = match ($key) {
                                            'estado_civil_otro' => $currentFieldValue('estado_civil') !== 'Otro',
                                            'nacionalidad_otra' => $currentFieldValue('nacionalidad') !== 'Otra',
                                            'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual !== 'Otro',
                                            'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual === 'Otro',
                                            'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual !== 'Extranjero',
                                            'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual === 'Extranjero',
                                            'numero_cuenta' => !in_array($bancoActual, ['BCP', 'Interbank'], true),
                                            'banco_otro' => $bancoActual !== 'Otro',
                                            'cci' => $bancoActual === '',
                                            'tipo_comision', 'tipo_afp', 'cuspp' => $currentFieldValue('sistema_pensionario') !== 'Sistema Privado de Pensiones',
                                            'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $currentFieldValue('quinta_empleador_principal') !== 'Otra empresa',
                                            default => false,
                                        };
                                        $fieldDisabled = $fieldReadonly || $conditionalHidden;
                                    @endphp
                                    @if($type === 'hidden')
                                        <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                                        @continue
                                    @endif
                                    <div class="{{ $fieldClass }}" data-ficha-field="{{ $key }}" style="{{ $conditionalHidden ? 'display:none;' : '' }}">
                                        <label class="ficha-label" for="field_{{ $key }}">
                                            {{ $field['label'] }}
                                            @if($isRequired)
                                                <span class="ficha-required">*</span>
                                            @endif
                                            @if($isVerify && !$locked)
                                                <span class="ficha-status" style="padding:2px 7px;font-size:10px;">Verificar</span>
                                            @endif
                                        </label>

                                        @if($locked)
                                            <input type="hidden" name="fields[{{ $key }}]" value="{{ $value }}">
                                        @endif

                                        @if($key === 'puesto')
                                            @include('personal.partials.puesto-autocomplete', [
                                                'name' => 'fields[' . $key . ']',
                                                'value' => $value,
                                                'inputId' => 'field_' . $key,
                                                'listId' => 'puestos_catalogo_publico',
                                                'class' => 'ficha-input',
                                                'required' => (!$fieldDisabled && $isRequired && !$locked),
                                                'disabled' => $fieldDisabled,
                                                'options' => $puestoOptions ?? [],
                                            ])
                                            @if($fieldReadonly && !$locked)
                                                <input type="hidden" name="fields[{{ $key }}]" value="{{ $value }}">
                                            @endif
                                        @elseif($type === 'select')
                                            <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" data-current-value="{{ $value }}" {{ $fieldDisabled ? 'disabled' : '' }} {{ (!$fieldDisabled && $isRequired && !$locked) ? 'required' : '' }}>
                                                <option value="">Seleccionar</option>
                                                @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                            @if($fieldReadonly && !$locked)
                                                <input type="hidden" name="fields[{{ $key }}]" value="{{ $value }}">
                                            @endif
                                        @elseif($isTextarea)
                                            <textarea class="ficha-textarea" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" {{ $fieldReadonly ? 'readonly' : '' }} {{ (!$fieldReadonly && $conditionalHidden) ? 'disabled' : '' }} {{ (!$fieldReadonly && !$conditionalHidden && $isRequired) ? 'required' : '' }}>{{ $value }}</textarea>
                                        @else
                                            <input class="ficha-input" id="field_{{ $key }}" type="{{ $type }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}" {{ $fieldReadonly ? 'readonly' : '' }} {{ (!$fieldReadonly && $conditionalHidden) ? 'disabled' : '' }} {{ (!$fieldReadonly && !$conditionalHidden && $isRequired) ? 'required' : '' }}>
                                        @endif

                                        @error('fields.' . $key)
                                            <span class="ficha-error">{{ $message }}</span>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Remuneraciones percibidas de otros empleadores</h2>
                            @if(!$readonly)
                                <button type="button" class="btn btn-outline btn-sm" id="addEmployerBtn">Agregar empleador</button>
                            @endif
                        </div>
                        <div class="ficha-card-body">
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
                            <h2 class="ficha-section-title">Familiares o contactos de emergencia</h2>
                            @if(!$readonly)
                                <button type="button" class="btn btn-outline btn-sm" id="addFamilyBtn">Agregar familiar</button>
                            @endif
                        </div>
                        <div class="ficha-card-body">
                            <div class="ficha-family-list" id="familyList">
                                <div class="ficha-batch-table-wrap">
                                    <table class="ficha-batch-table ficha-family-table">
                                        <thead>
                                            <tr>
                                                <th>Parentesco</th>
                                                <th>Apellidos y nombres</th>
                                                <th>Fecha nacimiento</th>
                                                <th>Vive conmigo</th>
                                                <th>Estudia</th>
                                                <th>Telefono</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="familyTableBody">
                                            @foreach($familyRows as $index => $familiar)
                                                <tr data-family-item>
                                                    <td><input class="ficha-input" name="familiares[{{ $index }}][parentesco]" value="{{ $familiar['parentesco'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td>
                                                        <input class="ficha-input" name="familiares[{{ $index }}][nombres_apellidos]" value="{{ $familiar['nombres_apellidos'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}>
                                                        <input type="hidden" name="familiares[{{ $index }}][tipo_documento]" value="{{ $familiar['tipo_documento'] ?? 'DNI' }}">
                                                        <input type="hidden" name="familiares[{{ $index }}][numero_documento]" value="{{ $familiar['numero_documento'] ?? '' }}">
                                                        <input type="hidden" name="familiares[{{ $index }}][contacto_emergencia]" value="{{ ($familiar['contacto_emergencia'] ?? false) ? '1' : '0' }}">
                                                    </td>
                                                    <td><input class="ficha-input" type="date" name="familiares[{{ $index }}][fecha_nacimiento]" value="{{ $familiar['fecha_nacimiento'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td style="text-align:center;"><input type="checkbox" name="familiares[{{ $index }}][vive_con_trabajador]" value="1" @checked((bool) ($familiar['vive_con_trabajador'] ?? false)) {{ $readonly ? 'disabled' : '' }}></td>
                                                    <td style="text-align:center;"><input type="checkbox" name="familiares[{{ $index }}][estudia]" value="1" @checked((bool) ($familiar['estudia'] ?? false)) {{ $readonly ? 'disabled' : '' }}></td>
                                                    <td><input class="ficha-input" name="familiares[{{ $index }}][telefono]" value="{{ $familiar['telefono'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td>@if(!$readonly)<button type="button" class="btn btn-outline btn-sm" data-remove-family aria-label="Eliminar familiar">X</button>@endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            @error('familiares') <span class="ficha-error">{{ $message }}</span> @enderror
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Documentos requeridos</h2>
                        </div>
                        <div class="ficha-card-body">
                            @if(!$readonly)
                                <div class="ficha-alert" style="background:#fff7ed;border-color:#fed7aa;color:#9a3412;">
                                    Si tienes los documentos a la mano, adjuntalos ahora. Si omites este paso, tu ficha se podra enviar, pero quedara pendiente de regularizacion documentaria con RRHH.
                                </div>
                            @endif
                            <div class="ficha-fields" style="padding:0;">
                                @foreach($documentRequirements as $docKey => $requirement)
                                    @php
                                        $storedDoc = ($archivos ?? collect())->firstWhere('tipo', $docKey);
                                        $docLabel = $requirement['label'] ?? $requirement;
                                        $docRequired = (bool) ($requirement['required'] ?? true);
                                    @endphp
                                    <div class="ficha-field ficha-field-wide">
                                        <label class="ficha-label" for="documento_{{ $docKey }}">
                                            {{ $docLabel }}
                                            @if(!$readonly)
                                                <span class="ficha-status" style="padding:2px 7px;font-size:10px;">Opcional</span>
                                            @endif
                                        </label>
                                        @if($readonly)
                                            <div class="ficha-input" style="height:auto;min-height:42px;">
                                                {{ $storedDoc?->nombre_original ?: 'Documento registrado' }}
                                            </div>
                                        @else
                                            <input id="documento_{{ $docKey }}" class="ficha-input js-draft-file-input" type="file" name="documentos[{{ $docKey }}]" data-file-draft-key="documentos.{{ $docKey }}" data-server-draft-url="{{ route('ficha-colaborador.archivo-borrador', ['token' => $token]) }}" data-server-draft-tipo="{{ $docKey }}" data-has-server-file="{{ $storedDoc ? '1' : '0' }}" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                                            <div class="ficha-card-subtitle js-draft-file-status" data-file-status-for="documento_{{ $docKey }}" style="margin-top:6px; display:block;">
                                                @if($storedDoc)
                                                    Ya cargado: {{ $storedDoc->nombre_original }}. Puedes reemplazarlo seleccionando otro archivo.
                                                @elseif($docRequired)
                                                    Faltara regularizar este documento si no lo adjuntas ahora.
                                                @else
                                                    Si este documento aplica para tu caso, puedes adjuntarlo ahora.
                                                @endif
                                            </div>
                                            @error('documentos.' . $docKey) <span class="ficha-error">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Declaraciones finales</h2>
                        </div>
                        <div class="ficha-card-body">
                            <div class="ficha-workspace" style="gap:10px;">
                                @foreach($declarationCheckboxes as $declarationKey => $declarationLabel)
                                    @if($readonly)
                                        <div class="ficha-input" style="height:auto;min-height:42px;line-height:1.5;">{{ $declarationLabel }}</div>
                                    @else
                                        <label class="ficha-check ficha-declaration-check" style="align-items:flex-start;line-height:1.5;">
                                            <input type="checkbox" name="declaraciones[{{ $declarationKey }}]" value="1" @checked(old('declaraciones.' . $declarationKey)) required>
                                            <span>{{ $declarationLabel }}</span>
                                        </label>
                                        @error('declaraciones.' . $declarationKey) <span class="ficha-error">{{ $message }}</span> @enderror
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Firma digital</h2>
                        </div>
                        <div class="ficha-card-body">
                            @if($readonly && $firmaBase64)
                                <img class="ficha-preview-image" src="{{ $firmaBase64 }}" alt="Firma digital">
                            @else
                                <div class="public-signature-help">
                                    <strong>Firma dentro del recuadro.</strong>
                                    <ul>
                                        <li>Usa tu dedo en celular o el mouse en computadora y dibuja tu firma completa.</li>
                                        <li>No coloques solo una raya, iniciales sueltas ni la huella del dedo en esta parte.</li>
                                        <li>Si te equivocas, presiona "Limpiar firma" y vuelve a firmar antes de enviar.</li>
                                    </ul>
                                </div>
                                <div class="signature-pad-wrap">
                                    <canvas id="signaturePad" class="signature-pad"></canvas>
                                    <input type="hidden" name="firma_base64" id="firmaBase64" value="{{ old('firma_base64') }}">
                                    <div class="ficha-actions-bar" style="justify-content:flex-start;">
                                        <button type="button" class="btn btn-outline btn-sm" id="clearSignature">Limpiar firma</button>
                                    </div>
                                    @error('firma_base64') <span class="ficha-error">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Huella digital</h2>
                        </div>
                        <div class="ficha-card-body">
                            @if($readonly && $huellaDataUrl)
                                <img class="ficha-preview-image" src="{{ $huellaDataUrl }}" alt="Huella digital">
                            @else
                                <div class="public-signature-help">
                                    <strong>Sube una foto clara de tu huella en papel.</strong>
                                    <ul>
                                        <li>Marca tu dedo con tinta, coloca la huella en una hoja blanca y toma una foto enfocada.</li>
                                        <li>No subas una foto del dedo. Debe verse la huella marcada en el papel.</li>
                                        <li>La imagen debe tener buena luz y mostrar la huella completa.</li>
                                    </ul>
                                </div>
                                <div class="ficha-fields" style="padding:0;">
                                    <div class="ficha-field ficha-field-wide">
                                        <label class="ficha-label" for="huella">Foto de huella <span class="ficha-required">*</span></label>
                                        <input id="huella" class="ficha-input js-draft-file-input" type="file" name="huella" data-file-draft-key="huella" data-server-draft-url="{{ route('ficha-colaborador.archivo-borrador', ['token' => $token]) }}" data-server-draft-tipo="huella" data-has-server-file="{{ $huellaDataUrl ? '1' : '0' }}" accept="image/*" capture="environment" {{ $huellaDataUrl ? '' : 'required' }}>
                                        <div class="ficha-card-subtitle js-draft-file-status" data-file-status-for="huella" style="margin-top:6px; display:{{ $huellaDataUrl ? 'block' : 'none' }};">
                                            @if($huellaDataUrl)
                                                Huella ya cargada. Puedes reemplazarla seleccionando otra imagen.
                                            @endif
                                        </div>
                                        @error('huella') <span class="ficha-error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="ficha-field">
                                        <img id="huellaPreview" class="ficha-preview-image" src="{{ $huellaDataUrl ?: '' }}" style="display:{{ $huellaDataUrl ? 'block' : 'none' }};" alt="Previsualizacion de huella">
                                    </div>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            </div>

            @if(!$readonly)
                <div class="ficha-actions-bar">
                    <button type="submit" class="btn btn-primary">Enviar ficha</button>
                </div>
            @endif
        </form>
    @endif
</div>
@endsection

@push('scripts')
@if(in_array($mode, ['edit', 'readonly'], true))
@php
    $draftRevisionKey = implode(':', [
        $ficha?->estado ?? '',
        optional($ficha?->observed_at)->timestamp ?? 0,
        optional($ficha?->submitted_at)->timestamp ?? 0,
    ]);
@endphp
<script>
document.addEventListener('DOMContentLoaded', function () {
    const isReadonly = @json($mode !== 'edit');
    const form = document.getElementById('workerFichaForm');
    const canvas = document.getElementById('signaturePad');
    const hidden = document.getElementById('firmaBase64');
    const clearBtn = document.getElementById('clearSignature');
    const draftNotice = document.getElementById('localDraftNotice');
    const draftStorageBaseKey = 'proserge:ficha-borrador:' + @json($token);
    const draftRevisionKey = @json($draftRevisionKey);
    const draftStorageKey = draftStorageBaseKey + ':' + draftRevisionKey;
    const draftFileDbName = 'proserge-ficha-drafts';
    const draftFileStoreName = 'files';
    const draftFileInputs = Array.from(document.querySelectorAll('.js-draft-file-input'));
    const csrfToken = form?.querySelector('input[name="_token"]')?.value || '';
    const draftDataUrl = @json(route('ficha-colaborador.datos-borrador', ['token' => $token]));
    let drawing = false;
    let hasSignature = Boolean(hidden && hidden.value);

    try {
        window.localStorage.removeItem(draftStorageBaseKey);
    } catch (error) {
        // noop
    }

    if (canvas && hidden) {
        const ctx = canvas.getContext('2d');

        const resizeCanvas = function () {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();
            const current = hasSignature ? hidden.value : null;
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.4;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            if (current) {
                const img = new Image();
                img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
                img.src = current;
            }
        };

        const point = function (event) {
            const source = event.touches?.[0] || event.changedTouches?.[0] || event;
            const rect = canvas.getBoundingClientRect();
            return {
                x: source.clientX - rect.left,
                y: source.clientY - rect.top,
            };
        };

        const startDrawing = function (event) {
            event.preventDefault();
            drawing = true;
            hasSignature = true;
            if (event.pointerId !== undefined && canvas.setPointerCapture) {
                canvas.setPointerCapture(event.pointerId);
            }
            const p = point(event);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        };

        const moveDrawing = function (event) {
            if (!drawing) return;
            event.preventDefault();
            const p = point(event);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hidden.value = canvas.toDataURL('image/png');
        };

        const stop = function (event) {
            if (!drawing) return;
            event?.preventDefault?.();
            drawing = false;
            hidden.value = canvas.toDataURL('image/png');
        };

        if (window.PointerEvent) {
            canvas.addEventListener('pointerdown', startDrawing);
            canvas.addEventListener('pointermove', moveDrawing);
            canvas.addEventListener('pointerup', stop);
            canvas.addEventListener('pointercancel', stop);
        } else {
            canvas.addEventListener('touchstart', startDrawing, { passive: false });
            canvas.addEventListener('touchmove', moveDrawing, { passive: false });
            canvas.addEventListener('touchend', stop, { passive: false });
            canvas.addEventListener('touchcancel', stop, { passive: false });
            canvas.addEventListener('mousedown', startDrawing);
            window.addEventListener('mousemove', moveDrawing);
            window.addEventListener('mouseup', stop);
        }
        window.addEventListener('resize', resizeCanvas);

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hidden.value = '';
                hasSignature = false;
            });
        }

        resizeCanvas();
    }

    const huella = document.getElementById('huella');
    const preview = document.getElementById('huellaPreview');
    if (huella && preview) {
        huella.addEventListener('change', function () {
            const file = huella.files && huella.files[0];
            if (!file) {
                preview.style.display = 'none';
                preview.removeAttribute('src');
                return;
            }
            preview.src = URL.createObjectURL(file);
            preview.style.display = 'block';
        });
    }

    draftFileInputs.forEach(function (input) {
        input.addEventListener('change', async function () {
            await saveDraftFile(input);
            await uploadDraftFile(input);
        });
    });

    const readDraft = function () {
        if (isReadonly) return null;
        try {
            const raw = window.localStorage.getItem(draftStorageKey);
            const draft = raw ? JSON.parse(raw) : null;
            if (draft && draft.revision_key && draft.revision_key !== draftRevisionKey) {
                window.localStorage.removeItem(draftStorageKey);
                return null;
            }

            return draft;
        } catch (error) {
            return null;
        }
    };

    const openDraftFileDb = function () {
        if (isReadonly || !window.indexedDB) {
            return Promise.resolve(null);
        }

        return new Promise(function (resolve) {
            const request = window.indexedDB.open(draftFileDbName, 1);

            request.onupgradeneeded = function (event) {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(draftFileStoreName)) {
                    db.createObjectStore(draftFileStoreName, { keyPath: 'id' });
                }
            };

            request.onsuccess = function () {
                resolve(request.result);
            };

            request.onerror = function () {
                resolve(null);
            };
        });
    };

    const draftFileRecordId = function (key) {
        return draftStorageKey + ':' + key;
    };

    const setFileStatus = function (input, message) {
        const statusNode = input
            ? document.querySelector('[data-file-status-for="' + input.id + '"]')
            : null;

        if (!statusNode) return;

        if (!message) {
            statusNode.textContent = '';
            statusNode.style.display = 'none';
            return;
        }

        statusNode.textContent = message;
        statusNode.style.display = 'block';
    };

    const uploadDraftFile = async function (input) {
        if (isReadonly || !input || !input.dataset.serverDraftUrl || !input.dataset.serverDraftTipo) return false;

        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return false;

        const payload = new FormData();
        payload.append('_token', csrfToken);
        payload.append('tipo', input.dataset.serverDraftTipo);
        payload.append('archivo', file);

        setFileStatus(input, 'Guardando archivo en la ficha...');

        try {
            const response = await fetch(input.dataset.serverDraftUrl, {
                method: 'POST',
                body: payload,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || 'No se pudo guardar el archivo.');
            }

            input.required = false;
            input.dataset.hasServerFile = '1';
            setFileStatus(input, 'Guardado en la ficha: ' + (data.nombre_original || file.name));
            try {
                input.value = '';
            } catch (error) {
                // noop
            }

            return true;
        } catch (error) {
            setFileStatus(input, (error && error.message ? error.message : 'No se pudo guardar el archivo.') + ' Vuelve a intentarlo o mantenlo seleccionado antes de enviar.');
            return false;
        }
    };

    const saveDraftFile = async function (input) {
        if (isReadonly || !input || !input.dataset.fileDraftKey) return;

        const db = await openDraftFileDb();
        if (!db) return;

        const tx = db.transaction(draftFileStoreName, 'readwrite');
        const store = tx.objectStore(draftFileStoreName);
        const recordId = draftFileRecordId(input.dataset.fileDraftKey);
        const file = input.files && input.files[0] ? input.files[0] : null;

        if (!file) {
            store.delete(recordId);
            setFileStatus(input, '');
            return;
        }

        store.put({
            id: recordId,
            key: input.dataset.fileDraftKey,
            file: file,
            name: file.name,
            type: file.type,
            size: file.size,
            saved_at: new Date().toISOString(),
        });

        setFileStatus(input, 'Documento recuperable en este equipo: ' + file.name);
    };

    const loadDraftFile = async function (input) {
        if (isReadonly || !input || !input.dataset.fileDraftKey) return false;
        if (input.dataset.hasServerFile === '1') return false;

        const db = await openDraftFileDb();
        if (!db) return false;

        const record = await new Promise(function (resolve) {
            const tx = db.transaction(draftFileStoreName, 'readonly');
            const store = tx.objectStore(draftFileStoreName);
            const request = store.get(draftFileRecordId(input.dataset.fileDraftKey));
            request.onsuccess = function () {
                resolve(request.result || null);
            };
            request.onerror = function () {
                resolve(null);
            };
        });

        if (!record || !record.file) {
            setFileStatus(input, '');
            return false;
        }

        try {
            const transfer = new DataTransfer();
            transfer.items.add(record.file);
            input.files = transfer.files;
            setFileStatus(input, 'Documento recuperado: ' + (record.name || 'archivo guardado'));

            if (input.id === 'huella' && preview) {
                preview.src = URL.createObjectURL(record.file);
                preview.style.display = 'block';
            }

            return true;
        } catch (error) {
            setFileStatus(input, 'Documento guardado en este equipo: ' + (record.name || 'archivo') + '. Si no aparece adjunto, vuelve a seleccionarlo.');
            return false;
        }
    };

    const clearDraftFiles = async function () {
        if (isReadonly || draftFileInputs.length === 0) return;

        const db = await openDraftFileDb();
        if (!db) return;

        const tx = db.transaction(draftFileStoreName, 'readwrite');
        const store = tx.objectStore(draftFileStoreName);
        draftFileInputs.forEach(function (input) {
            if (!input.dataset.fileDraftKey) return;
            store.delete(draftFileRecordId(input.dataset.fileDraftKey));
            setFileStatus(input, '');
        });
    };

    const collectDraft = function () {
        const draft = {
            revision_key: draftRevisionKey,
            fields: {},
            familiares: [],
            declaraciones: {},
            firma_base64: hidden?.value || '',
            quinta_otros_empleadores_json: '',
            saved_at: new Date().toISOString(),
        };

        form.querySelectorAll('[data-ficha-key]').forEach(function (input) {
            if (!input.name) return;
            draft.fields[input.getAttribute('data-ficha-key')] = input.value;
        });

        const familyRows = document.querySelectorAll('#familyTableBody [data-family-item]');
        familyRows.forEach(function (row) {
            const payload = {};
            row.querySelectorAll('[name]').forEach(function (input) {
                const match = input.name.match(/\[([a-z_]+)\]$/i);
                if (!match) return;
                const key = match[1];
                if (input.type === 'checkbox') {
                    payload[key] = input.checked;
                    return;
                }
                payload[key] = input.value;
            });
            draft.familiares.push(payload);
        });

        form.querySelectorAll('input[name^="declaraciones["]').forEach(function (input) {
            const match = input.name.match(/declaraciones\[([^\]]+)\]/);
            if (!match) return;
            draft.declaraciones[match[1]] = input.checked;
        });

        const employersField = document.getElementById('field_quinta_otros_empleadores_json');
        if (employersField) {
            draft.quinta_otros_empleadores_json = employersField.value || '';
        }

        return draft;
    };

    const writeDraft = function () {
        if (isReadonly || !form) return null;

        const draft = collectDraft();
        window.localStorage.setItem(draftStorageKey, JSON.stringify(draft));

        return draft;
    };

    const appendDraftPayload = function (payload, draft) {
        payload.append('_token', csrfToken);

        Object.entries(draft.fields || {}).forEach(function ([key, value]) {
            payload.append('fields[' + key + ']', value ?? '');
        });

        (draft.familiares || []).forEach(function (familiar, index) {
            Object.entries(familiar || {}).forEach(function ([key, value]) {
                payload.append('familiares[' + index + '][' + key + ']', value ? String(value) : '');
            });
        });

        Object.entries(draft.declaraciones || {}).forEach(function ([key, checked]) {
            if (checked) {
                payload.append('declaraciones[' + key + ']', '1');
            }
        });

        if (draft.firma_base64) {
            payload.append('firma_base64', draft.firma_base64);
        }
    };

    const saveDraftData = async function () {
        if (isReadonly || !form || !draftDataUrl) return false;

        syncEmployers();
        const draft = writeDraft();
        if (!draft) return false;

        const payload = new FormData();
        appendDraftPayload(payload, draft);

        try {
            const response = await fetch(draftDataUrl, {
                method: 'POST',
                body: payload,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || 'No se pudo guardar el borrador de datos.');
            }

            return true;
        } catch (error) {
            return false;
        }
    };

    const clearDraft = function () {
        try {
            window.localStorage.removeItem(draftStorageKey);
        } catch (error) {
            // noop
        }
        clearDraftFiles();
    };

    const restoreDraft = async function () {
        const draft = readDraft();
        if (!draft || !form) {
            await Promise.allSettled(draftFileInputs.map(loadDraftFile));
            return;
        }

        Object.entries(draft.fields || {}).forEach(function ([key, value]) {
            const input = form.querySelector('[data-ficha-key="' + key + '"]');
            if (!input || input.disabled || input.readOnly) return;
            input.value = value ?? '';
            if (input.tagName === 'SELECT') {
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        const employersField = document.getElementById('field_quinta_otros_empleadores_json');
        if (employersField && typeof draft.quinta_otros_empleadores_json === 'string') {
            employersField.value = draft.quinta_otros_empleadores_json;
        }

        const familyTableBody = document.getElementById('familyTableBody');
        if (familyTableBody && Array.isArray(draft.familiares) && draft.familiares.length > 0) {
            familyTableBody.innerHTML = '';
            draft.familiares.forEach(function (familiar, index) {
                const tr = document.createElement('tr');
                tr.setAttribute('data-family-item', '');
                tr.innerHTML =
                    '<td><input class="ficha-input" name="familiares[' + index + '][parentesco]" value="' + escapeHtml(familiar.parentesco || '') + '"></td>' +
                    '<td><input class="ficha-input" name="familiares[' + index + '][nombres_apellidos]" value="' + escapeHtml(familiar.nombres_apellidos || '') + '">' +
                    '<input type="hidden" name="familiares[' + index + '][tipo_documento]" value="' + escapeHtml(familiar.tipo_documento || 'DNI') + '">' +
                    '<input type="hidden" name="familiares[' + index + '][numero_documento]" value="' + escapeHtml(familiar.numero_documento || '') + '">' +
                    '<input type="hidden" name="familiares[' + index + '][contacto_emergencia]" value="' + ((familiar.contacto_emergencia ?? false) ? '1' : '0') + '"></td>' +
                    '<td><input class="ficha-input" type="date" name="familiares[' + index + '][fecha_nacimiento]" value="' + escapeHtml(familiar.fecha_nacimiento || '') + '"></td>' +
                    '<td style="text-align:center;"><input type="checkbox" name="familiares[' + index + '][vive_con_trabajador]" value="1" ' + ((familiar.vive_con_trabajador ?? false) ? 'checked' : '') + '></td>' +
                    '<td style="text-align:center;"><input type="checkbox" name="familiares[' + index + '][estudia]" value="1" ' + ((familiar.estudia ?? false) ? 'checked' : '') + '></td>' +
                    '<td><input class="ficha-input" name="familiares[' + index + '][telefono]" value="' + escapeHtml(familiar.telefono || '') + '"></td>' +
                    '<td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>';
                familyTableBody.appendChild(tr);
            });
        }

        Object.entries(draft.declaraciones || {}).forEach(function ([key, checked]) {
            const input = form.querySelector('input[name="declaraciones[' + key + ']"]');
            if (input) {
                input.checked = Boolean(checked);
            }
        });

        if (hidden && draft.firma_base64) {
            hidden.value = draft.firma_base64;
            hasSignature = true;
        }

        await Promise.allSettled(draftFileInputs.map(loadDraftFile));

        if (draftNotice) {
            draftNotice.style.display = 'block';
        }
    };

    const byKey = (key) => document.querySelector('[data-ficha-key="' + key + '"]');
    const fieldWrap = (key) => document.querySelector('[data-ficha-field="' + key + '"]');
    const setVisible = function (key, visible) {
        const node = fieldWrap(key);
        if (node) node.style.display = visible ? '' : 'none';
    };
    const setEnabled = function (key, enabled) {
        const input = byKey(key);
        if (!input) return;
        if (isReadonly) return;
        input.disabled = !enabled;
        if (!enabled && input.tagName !== 'SELECT') input.value = '';
    };

    let ubigeo = {};
    const loadUbigeo = async function () {
        try {
            const response = await fetch(@json(asset('data/ubigeo-peru.json')), { cache: 'force-cache' });
            const payload = await response.json();
            ubigeo = payload.data || {};
        } catch (error) {
            ubigeo = {};
        }
    };

    const fillSelect = function (select, values, current) {
        if (!select) return;
        const selected = current || select.dataset.currentValue || select.value;
        const matched = values.find(value => String(value).toUpperCase() === String(selected).toUpperCase()) || selected;
        select.innerHTML = '<option value="">Seleccionar</option>' + values.map(v => '<option value="' + String(v).replace(/"/g, '&quot;') + '">' + v + '</option>').join('');
        if (matched) select.value = matched;
        select.dataset.currentValue = select.value;
    };
    const bindUbigeo = function (prefix) {
        const dep = byKey(prefix + '_departamento') || byKey('departamento_' + prefix);
        const prov = byKey(prefix + '_provincia') || byKey('provincia_' + prefix);
        const dist = byKey(prefix + '_distrito') || byKey('distrito_' + prefix);
        fillSelect(dep, Object.keys(ubigeo));
        const updateProv = function () {
            fillSelect(prov, Object.keys(ubigeo[dep.value] || {}));
            updateDist();
        };
        const updateDist = function () {
            fillSelect(dist, ubigeo[dep.value]?.[prov.value] || []);
        };
        dep?.addEventListener('change', updateProv);
        prov?.addEventListener('change', updateDist);
        updateProv();
    };
    const applyConditionals = function () {
        const estadoCivilOtro = byKey('estado_civil')?.value === 'Otro';
        setVisible('estado_civil_otro', estadoCivilOtro);
        setEnabled('estado_civil_otro', estadoCivilOtro);

        const nacionalidadOtra = byKey('nacionalidad')?.value === 'Otra';
        setVisible('nacionalidad_otra', nacionalidadOtra);
        setEnabled('nacionalidad_otra', nacionalidadOtra);

        const nacimientoPais = byKey('pais_nacimiento')?.value || 'Peru';
        const nacimientoPeru = nacimientoPais === 'Peru';
        setVisible('pais_nacimiento_otro', !nacimientoPeru);
        setVisible('departamento_nacimiento', nacimientoPeru);
        setVisible('provincia_nacimiento', nacimientoPeru);
        setVisible('distrito_nacimiento', nacimientoPeru);
        setVisible('lugar_nacimiento_extranjero', !nacimientoPeru);
        setEnabled('pais_nacimiento_otro', !nacimientoPeru);
        setEnabled('lugar_nacimiento_extranjero', !nacimientoPeru);
        setEnabled('departamento_nacimiento', nacimientoPeru);
        setEnabled('provincia_nacimiento', nacimientoPeru);
        setEnabled('distrito_nacimiento', nacimientoPeru);

        const domicilioPeru = byKey('domicilio_tipo')?.value !== 'Extranjero';
        setVisible('domicilio_pais_otro', !domicilioPeru);
        setVisible('domicilio_departamento', domicilioPeru);
        setVisible('domicilio_provincia', domicilioPeru);
        setVisible('domicilio_distrito', domicilioPeru);
        setVisible('domicilio_direccion', domicilioPeru);
        setVisible('domicilio_referencia', true);
        setVisible('domicilio_extranjero', !domicilioPeru);
        setEnabled('domicilio_pais_otro', !domicilioPeru);
        setEnabled('domicilio_extranjero', !domicilioPeru);
        setEnabled('domicilio_departamento', domicilioPeru);
        setEnabled('domicilio_provincia', domicilioPeru);
        setEnabled('domicilio_distrito', domicilioPeru);
        setEnabled('domicilio_direccion', domicilioPeru);
        setEnabled('domicilio_referencia', true);

        const banco = byKey('banco')?.value || '';
        const bancoConCuenta = banco === 'BCP' || banco === 'Interbank';
        setVisible('numero_cuenta', bancoConCuenta);
        setVisible('banco_otro', banco === 'Otro');
        setVisible('cci', banco !== '');
        setEnabled('numero_cuenta', bancoConCuenta);
        setEnabled('banco_otro', banco === 'Otro');
        setEnabled('cci', banco !== '');
        if (!isReadonly && banco !== 'Otro') {
            const otroBanco = byKey('banco_otro');
            if (otroBanco) otroBanco.value = '';
        }
        if (!isReadonly && !bancoConCuenta) {
            const numeroCuenta = byKey('numero_cuenta');
            if (numeroCuenta) numeroCuenta.value = '';
        }

        const empleadorOtro = byKey('quinta_empleador_principal')?.value === 'Otra empresa';
        setVisible('quinta_otra_empresa', empleadorOtro);
        setVisible('quinta_otra_empresa_ruc', empleadorOtro);
        setEnabled('quinta_otra_empresa', empleadorOtro);
        setEnabled('quinta_otra_empresa_ruc', empleadorOtro);

        const spp = byKey('sistema_pensionario')?.value === 'Sistema Privado de Pensiones';
        setVisible('tipo_comision', spp);
        setVisible('tipo_afp', spp);
        setVisible('cuspp', spp);
        setEnabled('tipo_comision', spp);
        setEnabled('tipo_afp', spp);
        setEnabled('cuspp', spp);
    };
    ['estado_civil', 'nacionalidad', 'pais_nacimiento', 'domicilio_tipo', 'banco', 'quinta_empleador_principal', 'sistema_pensionario'].forEach(key => byKey(key)?.addEventListener('change', applyConditionals));
    applyConditionals();

    const updateQuintaDefaults = function () {
        const now = new Date();
        const month = now.toLocaleString('es-PE', { month: 'long' });
        if (byKey('quinta_fecha_anio') && !byKey('quinta_fecha_anio').value) byKey('quinta_fecha_anio').value = String(now.getFullYear());
        if (byKey('quinta_fecha_mes') && !byKey('quinta_fecha_mes').value) byKey('quinta_fecha_mes').value = month;
        if (byKey('quinta_fecha_dia') && !byKey('quinta_fecha_dia').value) byKey('quinta_fecha_dia').value = String(now.getDate()).padStart(2, '0');

        const city = byKey('domicilio_distrito')?.value || byKey('domicilio_provincia')?.value || byKey('domicilio_departamento')?.value || 'Arequipa';
        if (byKey('quinta_ciudad') && !byKey('quinta_ciudad').value) byKey('quinta_ciudad').value = city;

        const domicilio = [
            byKey('domicilio_direccion')?.value || byKey('domicilio_extranjero')?.value || '',
            byKey('domicilio_distrito')?.value || '',
            byKey('domicilio_provincia')?.value || '',
            byKey('domicilio_departamento')?.value || '',
        ].filter(Boolean).join(' / ');
        if (byKey('quinta_domicilio') && !byKey('quinta_domicilio').value && domicilio) byKey('quinta_domicilio').value = domicilio;
    };
    ['domicilio_direccion', 'domicilio_extranjero', 'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito'].forEach(key => {
        byKey(key)?.addEventListener('change', updateQuintaDefaults);
        byKey(key)?.addEventListener('input', updateQuintaDefaults);
    });
    updateQuintaDefaults();
    loadUbigeo().then(function () {
        bindUbigeo('nacimiento');
        bindUbigeo('domicilio');
        applyConditionals();
        updateQuintaDefaults();
    });

    const employerBody = document.getElementById('quintaEmployersBody');
    const employersJson = byKey('quinta_otros_empleadores_json');
    const escapeHtml = function (value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    };
    const readEmployers = function () {
        try { return JSON.parse(employersJson?.value || '[]'); } catch (e) { return []; }
    };
    const syncEmployers = function () {
        if (!employerBody || !employersJson) return;
        const rows = Array.from(employerBody.querySelectorAll('tr')).map(row => ({
            empresa: row.querySelector('[data-employer="empresa"]')?.value || '',
            ruc: row.querySelector('[data-employer="ruc"]')?.value || '',
            monto: row.querySelector('[data-employer="monto"]')?.value || '',
            retencion: row.querySelector('[data-employer="retencion"]')?.value || '',
        })).filter(row => row.empresa || row.ruc || row.monto || row.retencion);
        employersJson.value = JSON.stringify(rows);
    };
    const addEmployerRow = function (row = {}) {
        if (!employerBody) return;
        const readonlyAttr = isReadonly ? ' readonly' : '';
        const removeButton = isReadonly ? '' : '<button type="button" class="btn btn-outline btn-sm" data-remove-employer>X</button>';
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input class="ficha-input" data-employer="empresa" value="' + escapeHtml(row.empresa || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="ruc" value="' + escapeHtml(row.ruc || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="monto" value="' + escapeHtml(row.monto || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="retencion" value="' + escapeHtml(row.retencion || '') + '"' + readonlyAttr + '></td><td>' + removeButton + '</td>';
        employerBody.appendChild(tr);
    };
    readEmployers().forEach(addEmployerRow);
    if (!isReadonly && employerBody && employerBody.children.length === 0) addEmployerRow();
    document.getElementById('addEmployerBtn')?.addEventListener('click', () => addEmployerRow());
    employerBody?.addEventListener('input', syncEmployers);
    employerBody?.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-remove-employer]');
        if (!btn) return;
        btn.closest('tr')?.remove();
        if (employerBody.children.length === 0) addEmployerRow();
        syncEmployers();
    });
    document.getElementById('workerFichaForm')?.addEventListener('submit', syncEmployers);

    const list = document.getElementById('familyTableBody');
    const addBtn = document.getElementById('addFamilyBtn');

    const reindexFamilies = function () {
        Array.from(list.querySelectorAll('[data-family-item]')).forEach(function (item, index) {
            item.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(/familiares\[\d+\]/, 'familiares[' + index + ']');
            });
        });
    };

    if (!isReadonly && addBtn && list) {
        addBtn.addEventListener('click', function () {
            const count = list.querySelectorAll('[data-family-item]').length + 1;
            const clone = document.createElement('tr');
            clone.setAttribute('data-family-item', '');
            clone.innerHTML = '<td><input class="ficha-input" name="familiares[0][parentesco]" value="Hijo ' + Math.max(count - 2, 1) + '"></td><td><input class="ficha-input" name="familiares[0][nombres_apellidos]" value=""><input type="hidden" name="familiares[0][tipo_documento]" value="DNI"><input type="hidden" name="familiares[0][numero_documento]" value=""><input type="hidden" name="familiares[0][contacto_emergencia]" value="0"></td><td><input class="ficha-input" type="date" name="familiares[0][fecha_nacimiento]" value=""></td><td style="text-align:center;"><input type="checkbox" name="familiares[0][vive_con_trabajador]" value="1"></td><td style="text-align:center;"><input type="checkbox" name="familiares[0][estudia]" value="1"></td><td><input class="ficha-input" name="familiares[0][telefono]" value=""></td><td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>';
            list.appendChild(clone);
            reindexFamilies();
        });

        list.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-family]');
            if (!button) return;
            const items = list.querySelectorAll('[data-family-item]');
            if (items.length <= 1) return;
            button.closest('[data-family-item]').remove();
            reindexFamilies();
        });
    }

    if (!isReadonly) {
        restoreDraft();
        if (employerBody) {
            employerBody.innerHTML = '';
            readEmployers().forEach(addEmployerRow);
            if (employerBody.children.length === 0) addEmployerRow();
        }
        applyConditionals();
        updateQuintaDefaults();

        let autosaveTimer = null;
        let serverAutosaveTimer = null;
        let lastServerAutosave = Promise.resolve(false);
        let draftSubmitting = false;
        const scheduleDraftSave = function () {
            window.clearTimeout(autosaveTimer);
            window.clearTimeout(serverAutosaveTimer);
            autosaveTimer = window.setTimeout(writeDraft, 500);
            serverAutosaveTimer = window.setTimeout(function () {
                lastServerAutosave = saveDraftData();
            }, 1500);
        };

        form?.addEventListener('input', scheduleDraftSave, true);
        form?.addEventListener('change', scheduleDraftSave, true);
        form?.addEventListener('submit', async function (event) {
            if (draftSubmitting) {
                draftSubmitting = false;
                return;
            }

            event.preventDefault();
            draftSubmitting = true;
            window.clearTimeout(autosaveTimer);
            window.clearTimeout(serverAutosaveTimer);
            syncEmployers();
            writeDraft();
            await lastServerAutosave.catch(() => false);
            await saveDraftData();
            await Promise.allSettled(draftFileInputs.map(loadDraftFile));
            await Promise.allSettled(draftFileInputs.map(uploadDraftFile));
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            HTMLFormElement.prototype.submit.call(form);
        });
    } else if (@json(session('success') ? true : false)) {
        clearDraft();
    }
});
</script>
@endif
@endpush
