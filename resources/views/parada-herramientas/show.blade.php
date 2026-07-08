@extends('layouts.app')

@section('title', 'Herramientas y consumibles')

@php
    $puedeEditar = (bool) ($item['puede_editar'] ?? false);
    $puedeActualizarPedido = (bool) ($item['puede_actualizar_pedido'] ?? false);
    $requiereComentarioCambio = (bool) ($item['requiere_comentario_cambio_previo'] ?? false);
    $dias = (int) ($item['dias_para_limite'] ?? 0);
    $limiteEnvioVencido = (bool) ($item['limite_envio_vencido'] ?? ($dias < 0));
    $deadlineClass = $dias < 0 ? 'expired' : ($dias <= 2 ? 'urgent' : 'ok');
    $formAction = $puedeEditar
        ? route('herramientas-parada.save', $item['rq_mina_id'])
        : ($puedeActualizarPedido ? route('herramientas-parada.pedido', $item['rq_mina_id']) : route('herramientas-parada.show', $item['rq_mina_id']));
    $toolBuckets = ['base', 'adicional', 'consumibles_base', 'consumibles_adicional'];
    $importRoutes = [];
    $pedidoTotal = 0;
    $pedidoCompleto = 0;
    foreach (($item['grupos'] ?? []) as $grupo) {
        $importRoutes[$grupo['id']] = route('herramientas-parada.importar-formato', [$item['rq_mina_id'], $grupo['id']]);
        foreach ($toolBuckets as $tipo) {
            foreach (($grupo[$tipo] ?? []) as $row) {
                if ((int) ($row['cantidad_solicitada'] ?? 0) <= 0) {
                    continue;
                }
                $pedidoTotal++;
                if (!empty($row['pedido_solicitado_at']) && !empty($row['pedido_llego_at'])) {
                    $pedidoCompleto++;
                }
            }
        }
    }
@endphp

