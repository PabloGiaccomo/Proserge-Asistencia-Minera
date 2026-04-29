@extends('layouts.app')

@section('title', 'Confirmar lote de trabajadores - Proserge')

@section('content')
@php
    $fieldDefinitions = collect($sections)->flatMap(fn ($section) => $section['fields'])->keyBy('key');
    $editableKeys = ['tipo_documento', 'numero_documento', 'apellido_paterno', 'apellido_materno', 'nombres', 'correo', 'telefono', 'fecha_ingreso', 'puesto', 'contrato'];
    $generatedCount = collect($items)->where('availability.available', true)->count();
@endphp
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Confirmar trabajadores detectados</h1>
                <p class="page-subtitle">Revisa el lote y genera un link temporal por cada trabajador valido.</p>
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
            <strong>Advertencias:</strong> {{ implode(' ', $warnings) }}
        </div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">{{ count($items) }} registros leidos</h2>
                <p class="ficha-card-subtitle">{{ $generatedCount }} listos para generar link. Los duplicados se saltaran y quedaran informados.</p>
            </div>
            <span class="ficha-status ficha-status-pending">Carga masiva</span>
        </div>
    </div>

    <form method="POST" action="{{ route('personal.fichas.generate-link') }}" class="ficha-workspace">
        @csrf
        <input type="hidden" name="session_key" value="{{ $sessionKey }}">

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Campos que verificara el trabajador</h2>
                    <p class="ficha-card-subtitle">Se aplican a todos los links generados. Los campos obligatorios faltantes igual se pediran en la ficha.</p>
                </div>
            </div>
            <div class="ficha-card-body">
                <div class="ficha-status-row">
                    @foreach($defaultVerify as $key)
                        @php $field = $fieldDefinitions[$key] ?? null; @endphp
                        @if($field)
                            <label class="ficha-check">
                                <input type="checkbox" name="verify_fields[]" value="{{ $key }}" checked>
                                {{ $field['label'] }}
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Trabajadores detectados</h2>
                    <p class="ficha-card-subtitle">Puedes corregir documento, nombre, contacto, fecha, puesto y contrato antes de generar los links.</p>
                </div>
            </div>
            <div class="ficha-card-body">
                <div class="ficha-batch-table-wrap">
                    <table class="ficha-batch-table">
                        <thead>
                            <tr>
                                <th>Fila</th>
                                <th>Estado</th>
                                <th>Documento</th>
                                <th>Apellidos y nombres</th>
                                <th>Contacto</th>
                                <th>Ingreso</th>
                                <th>Puesto / contrato</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $index => $item)
                                @php
                                    $fields = $item['fields'] ?? [];
                                    if (trim((string) ($fields['tipo_documento'] ?? '')) === '') {
                                        $fields['tipo_documento'] = 'DNI';
                                    }
                                    $availability = $item['availability'] ?? ['available' => true, 'message' => 'Disponible'];
                                    $hiddenKeys = collect($fields)->keys()->diff($editableKeys);
                                @endphp
                                <tr>
                                    <td>{{ $item['row_number'] ?? ($index + 1) }}</td>
                                    <td>
                                        <span class="ficha-status {{ ($availability['available'] ?? false) ? 'ficha-status-sent' : 'ficha-status-expired' }}">
                                            {{ ($availability['available'] ?? false) ? 'Listo' : 'Duplicado' }}
                                        </span>
                                        <div class="ficha-card-subtitle" style="margin-top:6px;">{{ $availability['message'] ?? '' }}</div>
                                        @foreach(($item['warnings'] ?? []) as $warning)
                                            <div class="ficha-error">{{ $warning }}</div>
                                        @endforeach
                                    </td>
                                    <td>
                                        @foreach($hiddenKeys as $hiddenKey)
                                            <input type="hidden" name="items[{{ $index }}][fields][{{ $hiddenKey }}]" value="{{ $fields[$hiddenKey] ?? '' }}">
                                        @endforeach
                                        <select class="ficha-select ficha-compact-control" name="items[{{ $index }}][fields][tipo_documento]">
                                            @foreach(($fieldDefinitions['tipo_documento']['options'] ?? ['DNI' => 'DNI']) as $value => $label)
                                                <option value="{{ $value }}" @selected(($fields['tipo_documento'] ?? 'DNI') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][numero_documento]" value="{{ $fields['numero_documento'] ?? '' }}">
                                    </td>
                                    <td>
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][apellido_paterno]" value="{{ $fields['apellido_paterno'] ?? '' }}" placeholder="Apellido paterno">
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][apellido_materno]" value="{{ $fields['apellido_materno'] ?? '' }}" placeholder="Apellido materno">
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][nombres]" value="{{ $fields['nombres'] ?? '' }}" placeholder="Nombres">
                                    </td>
                                    <td>
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][correo]" value="{{ $fields['correo'] ?? '' }}" placeholder="Correo">
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][telefono]" value="{{ $fields['telefono'] ?? '' }}" placeholder="Telefono">
                                    </td>
                                    <td>
                                        <input class="ficha-input ficha-compact-control" type="date" name="items[{{ $index }}][fields][fecha_ingreso]" value="{{ $fields['fecha_ingreso'] ?? '' }}">
                                    </td>
                                    <td>
                                        <input class="ficha-input ficha-compact-control" name="items[{{ $index }}][fields][puesto]" value="{{ $fields['puesto'] ?? '' }}" placeholder="Puesto">
                                        <select class="ficha-select ficha-compact-control" name="items[{{ $index }}][fields][contrato]">
                                            @foreach(($fieldDefinitions['contrato']['options'] ?? []) as $value => $label)
                                                <option value="{{ $value }}" @selected(($fields['contrato'] ?? 'REG') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ficha-actions-bar">
            <button type="submit" form="cancelImportForm" class="btn btn-outline">Cancelar</button>
            <button type="submit" class="btn btn-primary">Generar links temporales</button>
        </div>
    </form>
</div>
@endsection