@section('content')
<div class="tools-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">Herramientas y consumibles</h1>
            <p class="page-subtitle">{{ $item['lugar'] ?? '-' }} | Semana {{ $item['semana'] ?? '-' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('herramientas-parada.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="tools-summary">
        <div class="summary-item">
            <span>Parada</span>
            <strong>{{ $item['area'] ?? '-' }}</strong>
        </div>
        <div class="summary-item">
            <span>Semana ISO</span>
            <strong>{{ $item['semana'] ?? '-' }} / {{ $item['anio_semana'] ?? '-' }}</strong>
        </div>
        <div class="summary-item">
            <span>Fechas</span>
            <strong>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</strong>
        </div>
        <div class="summary-item deadline {{ $deadlineClass }}">
            <span>Limite envio</span>
            <strong>{{ $item['fecha_limite_envio'] ?? '-' }}</strong>
            <small>
                @if($dias < 0)
                    Vencido hace {{ abs($dias) }} dia(s)
                @elseif($dias === 0)
                    Vence hoy
                @else
                    Faltan {{ $dias }} dia(s)
                @endif
            </small>
        </div>
        <div class="summary-item">
            <span>Estado</span>
            <strong>{{ ucfirst(strtolower($item['estado_lista'] ?? 'borrador')) }}</strong>
        </div>
        <div class="summary-item">
            <span>Pedido completado</span>
            <strong>{{ $pedidoCompleto }} / {{ $pedidoTotal }}</strong>
        </div>
        <div class="summary-item">
            <span>Supervisor responsable</span>
            <strong>{{ $item['supervisor_responsable']['nombre'] ?? '-' }}</strong>
            <small>{{ $item['supervisor_responsable']['correo'] ?? 'Sin correo' }}</small>
        </div>
    </div>

    @unless($puedeEditar)
        <div class="alert alert-error">
            @if($limiteEnvioVencido)
                El limite de envio vencio. El requerimiento quedo cerrado y no se puede modificar.
            @else
                Esta lista no esta editable porque ya fue enviada.
            @endif
        </div>
    @endunless

    <form method="POST" action="{{ $formAction }}" id="toolsForm">
        @csrf

        <div class="tools-card tools-edit-card">
            <div class="tools-card-header">
                <div>
                    <h2>Grupos y herramientas</h2>
                    <span>Equipos, herramientas y utillaje solicitados</span>
                </div>
                @if($puedeEditar)
                    <button type="button" class="btn-filter-outline" onclick="addToolGroup()">Agregar grupo</button>
                @endif
            </div>

            <div class="tools-groups" id="toolsGroups">
                @foreach(($item['grupos'] ?? []) as $groupIndex => $group)
                    <div class="tool-group" data-group-index="{{ $groupIndex }}" data-active-category="herramientas">
                        <div class="tool-group-head">
                            <div class="group-name-fields">
                                <input type="hidden" name="grupos[{{ $groupIndex }}][grupo_trabajo_id]" value="{{ $group['grupo_trabajo_id'] ?? '' }}">
                                <div class="form-group">
                                    <label class="form-label">Grupo</label>
                                    <input type="text" name="grupos[{{ $groupIndex }}][nombre]" class="form-control" value="{{ $group['nombre'] ?? '' }}" @readonly(!$puedeEditar)>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Observaciones grupo</label>
                                    <input type="text" name="grupos[{{ $groupIndex }}][observaciones]" class="form-control" value="{{ $group['observaciones'] ?? '' }}" @readonly(!$puedeEditar)>
                                </div>
                            </div>
                            <div class="tool-group-actions">
                                <details class="tools-action-menu">
                                    <summary class="btn-row btn-row-outline">Acciones</summary>
                                    <div class="tools-action-menu-list">
                                        <button type="button" onclick="showToolCategory(this, 'herramientas')">Herramientas</button>
                                        <button type="button" onclick="showToolCategory(this, 'consumibles')">Consumibles</button>
                                        @if($puedeEditar)
                                            <button type="button" onclick="openToolImport(this, '{{ $group['id'] }}')">Subir/actualizar formato</button>
                                        @endif
                                        <button
                                            type="submit"
                                            form="toolReminderForm-{{ $group['id'] }}"
                                            onclick="return confirm('Enviar correo al supervisor responsable para este grupo?');"
                                        >
                                            Correo supervisor
                                        </button>
                                    </div>
                                </details>
                                @if($puedeEditar)
                                    <button type="button" class="btn-row btn-danger" onclick="this.closest('.tool-group').remove()">Quitar grupo</button>
                                @endif
                            </div>
                        </div>

                        <div class="tool-category-tabs" role="tablist" aria-label="Categoria del requerimiento">
                            <button type="button" class="active" onclick="showToolCategory(this, 'herramientas')">Herramientas</button>
                            <button type="button" onclick="showToolCategory(this, 'consumibles')">Consumibles</button>
                        </div>

                        <div class="tool-category-section" data-category-section="herramientas">
                            <div class="tool-list-block">
                                <div class="tool-list-title">
                                    <h3>Equipos / herramientas / utillaje</h3>
                                    @if($puedeEditar)
                                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'base', 'herramienta')">Agregar fila</button>
                                    @endif
                                </div>
                                <div class="tool-list" data-list-type="base">
                                    @php $baseRows = !empty($group['base'] ?? []) ? $group['base'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'observaciones' => '']]; @endphp
                                    @foreach($baseRows as $rowIndex => $row)
                                        @include('parada-herramientas.partials.tool-row', [
                                            'groupIndex' => $groupIndex,
                                            'type' => 'base',
                                            'rowIndex' => $rowIndex,
                                            'row' => $row,
                                            'puedeEditar' => $puedeEditar,
                                            'puedeActualizarPedido' => $puedeActualizarPedido,
                                            'showUnidad' => false,
                                        ])
                                    @endforeach
                                </div>
                            </div>

                            <div class="tool-list-block additional">
                                <div class="tool-list-title">
                                    <h3>Herramientas adicionales</h3>
                                    @if($puedeEditar)
                                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'adicional', 'herramienta')">Agregar fila</button>
                                    @endif
                                </div>
                                <div class="tool-list" data-list-type="adicional">
                                    @php $additionalRows = !empty($group['adicional'] ?? []) ? $group['adicional'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'observaciones' => '']]; @endphp
                                    @foreach($additionalRows as $rowIndex => $row)
                                        @include('parada-herramientas.partials.tool-row', [
                                            'groupIndex' => $groupIndex,
                                            'type' => 'adicional',
                                            'rowIndex' => $rowIndex,
                                            'row' => $row,
                                            'puedeEditar' => $puedeEditar,
                                            'puedeActualizarPedido' => $puedeActualizarPedido,
                                            'showUnidad' => false,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="tool-category-section" data-category-section="consumibles" hidden>
                            <div class="tool-list-block">
                                <div class="tool-list-title">
                                    <h3>Consumibles</h3>
                                    @if($puedeEditar)
                                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'consumibles_base', 'consumible')">Agregar fila</button>
                                    @endif
                                </div>
                                <div class="tool-list" data-list-type="consumibles_base">
                                    @php $consumableRows = !empty($group['consumibles_base'] ?? []) ? $group['consumibles_base'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'unidad' => '', 'observaciones' => '']]; @endphp
                                    @foreach($consumableRows as $rowIndex => $row)
                                        @include('parada-herramientas.partials.tool-row', [
                                            'groupIndex' => $groupIndex,
                                            'type' => 'consumibles_base',
                                            'rowIndex' => $rowIndex,
                                            'row' => $row,
                                            'puedeEditar' => $puedeEditar,
                                            'puedeActualizarPedido' => $puedeActualizarPedido,
                                            'showUnidad' => true,
                                        ])
                                    @endforeach
                                </div>
                            </div>

                            <div class="tool-list-block additional">
                                <div class="tool-list-title">
                                    <h3>Consumibles adicionales</h3>
                                    @if($puedeEditar)
                                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'consumibles_adicional', 'consumible')">Agregar fila</button>
                                    @endif
                                </div>
                                <div class="tool-list" data-list-type="consumibles_adicional">
                                    @php $additionalConsumables = !empty($group['consumibles_adicional'] ?? []) ? $group['consumibles_adicional'] : [['descripcion' => '', 'cantidad_solicitada' => 1, 'unidad' => '', 'observaciones' => '']]; @endphp
                                    @foreach($additionalConsumables as $rowIndex => $row)
                                        @include('parada-herramientas.partials.tool-row', [
                                            'groupIndex' => $groupIndex,
                                            'type' => 'consumibles_adicional',
                                            'rowIndex' => $rowIndex,
                                            'row' => $row,
                                            'puedeEditar' => $puedeEditar,
                                            'puedeActualizarPedido' => $puedeActualizarPedido,
                                            'showUnidad' => true,
                                        ])
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="tools-card notes-card">
            @if($puedeEditar && $requiereComentarioCambio)
                <div class="phase-note is-warning">
                    <strong>Cambio dentro de la semana previa a la parada.</strong>
                    <span>Registra el motivo del cambio para que RR.HH., logistica y operaciones tengan trazabilidad.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo del cambio</label>
                    <textarea name="comentario_cambio_previo" class="form-control" rows="3" required>{{ old('comentario_cambio_previo') }}</textarea>
                </div>
            @endif
            <div class="form-group">
                <label class="form-label">Observaciones generales</label>
                <textarea name="observaciones" class="form-control" rows="3" @readonly(!$puedeEditar)>{{ old('observaciones', $item['observaciones'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="form-actions tools-actions">
            @if($puedeEditar)
                <button type="submit" class="btn btn-primary">Guardar borrador</button>
            @elseif($puedeActualizarPedido)
                <button type="submit" class="btn btn-primary">Actualizar pedido</button>
            @endif
        </div>
    </form>

    @if($puedeEditar)
        <dialog class="tools-import-dialog" id="toolsImportDialog">
            <form method="POST" action="#" enctype="multipart/form-data" id="toolsImportForm">
                @csrf
                <div class="tools-dialog-head">
                    <div>
                        <span>Actualizar formato</span>
                        <h2>Herramientas y consumibles</h2>
                        <p id="toolsImportGroupName">Selecciona el archivo Excel del grupo.</p>
                    </div>
                    <button type="button" class="tools-dialog-close" onclick="closeToolImport()" aria-label="Cerrar">X</button>
                </div>
                <div class="tools-dialog-body">
                    <label class="form-label" for="toolsImportFile">Archivo Excel</label>
                    <input id="toolsImportFile" type="file" name="archivo" class="form-control" accept=".xlsx,.xls,.xlsm" required>
                    <p class="tools-import-help">La primera hoja actualiza herramientas y la segunda hoja actualiza consumibles del grupo seleccionado. Las filas quitadas del formato se retiran de la tabla base del grupo.</p>
                </div>
                <div class="tools-dialog-actions">
                    <button type="button" class="btn-filter-outline" onclick="closeToolImport()">Cancelar</button>
                    <button type="submit" class="btn-filter">Subir y actualizar</button>
                </div>
            </form>
        </dialog>
    @endif

    @if($puedeEditar)
        <form method="POST" action="{{ route('herramientas-parada.enviar', $item['rq_mina_id']) }}" class="send-form" onsubmit="return confirm('Enviar esta lista de herramientas?');">
            @csrf
            <button type="submit" class="btn btn-primary">Enviar lista</button>
        </form>
    @endif

    @foreach(($item['grupos'] ?? []) as $group)
        <form id="toolReminderForm-{{ $group['id'] }}" method="POST" action="{{ route('herramientas-parada.recordar-supervisor', [$item['rq_mina_id'], $group['id']]) }}" style="display:none;">
            @csrf
        </form>
    @endforeach

    <datalist id="toolDescriptionSuggestions"></datalist>
    <datalist id="toolObservationSuggestions"></datalist>
</div>

<script>
let toolGroupIndex = {{ count($item['grupos'] ?? []) }};
const pedidoReadonlyAttr = @json($puedeActualizarPedido ? '' : 'readonly');
const toolImportRoutes = @json($importRoutes);
const canEditTools = @json($puedeEditar);
const toolCatalogSuggestionsUrl = @json(route('herramientas-parada.catalogo.sugerencias'));
const toolObservationSuggestionsUrl = @json(route('herramientas-parada.catalogo.observaciones'));

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
}

function showToolCategory(button, category) {
    const group = button.closest('.tool-group');
    if (!group) {
        return;
    }

    group.dataset.activeCategory = category;
    group.querySelectorAll('[data-category-section]').forEach(section => {
        section.hidden = section.dataset.categorySection !== category;
    });
    group.querySelectorAll('.tool-category-tabs button').forEach(tab => {
        tab.classList.toggle('active', tab.textContent.trim().toLowerCase().startsWith(category));
    });
    button.closest('details')?.removeAttribute('open');
}

function toolRowTemplate(groupIndex, type, rowIndex, row = {}, category = 'herramienta') {
    const prefix = 'grupos[' + groupIndex + '][' + type + '][' + rowIndex + ']';
    const hasUnit = category === 'consumible';
    const toolCategory = hasUnit ? 'CONSUMIBLE' : 'HERRAMIENTA';
    const unitInput = hasUnit
        ? `<input type="text" name="${prefix}[unidad]" class="form-control unit" placeholder="Unidad" value="${escapeHtml(row.unidad || '')}">`
        : '';
    return `
        <div class="tool-row ${hasUnit ? 'has-unit' : ''}">
            <input type="hidden" name="${prefix}[id]" value="${escapeHtml(row.id || '')}">
            <div class="tool-autocomplete-wrap">
                <input type="text" name="${prefix}[descripcion]" class="form-control js-tool-description" data-tool-category="${toolCategory}" placeholder="Descripcion" autocomplete="off" value="${escapeHtml(row.descripcion || '')}">
                <div class="tool-autocomplete-menu" hidden></div>
            </div>
            <input type="number" name="${prefix}[cantidad_solicitada]" class="form-control qty" min="0" value="${escapeHtml(row.cantidad_solicitada ?? 1)}">
            ${unitInput}
            <input type="text" name="${prefix}[observaciones]" class="form-control js-tool-observation" list="toolObservationSuggestions" data-tool-category="${toolCategory}" placeholder="Observaciones" value="${escapeHtml(row.observaciones || '')}">
            <input type="date" name="${prefix}[pedido_solicitado_at]" class="form-control" value="${escapeHtml(row.pedido_solicitado_at || '')}" ${pedidoReadonlyAttr}>
            <input type="date" name="${prefix}[pedido_llego_at]" class="form-control" value="${escapeHtml(row.pedido_llego_at || '')}" ${pedidoReadonlyAttr}>
            <input type="hidden" name="${prefix}[cantidad_entregada]" value="${escapeHtml(row.cantidad_entregada || 0)}">
            <input type="hidden" name="${prefix}[cantidad_recibida]" value="${escapeHtml(row.cantidad_recibida || 0)}">
            <input type="hidden" name="${prefix}[incidencia_durante_parada]" value="${escapeHtml(row.incidencia_durante_parada || '')}">
            <input type="hidden" name="${prefix}[recepcion_estado]" value="${escapeHtml(row.recepcion_estado || 'PENDIENTE')}">
            <input type="hidden" name="${prefix}[recepcion_fecha]" value="${escapeHtml(row.recepcion_fecha || '')}">
            <input type="hidden" name="${prefix}[recepcion_observacion]" value="${escapeHtml(row.recepcion_observacion || '')}">
            <input type="hidden" name="${prefix}[recepcion_registrada_at]" value="${escapeHtml(row.recepcion_registrada_at || '')}">
            <input type="hidden" name="${prefix}[recepcion_registrada_por_usuario_id]" value="${escapeHtml(row.recepcion_registrada_por_usuario_id || '')}">
            <input type="hidden" name="${prefix}[comentario_cambio_previo]" value="${escapeHtml(row.comentario_cambio_previo || '')}">
            <span class="tools-status pending">Pedido pendiente</span>
            <button type="button" class="btn-remove-tool" onclick="this.closest('.tool-row').remove()" aria-label="Quitar fila" title="Quitar fila">X</button>
        </div>
    `;
}

function addToolRow(button, type, category = 'herramienta') {
    const group = button.closest('.tool-group');
    const groupIndex = group.dataset.groupIndex;
    const list = group.querySelector('[data-list-type="' + type + '"]');
    const rowIndex = list.querySelectorAll('.tool-row').length;
    list.insertAdjacentHTML('beforeend', toolRowTemplate(groupIndex, type, rowIndex, {}, category));
}

const toolSuggestionTimers = new WeakMap();
const toolDescriptionCache = new Map();

function debounceToolSuggestion(input, callback) {
    window.clearTimeout(toolSuggestionTimers.get(input));
    toolSuggestionTimers.set(input, window.setTimeout(callback, 180));
}

function toolCategoryForInput(input) {
    return input?.dataset?.toolCategory || (input?.closest('.tool-row')?.classList.contains('has-unit') ? 'CONSUMIBLE' : 'HERRAMIENTA');
}

function fillToolDatalist(id, items, key) {
    const list = document.getElementById(id);
    if (!list) {
        return;
    }

    list.innerHTML = '';
    items.forEach(item => {
        const value = item?.[key] || '';
        if (!value) {
            return;
        }

        const option = document.createElement('option');
        option.value = value;
        list.appendChild(option);
    });
}

function closeToolDescriptionMenus(except = null) {
    document.querySelectorAll('.tool-autocomplete-menu').forEach(menu => {
        if (menu !== except) {
            menu.hidden = true;
            menu.innerHTML = '';
        }
    });
}

function toolDescriptionMenu(input) {
    return input.closest('.tool-autocomplete-wrap')?.querySelector('.tool-autocomplete-menu') || null;
}

function selectToolDescription(input, item) {
    input.value = item.descripcion || '';

    const row = input.closest('.tool-row');
    const unitInput = row?.querySelector('.unit');
    if (unitInput instanceof HTMLInputElement && item.unidad) {
        unitInput.value = item.unidad;
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
    closeToolDescriptionMenus();
}

function renderToolDescriptionMenu(input, items) {
    const menu = toolDescriptionMenu(input);
    if (!menu) {
        return;
    }

    closeToolDescriptionMenus(menu);
    menu.innerHTML = '';

    if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'tool-autocomplete-empty';
        empty.textContent = 'Sin opciones encontradas';
        menu.appendChild(empty);
        menu.hidden = false;
        return;
    }

    items.forEach(item => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'tool-autocomplete-option';
        button.innerHTML = `
            <span>${escapeHtml(item.descripcion || '')}</span>
            ${item.unidad ? `<small>${escapeHtml(item.unidad)}</small>` : ''}
        `;
        button.addEventListener('mousedown', event => {
            event.preventDefault();
            selectToolDescription(input, item);
        });
        menu.appendChild(button);
    });

    menu.hidden = false;
}

async function fetchToolDescriptions(input, openMenu = false) {
    if (input.readOnly || input.disabled) {
        return;
    }

    const term = (input.value || '').trim();
    const category = toolCategoryForInput(input);
    const cacheKey = category + '|' + term.toUpperCase();

    if (toolDescriptionCache.has(cacheKey)) {
        const cached = toolDescriptionCache.get(cacheKey) || [];
        fillToolDatalist('toolDescriptionSuggestions', cached, 'descripcion');
        if (openMenu) {
            renderToolDescriptionMenu(input, cached);
        }
        return;
    }

    const url = new URL(toolCatalogSuggestionsUrl, window.location.origin);
    url.searchParams.set('q', term);
    url.searchParams.set('categoria', category);
    url.searchParams.set('limit', term.length < 2 ? '50' : '30');

    try {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) {
            return;
        }

        const data = await response.json();
        const items = data.items || [];
        toolDescriptionCache.set(cacheKey, items);
        fillToolDatalist('toolDescriptionSuggestions', items, 'descripcion');
        if (openMenu) {
            renderToolDescriptionMenu(input, items);
        }
    } catch (error) {
        // Las sugerencias no deben bloquear el llenado manual de la lista.
    }
}

async function fetchToolObservations(input) {
    const row = input.closest('.tool-row');
    const descriptionInput = row?.querySelector('.js-tool-description');
    const description = (descriptionInput?.value || '').trim();
    if (description.length < 2) {
        return;
    }

    const url = new URL(toolObservationSuggestionsUrl, window.location.origin);
    url.searchParams.set('descripcion', description);
    url.searchParams.set('categoria', toolCategoryForInput(input));
    url.searchParams.set('limit', '10');

    try {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) {
            return;
        }

        const data = await response.json();
        fillToolDatalist('toolObservationSuggestions', data.items || [], 'observacion');
    } catch (error) {
        // Las sugerencias no deben bloquear el llenado manual de la lista.
    }
}

function openToolImport(button, groupId) {
    const dialog = document.getElementById('toolsImportDialog');
    const form = document.getElementById('toolsImportForm');
    const file = document.getElementById('toolsImportFile');
    const label = document.getElementById('toolsImportGroupName');
    const route = toolImportRoutes[groupId];

    if (!dialog || !form || !route) {
        return;
    }

    const group = button.closest('.tool-group');
    const name = group?.querySelector('input[name$="[nombre]"]')?.value || 'Grupo';
    form.action = route;
    if (file) {
        file.value = '';
    }
    if (label) {
        label.textContent = 'Grupo: ' + name;
    }
    dialog.showModal();
}

function closeToolImport() {
    const dialog = document.getElementById('toolsImportDialog');
    if (dialog?.open) {
        dialog.close();
    }
}

function addToolGroup() {
    if (!canEditTools) {
        return;
    }

    const groupIndex = toolGroupIndex++;
    const html = `
        <div class="tool-group" data-group-index="${groupIndex}" data-active-category="herramientas">
            <div class="tool-group-head">
                <div class="group-name-fields">
                    <input type="hidden" name="grupos[${groupIndex}][grupo_trabajo_id]" value="">
                    <div class="form-group">
                        <label class="form-label">Grupo</label>
                        <input type="text" name="grupos[${groupIndex}][nombre]" class="form-control" value="Grupo ${groupIndex + 1}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Observaciones grupo</label>
                        <input type="text" name="grupos[${groupIndex}][observaciones]" class="form-control">
                    </div>
                </div>
                <div class="tool-group-actions">
                    <details class="tools-action-menu">
                        <summary class="btn-row btn-row-outline">Acciones</summary>
                        <div class="tools-action-menu-list">
                            <button type="button" onclick="showToolCategory(this, 'herramientas')">Herramientas</button>
                            <button type="button" onclick="showToolCategory(this, 'consumibles')">Consumibles</button>
                        </div>
                    </details>
                    <button type="button" class="btn-row btn-danger" onclick="this.closest('.tool-group').remove()">Quitar grupo</button>
                </div>
            </div>
            <div class="tool-category-tabs" role="tablist" aria-label="Categoria del requerimiento">
                <button type="button" class="active" onclick="showToolCategory(this, 'herramientas')">Herramientas</button>
                <button type="button" onclick="showToolCategory(this, 'consumibles')">Consumibles</button>
            </div>
            <div class="tool-category-section" data-category-section="herramientas">
                <div class="tool-list-block">
                    <div class="tool-list-title">
                        <h3>Equipos / herramientas / utillaje</h3>
                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'base', 'herramienta')">Agregar fila</button>
                    </div>
                    <div class="tool-list" data-list-type="base">${toolRowTemplate(groupIndex, 'base', 0, {}, 'herramienta')}</div>
                </div>
                <div class="tool-list-block additional">
                    <div class="tool-list-title">
                        <h3>Herramientas adicionales</h3>
                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'adicional', 'herramienta')">Agregar fila</button>
                    </div>
                    <div class="tool-list" data-list-type="adicional">${toolRowTemplate(groupIndex, 'adicional', 0, {}, 'herramienta')}</div>
                </div>
            </div>
            <div class="tool-category-section" data-category-section="consumibles" hidden>
                <div class="tool-list-block">
                    <div class="tool-list-title">
                        <h3>Consumibles</h3>
                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'consumibles_base', 'consumible')">Agregar fila</button>
                    </div>
                    <div class="tool-list" data-list-type="consumibles_base">${toolRowTemplate(groupIndex, 'consumibles_base', 0, {}, 'consumible')}</div>
                </div>
                <div class="tool-list-block additional">
                    <div class="tool-list-title">
                        <h3>Consumibles adicionales</h3>
                        <button type="button" class="btn-row btn-row-outline" onclick="addToolRow(this, 'consumibles_adicional', 'consumible')">Agregar fila</button>
                    </div>
                    <div class="tool-list" data-list-type="consumibles_adicional">${toolRowTemplate(groupIndex, 'consumibles_adicional', 0, {}, 'consumible')}</div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('toolsGroups').insertAdjacentHTML('beforeend', html);
}

document.getElementById('toolsImportDialog')?.addEventListener('click', function (event) {
    if (event.target === this) {
        closeToolImport();
    }
});

document.addEventListener('input', function (event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    if (input.matches('.js-tool-description')) {
        debounceToolSuggestion(input, function () {
            fetchToolDescriptions(input, document.activeElement === input);
            const row = input.closest('.tool-row');
            const observationInput = row?.querySelector('.js-tool-observation');
            if (observationInput instanceof HTMLInputElement) {
                fetchToolObservations(observationInput);
            }
        });
    }
});

document.addEventListener('focusin', function (event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    if (input.matches('.js-tool-description')) {
        fetchToolDescriptions(input, true);
    }

    if (input.matches('.js-tool-observation')) {
        fetchToolObservations(input);
    }
});

document.addEventListener('change', function (event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('.js-tool-description')) {
        return;
    }

    const row = input.closest('.tool-row');
    const observationInput = row?.querySelector('.js-tool-observation');
    if (observationInput instanceof HTMLInputElement) {
        fetchToolObservations(observationInput);
    }
});

document.addEventListener('click', function (event) {
    const target = event.target;

    if (target instanceof HTMLInputElement && target.matches('.js-tool-description')) {
        fetchToolDescriptions(target, true);
        return;
    }

    if (!(target instanceof Element) || !target.closest('.tool-autocomplete-wrap')) {
        closeToolDescriptionMenus();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeToolDescriptionMenus();
    }
});
</script>
@endsection
