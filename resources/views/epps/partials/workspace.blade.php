@php
    $permissions = session('user.permissions', []);
    $canConfigureEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'configurar');
    $canEditEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'editar');
    $canRegisterEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'registrar');
    $canUpdateEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'actualizar');
    $canDeleteEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'eliminar');
    $canManageEpps = $canConfigureEpps || $canEditEpps || $canRegisterEpps;
    $embedded = (bool) ($embedded ?? false);
    $eppFilterAction = $embedded ? url('/logistica') : route('epps.index');
    $eppResetUrl = $embedded ? url('/logistica?tab=entregas') : route('epps.index');
    $workerFilterChip = $workerFilterChip ?? null;
    $workerChipRemoveQuery = request()->except(['trabajador', 'q', 'page']);
    if ($embedded) {
        $workerChipRemoveQuery['tab'] = 'entregas';
    }
    $workerChipRemoveBase = $embedded ? url('/logistica') : route('epps.index');
    $workerChipRemoveUrl = $workerChipRemoveBase . (count($workerChipRemoveQuery) > 0 ? '?' . http_build_query($workerChipRemoveQuery) : '');
    $eppInitialAttributes = [
        'talla' => old('talla'),
        'color' => old('color'),
        'atributos' => old('atributos', []),
    ];
@endphp

<div
    class="epp-screen {{ $embedded ? 'epp-screen-embedded' : '' }}"
    data-personal-search-url="{{ route('epps.personal.buscar') }}"
    data-last-delivery-url="{{ route('epps.entregas.ultima') }}"
    @if(old('_epp_open_modal')) data-open-modal="{{ old('_epp_open_modal') }}"
    @elseif($errors->any()) data-open-modal="{{ old('_epp_edit_modal') ?: (old('personal_id') || old('epp_id') ? 'eppDeliveryModal' : (old('catalog_edit_id') ? 'eppCatalogListModal' : 'eppCatalogModal')) }}" @endif
>
    @unless($embedded)
        <header class="epp-header">
            <div>
                <h1>Logistica EPP</h1>
                <p>Registra entregas, cambios y uso efectivo de EPP por trabajador.</p>
            </div>
        </header>
    @endunless

    @if(session('success'))
        <div class="epp-alert epp-alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="epp-alert epp-alert-error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="epp-alert epp-alert-error">
            {{ $errors->first() }}
        </div>
    @endif

    <section class="epp-panel epp-filter-panel" data-epp-filter-panel>
        <div class="epp-panel-header">
            <div>
                <h2>Seguimiento de EPP</h2>
                <p>Filtra entregas activas, cambios o devoluciones registradas.</p>
            </div>
            <button
                type="button"
                class="epp-filter-toggle"
                data-epp-filter-toggle
                aria-expanded="true"
                aria-controls="eppFilterBody"
                aria-label="Subir o bajar filtros"
                title="Subir o bajar filtros">
                <span aria-hidden="true"></span>
            </button>
        </div>
        <div id="eppFilterBody" data-epp-filter-body>
            <form method="GET" action="{{ $eppFilterAction }}" class="epp-filter-grid">
                @if($embedded)
                    <input type="hidden" name="tab" value="entregas">
                @endif
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] ?? 10 }}">
                <label>
                    Buscar
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Trabajador, DNI, puesto o EPP">
                </label>
                <label>
                    Estado
                    <select name="estado">
                        <option value="">Todos</option>
                        @foreach($estadosEntrega as $estadoEntrega)
                            <option value="{{ $estadoEntrega }}" @selected(($filters['estado'] ?? '') === $estadoEntrega)>{{ ucwords(strtolower($estadoEntrega)) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Mina
                    <select name="mina_id">
                        <option value="">Todas las minas</option>
                        @foreach($minas ?? [] as $mina)
                            <option value="{{ $mina->id }}" @selected(($filters['mina_id'] ?? '') === $mina->id)>{{ $mina->nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    EPP / item
                    <select name="epp_id">
                        <option value="">Todos los EPP</option>
                        @foreach($catalogo ?? [] as $epp)
                            <option value="{{ $epp->id }}" @selected(($filters['epp_id'] ?? '') === $epp->id)>{{ $epp->nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Tipo de movimiento
                    <select name="tipo_movimiento">
                        <option value="">Todos</option>
                        @foreach($tiposMovimiento ?? [] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['tipo_movimiento'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Fecha desde
                    <input type="date" name="fecha_desde" value="{{ $filters['fecha_desde'] ?? '' }}">
                </label>
                <label>
                    Fecha hasta
                    <input type="date" name="fecha_hasta" value="{{ $filters['fecha_hasta'] ?? '' }}">
                </label>
                <div class="epp-filter-actions">
                    <button type="submit" class="epp-btn epp-btn-primary">Filtrar</button>
                    <a href="{{ $eppResetUrl }}" class="epp-btn epp-btn-light">Limpiar</a>
                </div>
            </form>
            @if($workerFilterChip)
                <div class="epp-active-filters" aria-label="Filtros activos">
                    <span class="epp-filter-chip">
                        <span>Trabajador: <strong>{{ $workerFilterChip['label'] }}</strong></span>
                        <a href="{{ $workerChipRemoveUrl }}" aria-label="Quitar filtro de trabajador">X</a>
                    </span>
                </div>
            @endif
        </div>
    </section>

    @if(false && $canManageEpps)
    <div class="epp-grid">
        <section class="epp-panel">
            <div class="epp-panel-header">
                <div>
                    <h2>Catalogo de EPP</h2>
                    <p>Agrega el EPP y su vida util para calcular vencimiento calendario y uso efectivo.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('epps.catalogo.store') }}" class="epp-form-grid">
                @csrf
                <label>
                    EPP
                    <input type="text" name="nombre" value="{{ old('nombre') }}" placeholder="Casco, lentes, guantes..." required>
                </label>
                <label>
                    Vida util (dias)
                    <input type="number" name="vida_util_dias" min="1" value="{{ old('vida_util_dias', 30) }}" required>
                </label>
                <label>
                    Estado
                    <select name="estado">
                        <option value="ACTIVO">Activo</option>
                        <option value="INACTIVO">Inactivo</option>
                    </select>
                </label>
                <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar EPP</button>
            </form>

            <div class="epp-catalog-list">
                @forelse($catalogo->take(8) as $epp)
                    <div class="epp-catalog-item">
                        <strong>{{ $epp->nombre }}</strong>
                        <span>{{ $epp->codigo }} · {{ $epp->vida_util_dias }} dias</span>
                    </div>
                @empty
                    <p class="epp-muted">Aun no hay EPP registrados.</p>
                @endforelse
            </div>
        </section>

        <section class="epp-panel">
            <div class="epp-panel-header">
                <div>
                    <h2>Registrar entrega</h2>
                    <p>Anota quien recoge, que recoge y la fecha de entrega.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('epps.entregas.store') }}" class="epp-delivery-form">
                @csrf
                <input type="hidden" name="personal_id" id="eppPersonalId" value="{{ old('personal_id') }}">
                <label class="epp-autocomplete">
                    Trabajador
                    <input type="search" id="eppPersonalSearch" autocomplete="off" placeholder="Buscar por nombre, DNI o puesto" value="{{ old('personal_label') }}">
                    <div class="epp-autocomplete-results" id="eppPersonalResults" hidden></div>
                </label>
                <label>
                    EPP
                    <select name="epp_id" required>
                        <option value="">Seleccionar EPP</option>
                        @foreach($eppsActivos as $epp)
                            <option value="{{ $epp->id }}" @selected(old('epp_id') === $epp->id)>{{ $epp->nombre }} · {{ $epp->vida_util_dias }} dias</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Cantidad
                    <input type="number" name="cantidad" min="1" value="{{ old('cantidad', 1) }}" required>
                </label>
                <label>
                    Fecha de entrega
                    <input type="date" name="fecha_entrega" value="{{ old('fecha_entrega', now()->toDateString()) }}" required>
                </label>
                <label class="epp-field-wide">
                    Observacion
                    <textarea name="observacion" rows="3" placeholder="Detalle opcional de entrega, talla, condicion o cambio">{{ old('observacion') }}</textarea>
                </label>
                <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Registrar entrega</button>
            </form>
        </section>
    </div>
    @endif

    <section class="epp-panel">
        @php
            $entregasIsPaginator = $entregas instanceof \Illuminate\Pagination\LengthAwarePaginator;
            $entregasTotal = $entregasIsPaginator ? $entregas->total() : count($entregas);
        @endphp
        <div class="epp-panel-header">
            <div>
                <h2>Entregas y cambios</h2>
                <p>El uso efectivo se calcula con las paradas donde el trabajador estuvo asignado.</p>
            </div>
            <div class="epp-panel-header-actions">
                <span class="epp-count">{{ $entregasTotal }} registros</span>
                @if($canRegisterEpps)
                    <button type="button" class="epp-btn epp-btn-primary epp-list-action" data-epp-open-modal="eppDeliveryModal">
                        + Registrar entrega
                    </button>
                @endif
            </div>
        </div>

        <div class="epp-table-wrap">
            <table class="epp-table">
                <thead>
                    <tr>
                        <th>Trabajador</th>
                        <th>EPP</th>
                        <th>Entrega</th>
                        <th>Vencimiento</th>
                        <th>Uso efectivo</th>
                        <th>Paradas de uso</th>
                        <th>Estado</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entregas as $item)
                        @php
                            $entrega = $item['model'];
                            $personal = $item['personal'];
                            $epp = $item['epp'];
                            $isActive = $entrega->estado === \App\Models\EppEntrega::ESTADO_ENTREGADO;
                            $editableEpps = collect($eppsActivos ?? []);
                            if ($epp && ! $editableEpps->contains('id', $epp->id)) {
                                $editableEpps = $editableEpps->push($epp);
                            }
                            $editableEpps = $editableEpps->sortBy('nombre')->values();
                            $deliveryAttributes = collect([
                                filled($item['talla'] ?? '') ? 'Talla: '.$item['talla'] : null,
                                filled($item['color'] ?? '') ? 'Color: '.$item['color'] : null,
                            ])
                                ->merge(collect($item['atributos'] ?? [])->map(function ($attribute) {
                                    $name = trim((string) data_get($attribute, 'nombre', ''));
                                    $value = trim((string) data_get($attribute, 'valor', ''));

                                    return $name !== '' && $value !== '' ? $name.': '.$value : null;
                                }))
                                ->filter()
                                ->values();
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $personal?->nombre_completo ?? 'Trabajador no encontrado' }}</strong>
                                <span>{{ $item['documento'] }} · {{ $personal?->puesto ?: 'Sin puesto' }}</span>
                            </td>
                            <td>
                                <strong>{{ $epp?->nombre ?? 'EPP no encontrado' }}</strong>
                                @if($deliveryAttributes->isNotEmpty())
                                    <div class="epp-delivery-attribute-list">
                                        @foreach($deliveryAttributes as $attribute)
                                            <small>- {{ $attribute }}</small>
                                        @endforeach
                                    </div>
                                @endif
                                <span>{{ $epp?->codigo ?? '-' }} · {{ $item['vida_dias'] }} dias de vida</span>
                            </td>
                            <td>
                                <strong>{{ optional($entrega->fecha_entrega)->format('d/m/Y') }}</strong>
                                <span>Cantidad: {{ $entrega->cantidad }}</span>
                            </td>
                            <td>
                                <strong>{{ optional($entrega->fecha_vencimiento_calendario)->format('d/m/Y') ?: '-' }}</strong>
                                <span>Por calendario</span>
                            </td>
                            <td>
                                <div class="epp-progress">
                                    <span style="width: {{ $item['uso_porcentaje'] }}%"></span>
                                </div>
                                <strong>{{ $item['dias_uso_efectivo'] }} / {{ $item['vida_dias'] }} dias</strong>
                                <span>{{ $item['dias_restantes_uso'] }} dias disponibles por uso</span>
                            </td>
                            <td>
                                @if(empty($item['periodos_uso']))
                                    <span class="epp-muted">Sin paradas registradas desde la entrega.</span>
                                @else
                                    <details class="epp-periods">
                                        <summary>{{ count($item['periodos_uso']) }} periodo(s)</summary>
                                        @foreach($item['periodos_uso'] as $periodo)
                                            <div>
                                                <strong>{{ $periodo['parada'] }}</strong>
                                                <span>{{ \Carbon\Carbon::parse($periodo['desde'])->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($periodo['hasta'])->format('d/m/Y') }} · {{ $periodo['dias'] }} dias</span>
                                            </div>
                                        @endforeach
                                    </details>
                                @endif
                            </td>
                            <td>
                                <span class="epp-status epp-status-{{ strtolower($entrega->estado) }}">{{ ucwords(strtolower($entrega->estado)) }}</span>
                                @if($item['fecha_cierre'])
                                    <small class="epp-return-date">
                                        {{ $item['movimiento_cierre'] }}: {{ $item['fecha_cierre'] }}
                                        @if(!empty($item['cerrado_por']) && $item['cerrado_por'] !== 'No registrado')
                                            <span>Cerrado por {{ $item['cerrado_por'] }}</span>
                                        @endif
                                    </small>
                                @endif
                                @if($entrega->motivo_cambio)
                                    <small>{{ $entrega->motivo_cambio }}</small>
                                @endif
                            </td>
                            <td class="epp-actions-cell">
                                <div class="epp-row-actions">
                                    @if($canRegisterEpps && $isActive)
                                        <button type="button" class="epp-btn epp-btn-sm epp-btn-outline" data-epp-open-modal="eppCloseModal{{ $entrega->id }}" title="Registrar devolucion o cambio">
                                            Cerrar
                                        </button>

                                        <div id="eppCloseModal{{ $entrega->id }}" class="epp-modal" hidden>
                                            <div class="epp-modal-card epp-modal-card-compact" role="dialog" aria-modal="true" aria-labelledby="eppCloseTitle{{ $entrega->id }}">
                                                <div class="epp-modal-header">
                                                    <div>
                                                        <h2 id="eppCloseTitle{{ $entrega->id }}">Registrar movimiento</h2>
                                                        <p>Indica si el EPP fue devuelto o cambiado.</p>
                                                    </div>
                                                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                </div>
                                                <div class="epp-modal-body">
                                                    <div class="epp-movement-summary">
                                                        <span>
                                                            <strong>Trabajador</strong>
                                                            {{ $personal?->nombre_completo ?? 'Trabajador no encontrado' }}
                                                        </span>
                                                        <span>
                                                            <strong>EPP</strong>
                                                            {{ $epp?->nombre ?? 'EPP no encontrado' }}
                                                        </span>
                                                        <span>
                                                            <strong>Entrega</strong>
                                                            {{ optional($entrega->fecha_entrega)->format('d/m/Y') ?: '-' }}
                                                        </span>
                                                    </div>
                                                    <form method="POST" action="{{ route('epps.entregas.close', $entrega->id) }}" class="epp-close-form epp-close-form-modal">
                                                        @csrf
                                                        <div class="epp-close-grid">
                                                            <label class="epp-close-field">
                                                                <span>Movimiento</span>
                                                                <select name="estado" required>
                                                                    <option value="DEVUELTO">Devuelto por internamiento</option>
                                                                    <option value="CAMBIADO">Cambio de EPP</option>
                                                                    <option value="USO_INCORRECTO">Uso incorrecto</option>
                                                                    <option value="PERDIDA_OLVIDO">Perdida / olvido</option>
                                                                </select>
                                                            </label>
                                                            <label class="epp-close-field">
                                                                <span>Fecha</span>
                                                                <input type="date" name="devuelto_at" value="{{ now()->toDateString() }}" required>
                                                            </label>
                                                        </div>
                                                        <label class="epp-close-field">
                                                            <span>Motivo</span>
                                                            <input type="text" name="motivo_cambio" placeholder="Motivo de cambio, uso incorrecto, perdida u olvido">
                                                        </label>
                                                        <label class="epp-close-field">
                                                            <span>Observacion</span>
                                                            <textarea name="observacion" rows="3" placeholder="Descripcion opcional"></textarea>
                                                        </label>
                                                        <button type="submit" class="epp-btn epp-btn-primary epp-close-submit">Guardar movimiento</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if($canUpdateEpps)
                                        <button type="button" class="epp-btn epp-btn-sm epp-btn-ghost" data-epp-open-modal="eppEditModal{{ $entrega->id }}" title="Editar datos de la entrega">
                                            Editar
                                        </button>

                                        <div id="eppEditModal{{ $entrega->id }}" class="epp-modal" hidden>
                                            <div class="epp-modal-card epp-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="eppEditTitle{{ $entrega->id }}">
                                                <div class="epp-modal-header">
                                                    <div>
                                                        <h2 id="eppEditTitle{{ $entrega->id }}">Editar entrega</h2>
                                                        <p>Corrige el item, fechas o cantidad cuando el registro fue creado con datos equivocados.</p>
                                                    </div>
                                                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                </div>
                                                <div class="epp-modal-body">
                                                    <div class="epp-movement-summary">
                                                        <span>
                                                            <strong>Trabajador</strong>
                                                            {{ $personal?->nombre_completo ?? 'Trabajador no encontrado' }}
                                                        </span>
                                                        <span>
                                                            <strong>EPP</strong>
                                                            {{ $epp?->nombre ?? 'EPP no encontrado' }}
                                                        </span>
                                                        <span>
                                                            <strong>Estado actual</strong>
                                                            {{ ucwords(strtolower($entrega->estado)) }}
                                                        </span>
                                                        <span>
                                                            <strong>Entrega</strong>
                                                            {{ optional($entrega->fecha_entrega)->format('d/m/Y') ?: '-' }}
                                                        </span>
                                                        <span>
                                                            <strong>Vencimiento</strong>
                                                            {{ optional($entrega->fecha_vencimiento_calendario)->format('d/m/Y') ?: '-' }}
                                                        </span>
                                                        <span>
                                                            <strong>Cantidad</strong>
                                                            {{ $entrega->cantidad }}
                                                        </span>
                                                    </div>
                                                    <div class="epp-edit-notice">
                                                        Para registrar devolucion, cambio, uso incorrecto o perdida, usa el boton <strong>Cerrar</strong>. Esta ventana solo corrige datos de la entrega.
                                                    </div>
                                                    <form method="POST" action="{{ route('epps.entregas.update', $entrega->id) }}" class="epp-edit-form">
                                                        @csrf
                                                        @method('PUT')
                                                        <input type="hidden" name="_epp_edit_modal" value="eppEditModal{{ $entrega->id }}">
                                                        <label class="epp-edit-field epp-field-wide">
                                                            <span>EPP entregado</span>
                                                            <select name="epp_id">
                                                                @foreach($editableEpps as $editableEpp)
                                                                    <option
                                                                        value="{{ $editableEpp->id }}"
                                                                        @selected(old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('epp_id', $entrega->epp_id) === $editableEpp->id : $entrega->epp_id === $editableEpp->id)
                                                                    >
                                                                        {{ $editableEpp->nombre }} - {{ $editableEpp->codigo ?: 'Sin codigo' }} - {{ $editableEpp->vida_util_dias ?: 0 }} dias
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <div class="epp-edit-grid">
                                                            <label class="epp-edit-field">
                                                                <span>Fecha de entrega</span>
                                                                <input type="date" name="fecha_entrega" value="{{ old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('fecha_entrega', optional($entrega->fecha_entrega)->format('Y-m-d')) : optional($entrega->fecha_entrega)->format('Y-m-d') }}">
                                                            </label>
                                                            <label class="epp-edit-field">
                                                                <span>Vencimiento por calendario</span>
                                                                <input type="date" name="fecha_vencimiento_calendario" value="{{ old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('fecha_vencimiento_calendario', optional($entrega->fecha_vencimiento_calendario)->format('Y-m-d')) : optional($entrega->fecha_vencimiento_calendario)->format('Y-m-d') }}">
                                                            </label>
                                                            <label class="epp-edit-field">
                                                                <span>Cantidad</span>
                                                                <input type="number" name="cantidad" min="1" max="1000" value="{{ old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('cantidad', $entrega->cantidad) : $entrega->cantidad }}">
                                                            </label>
                                                            <label class="epp-edit-field">
                                                                <span>Motivo de correccion</span>
                                                                <input type="text" name="motivo_cambio" maxlength="120" value="{{ old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('motivo_cambio', $entrega->motivo_cambio) : $entrega->motivo_cambio }}" placeholder="Ej. item registrado por error">
                                                            </label>
                                                        </div>
                                                        <label class="epp-edit-field epp-field-wide">
                                                            <span>Observacion</span>
                                                            <textarea name="observacion" rows="3" placeholder="Detalle opcional">{{ old('_epp_edit_modal') === 'eppEditModal'.$entrega->id ? old('observacion', $entrega->observacion) : $entrega->observacion }}</textarea>
                                                        </label>
                                                        <div class="epp-edit-actions">
                                                            <button type="button" class="epp-btn epp-btn-light" data-epp-close-modal>Cancelar</button>
                                                            <button type="submit" class="epp-btn epp-btn-primary">Guardar cambios</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if($canDeleteEpps)
                                        <button type="button" class="epp-btn epp-btn-sm epp-btn-danger-ghost" data-epp-open-modal="eppDeleteModal{{ $entrega->id }}" title="Eliminar entrega">
                                            Eliminar
                                        </button>

                                        <div id="eppDeleteModal{{ $entrega->id }}" class="epp-modal" hidden>
                                            <div class="epp-modal-card epp-modal-card-compact" role="dialog" aria-modal="true" aria-labelledby="eppDeleteTitle{{ $entrega->id }}">
                                                <div class="epp-modal-header">
                                                    <div>
                                                        <h2 id="eppDeleteTitle{{ $entrega->id }}" class="epp-delete-title">Eliminar entrega</h2>
                                                        <p>Esta accion no se puede deshacer.</p>
                                                    </div>
                                                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                </div>
                                                <div class="epp-modal-body">
                                                    <div class="epp-delete-warning">
                                                        <span class="epp-delete-icon">⚠️</span>
                                                        <div>
                                                            <strong>¿Eliminar esta entrega?</strong>
                                                            <p>
                                                                {{ $personal?->nombre_completo ?? 'Trabajador no encontrado' }} -
                                                                {{ $epp?->nombre ?? 'EPP no encontrado' }} -
                                                                {{ optional($entrega->fecha_entrega)->format('d/m/Y') ?: 'sin fecha' }}
                                                            </p>
                                                            <p class="epp-delete-hint">Se eliminara permanentemente el registro de entrega. Esta operacion no se puede revertir.</p>
                                                        </div>
                                                    </div>
                                                    <form method="POST" action="{{ route('epps.entregas.destroy', $entrega->id) }}" class="epp-delete-form">
                                                        @csrf
                                                        <div class="epp-delete-actions">
                                                            <button type="button" class="epp-btn epp-btn-light" data-epp-close-modal>Cancelar</button>
                                                            <button type="submit" class="epp-btn epp-btn-danger">Si, eliminar</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if(!$canRegisterEpps && !$canUpdateEpps && !$canDeleteEpps)
                                        <span class="epp-no-action">Sin accion</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="epp-empty">No hay entregas con los filtros seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($entregasIsPaginator)
            @php
                $perPageOptions = $perPageOptions ?? [10, 25, 50, 100];
                $firstItem = $entregas->firstItem() ?? 0;
                $lastItem = $entregas->lastItem() ?? 0;
            @endphp
            <div class="epp-pagination">
                <div class="epp-pagination-meta">
                    <p class="epp-pagination-summary">
                        Mostrando {{ $firstItem }}&ndash;{{ $lastItem }} de {{ $entregas->total() }} registros
                    </p>

                    <form method="GET" action="{{ $eppFilterAction }}" class="epp-page-size-form">
                        @if($embedded)
                            <input type="hidden" name="tab" value="entregas">
                        @endif
                        @if(($filters['q'] ?? '') !== '')
                            <input type="hidden" name="q" value="{{ $filters['q'] }}">
                        @endif
                        @if(request()->filled('trabajador'))
                            <input type="hidden" name="trabajador" value="{{ request()->query('trabajador') }}">
                        @endif
                        @if(($filters['estado'] ?? '') !== '')
                            <input type="hidden" name="estado" value="{{ $filters['estado'] }}">
                        @endif
                        @if(($filters['mina_id'] ?? '') !== '')
                            <input type="hidden" name="mina_id" value="{{ $filters['mina_id'] }}">
                        @endif
                        @if(($filters['epp_id'] ?? '') !== '')
                            <input type="hidden" name="epp_id" value="{{ $filters['epp_id'] }}">
                        @endif
                        @if(($filters['tipo_movimiento'] ?? '') !== '')
                            <input type="hidden" name="tipo_movimiento" value="{{ $filters['tipo_movimiento'] }}">
                        @endif
                        @if(($filters['fecha_desde'] ?? '') !== '')
                            <input type="hidden" name="fecha_desde" value="{{ $filters['fecha_desde'] }}">
                        @endif
                        @if(($filters['fecha_hasta'] ?? '') !== '')
                            <input type="hidden" name="fecha_hasta" value="{{ $filters['fecha_hasta'] }}">
                        @endif
                        <label>
                            <span>Mostrar</span>
                            <select name="per_page" onchange="this.form.submit()">
                                @foreach($perPageOptions as $option)
                                    <option value="{{ $option }}" @selected((int) ($filters['per_page'] ?? 10) === (int) $option)>{{ $option }} registros</option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                </div>
                <nav class="epp-pagination-pages" aria-label="Paginacion de entregas de EPP">
                    @if($entregas->onFirstPage())
                        <span class="epp-page-link is-disabled" aria-disabled="true">Anterior</span>
                    @else
                        <a class="epp-page-link" href="{{ $entregas->previousPageUrl() }}" aria-label="Pagina anterior">Anterior</a>
                    @endif

                    <span class="epp-page-link is-current" aria-current="page">{{ $entregas->currentPage() }}</span>

                    @if($entregas->hasMorePages())
                        <a class="epp-page-link" href="{{ $entregas->nextPageUrl() }}" aria-label="Pagina siguiente">Siguiente</a>
                    @else
                        <span class="epp-page-link is-disabled" aria-disabled="true">Siguiente</span>
                    @endif
                </nav>

                {{--
                <nav class="epp-pagination-pages" aria-label="Paginacion de entregas de EPP">
                    @if($entregas->onFirstPage())
                        <span class="epp-page-link is-disabled" aria-hidden="true">‹</span>
                    @else
                        <a class="epp-page-link" href="{{ $entregas->previousPageUrl() }}" aria-label="Pagina anterior">‹</a>
                    @endif

                    @foreach($paginationPages as $page)
                        @if($previousPaginationPage !== null && $page > $previousPaginationPage + 1)
                            <span class="epp-page-ellipsis">...</span>
                        @endif

                        @if($page === $entregas->currentPage())
                            <span class="epp-page-link is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="epp-page-link" href="{{ $entregas->url($page) }}">{{ $page }}</a>
                        @endif

                        @php $previousPaginationPage = $page; @endphp
                    @endforeach

                    @if($entregas->hasMorePages())
                        <a class="epp-page-link" href="{{ $entregas->nextPageUrl() }}" aria-label="Pagina siguiente">›</a>
                    @else
                        <span class="epp-page-link is-disabled" aria-hidden="true">›</span>
                    @endif
                </nav>
                --}}
            </div>
        @endif
    </section>

    @if($canConfigureEpps)
        <div id="eppCatalogModal" class="epp-modal" hidden>
            <div class="epp-modal-card" role="dialog" aria-modal="true" aria-labelledby="eppCatalogTitle">
                <div class="epp-modal-header">
                    <div>
                        <h2 id="eppCatalogTitle">Agregar a catalogo</h2>
                        <p>Registra el EPP y define si necesita talla o color al momento de entregarlo.</p>
                    </div>
                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                </div>
                <div class="epp-modal-body">
                    <form method="POST" action="{{ route('epps.catalogo.store') }}" class="epp-form-grid">
                        @csrf
                        <label class="epp-field-wide">
                            EPP
                            <input type="text" name="nombre" value="{{ old('nombre') }}" placeholder="Casco, lentes, guantes..." required data-epp-code-source>
                            <span class="epp-code-preview">Codigo generado: <strong data-epp-code-preview>--</strong></span>
                            <span class="epp-help-text">El codigo se genera automaticamente con el nombre del EPP. No se escribe manualmente.</span>
                        </label>
                        <label>
                            Vida util (dias)
                            <input type="number" name="vida_util_dias" min="1" value="{{ old('vida_util_dias', 30) }}" required>
                        </label>
                        <label>
                            Estado
                            <select name="estado">
                                <option value="ACTIVO" @selected(old('estado', 'ACTIVO') === 'ACTIVO')>Activo</option>
                                <option value="INACTIVO" @selected(old('estado') === 'INACTIVO')>Inactivo</option>
                            </select>
                        </label>
                        <label class="epp-option-toggle">
                            <input type="hidden" name="requiere_talla" value="0">
                            <input type="checkbox" name="requiere_talla" value="1" @checked(old('requiere_talla')) data-epp-toggle-target="eppTallasField">
                            <span>Requiere talla</span>
                        </label>
                        <label id="eppTallasField" class="epp-field-wide epp-option-list" @if(!old('requiere_talla')) hidden @endif>
                            Tallas disponibles
                            <textarea name="tallas" rows="2" placeholder="Ej. S, M, L, XL o 38, 40, 42">{{ old('tallas') }}</textarea>
                            <span class="epp-help-text">Separa las tallas con coma o una por linea. Si ya existen, se actualizaran en el mismo EPP.</span>
                        </label>
                        <label class="epp-option-toggle">
                            <input type="hidden" name="requiere_color" value="0">
                            <input type="checkbox" name="requiere_color" value="1" @checked(old('requiere_color')) data-epp-toggle-target="eppColoresField">
                            <span>Requiere color</span>
                        </label>
                        <label id="eppColoresField" class="epp-field-wide epp-option-list" @if(!old('requiere_color')) hidden @endif>
                            Colores disponibles
                            <textarea name="colores" rows="2" placeholder="Ej. Blanco, azul, negro">{{ old('colores') }}</textarea>
                            <span class="epp-help-text">Separa los colores con coma o una por linea. Se guardan para escogerlos en entregas futuras.</span>
                        </label>
                        <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar EPP</button>
                    </form>
                </div>
            </div>
        </div>

    @endif

    @if($canEditEpps)
        <div id="eppCatalogListModal" class="epp-modal" hidden>
            <div class="epp-modal-card epp-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="eppCatalogListTitle">
                <div class="epp-modal-header">
                    <div>
                        <h2 id="eppCatalogListTitle">Catalogo de EPP</h2>
                        <p>Consulta los EPP registrados y sus reglas de talla o color.</p>
                    </div>
                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                </div>
                <div class="epp-modal-body">
                    <div class="epp-catalog-table-wrap">
                        <table class="epp-catalog-table">
                            <thead>
                                <tr>
                                    <th>EPP</th>
                                    <th>Vida util</th>
                                    <th>Talla</th>
                                    <th>Color</th>
                                    <th>Estado</th>
                                    <th>Edicion</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($catalogo as $epp)
                                    @php
                                        $editingThis = old('catalog_edit_id') === (string) $epp->id;
                                        $editNombre = $editingThis ? old('nombre') : $epp->nombre;
                                        $editVida = $editingThis ? old('vida_util_dias') : $epp->vida_util_dias;
                                        $editEstado = $editingThis ? old('estado', $epp->estado) : $epp->estado;
                                        $editRequiereTalla = $editingThis ? old('requiere_talla') === '1' : (bool) $epp->requiere_talla;
                                        $editTallas = $editingThis ? old('tallas') : implode(', ', $epp->tallas ?? []);
                                        $editRequiereColor = $editingThis ? old('requiere_color') === '1' : (bool) $epp->requiere_color;
                                        $editColores = $editingThis ? old('colores') : implode(', ', $epp->colores ?? []);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $epp->nombre }}</strong>
                                            <span>{{ $epp->codigo }}</span>
                                        </td>
                                        <td>{{ $epp->vida_util_dias }} dias</td>
                                        <td>
                                            @if($epp->requiere_talla)
                                                <span class="epp-rule-pill">Si requiere</span>
                                                <small>{{ implode(', ', $epp->tallas ?? []) ?: 'Sin tallas registradas' }}</small>
                                            @else
                                                <span class="epp-muted">No requiere</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($epp->requiere_color)
                                                <span class="epp-rule-pill">Si requiere</span>
                                                <small>{{ implode(', ', $epp->colores ?? []) ?: 'Sin colores registrados' }}</small>
                                            @else
                                                <span class="epp-muted">No requiere</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="epp-status epp-status-{{ strtolower($epp->estado) }}">{{ ucwords(strtolower($epp->estado)) }}</span>
                                        </td>
                                        <td class="epp-catalog-actions-cell">
                                            <details class="epp-inline-edit" @if($editingThis) open @endif>
                                                <summary>Editar</summary>
                                                <form method="POST" action="{{ route('epps.catalogo.update', $epp->id) }}" class="epp-catalog-edit-form">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="catalog_edit_id" value="{{ $epp->id }}">

                                                    <label class="epp-field-wide">
                                                        EPP
                                                        <input type="text" name="nombre" value="{{ $editNombre }}" required data-epp-code-source>
                                                        <span class="epp-code-preview">Codigo generado: <strong data-epp-code-preview>--</strong></span>
                                                    </label>

                                                    <label>
                                                        Vida util (dias)
                                                        <input type="number" name="vida_util_dias" min="1" value="{{ $editVida }}" required>
                                                    </label>

                                                    <label>
                                                        Estado
                                                        <select name="estado">
                                                            <option value="ACTIVO" @selected($editEstado === 'ACTIVO')>Activo</option>
                                                            <option value="INACTIVO" @selected($editEstado === 'INACTIVO')>Inactivo</option>
                                                        </select>
                                                    </label>

                                                    <label class="epp-option-toggle">
                                                        <input type="hidden" name="requiere_talla" value="0">
                                                        <input type="checkbox" name="requiere_talla" value="1" @checked($editRequiereTalla) data-epp-toggle-target="eppEditTallasField{{ $epp->id }}">
                                                        <span>Requiere talla</span>
                                                    </label>
                                                    <label id="eppEditTallasField{{ $epp->id }}" class="epp-field-wide epp-option-list" @if(!$editRequiereTalla) hidden @endif>
                                                        Tallas disponibles
                                                        <textarea name="tallas" rows="2" placeholder="Ej. S, M, L, XL o 38, 40, 42">{{ $editTallas }}</textarea>
                                                    </label>

                                                    <label class="epp-option-toggle">
                                                        <input type="hidden" name="requiere_color" value="0">
                                                        <input type="checkbox" name="requiere_color" value="1" @checked($editRequiereColor) data-epp-toggle-target="eppEditColoresField{{ $epp->id }}">
                                                        <span>Requiere color</span>
                                                    </label>
                                                    <label id="eppEditColoresField{{ $epp->id }}" class="epp-field-wide epp-option-list" @if(!$editRequiereColor) hidden @endif>
                                                        Colores disponibles
                                                        <textarea name="colores" rows="2" placeholder="Ej. Blanco, azul, negro">{{ $editColores }}</textarea>
                                                    </label>

                                                    <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar cambios</button>
                                                </form>
                                            </details>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="epp-empty">Aun no hay EPP registrados.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    @endif

    @if($canRegisterEpps)
        @php
            $eppDeliveryOptions = collect($eppsActivos ?? [])->mapWithKeys(function ($epp) {
                return [
                    (string) $epp->id => [
                        'requiere_talla' => (bool) $epp->requiere_talla,
                        'tallas' => array_values(array_filter((array) ($epp->tallas ?? []))),
                        'requiere_color' => (bool) $epp->requiere_color,
                        'colores' => array_values(array_filter((array) ($epp->colores ?? []))),
                        'otros_atributos' => array_values(array_filter((array) ($epp->otros_atributos ?? []))),
                    ],
                ];
            })->all();
        @endphp
        <div id="eppDeliveryModal" class="epp-modal" hidden>
            <div class="epp-modal-card epp-modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="eppDeliveryTitle">
                <div class="epp-modal-header">
                    <div>
                        <h2 id="eppDeliveryTitle">Registrar entrega</h2>
                        <p>Anota quien recoge, que recoge y la fecha de entrega.</p>
                    </div>
                    <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                </div>
                <div class="epp-modal-body">
                    <form method="POST" action="{{ route('epps.entregas.store') }}" class="epp-delivery-form epp-delivery-modal-form {{ old('_epp_replaces_entrega_id') ? 'is-change-replacement' : '' }}">
                        @csrf
                        @if(old('_epp_replaces_entrega_id'))
                            <input type="hidden" name="_epp_replaces_entrega_id" value="{{ old('_epp_replaces_entrega_id') }}">
                            <input type="hidden" name="_epp_replacement_fecha" value="{{ old('_epp_replacement_fecha') }}">
                            <input type="hidden" name="_epp_replacement_motivo" value="{{ old('_epp_replacement_motivo') }}">
                            <input type="hidden" name="_epp_replacement_observacion" value="{{ old('_epp_replacement_observacion') }}">
                            <div class="epp-change-pending-note epp-delivery-field-wide">
                                Esta entrega confirmara el cambio de EPP. Si no la guardas, la entrega anterior seguira abierta.
                            </div>
                        @endif
                        <input type="hidden" name="personal_id" id="eppPersonalId" value="{{ old('personal_id') }}">
                        <label class="epp-delivery-field epp-delivery-field-wide epp-autocomplete">
                            <span>Trabajador</span>
                            <input type="search" id="eppPersonalSearch" autocomplete="off" placeholder="Buscar por nombre, DNI o puesto" value="{{ old('personal_label') }}">
                            <div class="epp-autocomplete-results" id="eppPersonalResults" hidden></div>
                        </label>
                        <label class="epp-delivery-field">
                            <span>EPP</span>
                            <select name="epp_id" id="eppSelect" required>
                                <option value="">Seleccionar EPP</option>
                                @foreach($eppsActivos as $epp)
                                    <option value="{{ $epp->id }}" @selected(old('epp_id') === $epp->id)>{{ $epp->nombre }} - {{ $epp->vida_util_dias }} dias</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="epp-delivery-attributes" id="eppDeliveryAttributes" hidden></div>
                        <label class="epp-delivery-field">
                            <span>Cantidad</span>
                            <input type="number" name="cantidad" min="1" value="{{ old('cantidad', 1) }}" required>
                        </label>
                        <label class="epp-delivery-field">
                            <span>Fecha de entrega</span>
                            <input type="date" name="fecha_entrega" value="{{ old('fecha_entrega', now()->toDateString()) }}" required>
                        </label>
                        <aside class="epp-mini-kardex" id="eppLastDeliveryCard" aria-live="polite">
                            <div class="epp-mini-kardex-header">
                                <span>Ultima entrega</span>
                                <strong data-epp-last-status>Sin historial</strong>
                            </div>
                            <p data-epp-last-message>Selecciona trabajador y EPP para ver el ultimo movimiento registrado.</p>
                            <div class="epp-mini-kardex-content" data-epp-last-content hidden>
                                <dl>
                                    <div>
                                        <dt>Fecha</dt>
                                        <dd data-epp-last-date>-</dd>
                                    </div>
                                    <div>
                                        <dt>Cantidad</dt>
                                        <dd data-epp-last-quantity>-</dd>
                                    </div>
                                    <div>
                                        <dt>Vence</dt>
                                        <dd data-epp-last-expiry>-</dd>
                                    </div>
                                    <div>
                                        <dt>Uso efectivo</dt>
                                        <dd data-epp-last-usage>-</dd>
                                    </div>
                                    <div>
                                        <dt>Registrado por</dt>
                                        <dd data-epp-last-user>-</dd>
                                    </div>
                                    <div>
                                        <dt>Cierre</dt>
                                        <dd data-epp-last-close>-</dd>
                                    </div>
                                </dl>
                                <div class="epp-mini-kardex-progress">
                                    <span data-epp-last-progress style="width: 0%"></span>
                                </div>
                                <div class="epp-mini-kardex-periods" data-epp-last-periods hidden></div>
                                <p class="epp-mini-kardex-note" data-epp-last-note hidden></p>
                            </div>
                        </aside>
                        <label class="epp-delivery-field epp-delivery-field-wide">
                            <span>Observacion</span>
                            <textarea name="observacion" rows="3" placeholder="Detalle opcional de entrega, talla, condicion o cambio">{{ old('observacion') }}</textarea>
                        </label>
                        <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Registrar entrega</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const screen = document.querySelector('.epp-screen');
    const modalRoot = screen || document;
    const input = document.getElementById('eppPersonalSearch');
    const hidden = document.getElementById('eppPersonalId');
    const results = document.getElementById('eppPersonalResults');
    const eppSelect = document.getElementById('eppSelect');
    const eppDeliveryAttributes = document.getElementById('eppDeliveryAttributes');
    const eppAttributeConfig = @json($eppDeliveryOptions ?? []);
    const eppInitialAttributes = @json($eppInitialAttributes);
    const kardex = document.getElementById('eppLastDeliveryCard');
    const lastDeliveryUrl = screen?.dataset.lastDeliveryUrl || '';
    const filterPanel = screen?.querySelector('[data-epp-filter-panel]');
    const filterToggle = filterPanel?.querySelector('[data-epp-filter-toggle]');
    const filterBody = filterPanel?.querySelector('[data-epp-filter-body]');

    if (filterPanel && filterToggle && filterBody) {
        const storageKey = 'proserge:epp-filter-collapsed';
        const setFilterCollapsed = (collapsed) => {
            filterPanel.classList.toggle('is-filter-collapsed', collapsed);
            filterBody.hidden = collapsed;
            filterToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        };

        setFilterCollapsed(window.localStorage?.getItem(storageKey) === '1');

        filterToggle.addEventListener('click', () => {
            const collapsed = !filterPanel.classList.contains('is-filter-collapsed');
            setFilterCollapsed(collapsed);
            window.localStorage?.setItem(storageKey, collapsed ? '1' : '0');
        });
    }

    const openEppModal = (id) => {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        modal.hidden = false;
        modal.classList.add('is-open');
        document.body.classList.add('epp-modal-open');
        document.body.style.overflow = 'hidden';
        modal.querySelector('input, select, textarea, button')?.focus({ preventScroll: true });
    };

    const closeEppModal = (modal) => {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        modal.classList.remove('is-open');

        if (!document.querySelector('.epp-modal:not([hidden])')) {
            document.body.style.overflow = '';
            document.body.classList.remove('epp-modal-open');
        }
    };

    modalRoot.querySelectorAll('[data-epp-open-modal]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            openEppModal(button.dataset.eppOpenModal);
        });
    });

    modalRoot.querySelectorAll('.epp-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal || event.target.closest('[data-epp-close-modal]')) {
                event.preventDefault();
                closeEppModal(modal);
            }
        });
    });

    modalRoot.querySelectorAll('[data-epp-toggle-target]').forEach((checkbox) => {
        const target = document.getElementById(checkbox.dataset.eppToggleTarget);
        const syncTarget = () => {
            if (!target) {
                return;
            }

            target.hidden = !checkbox.checked;
        };

        checkbox.addEventListener('change', syncTarget);
        syncTarget();
    });

    modalRoot.querySelectorAll('[data-epp-code-source]').forEach((source) => {
        const preview = source.closest('label')?.querySelector('[data-epp-code-preview]');
        const makeCode = (value) => {
            const normalized = String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .slice(0, 110);

            return normalized || '--';
        };
        const syncCodePreview = () => {
            if (preview) {
                preview.textContent = makeCode(source.value);
            }
        };

        source.addEventListener('input', syncCodePreview);
        syncCodePreview();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.epp-modal:not([hidden])').forEach(closeEppModal);
        }
    });

    if (screen?.dataset.openModal) {
        openEppModal(screen.dataset.openModal);
    }

    if (!screen || !input || !hidden || !results) {
        return;
    }

    const endpoint = screen.dataset.personalSearchUrl;
    let timer = null;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const optionList = (options) => (Array.isArray(options) ? options : [])
        .map((option) => String(option || '').trim())
        .filter((option) => option !== '');

    const normalizedOption = (value) => String(value || '').trim().toUpperCase();

    const selectedAttributeValue = (name, index) => {
        const attributes = Array.isArray(eppInitialAttributes.atributos) ? eppInitialAttributes.atributos : [];
        const configuredName = normalizedOption(name);
        const found = attributes.find((attribute) => {
            const postedName = normalizedOption(attribute?.nombre);

            return postedName === configuredName || String(attribute?.index ?? '') === String(index);
        });

        return String(found?.valor || '').trim();
    };

    const renderDeliveryAttributes = () => {
        if (!eppDeliveryAttributes || !eppSelect) {
            return;
        }

        const config = eppAttributeConfig[eppSelect.value] || {};
        const fields = [];
        const tallas = optionList(config.tallas);
        const colores = optionList(config.colores);
        const atributos = Array.isArray(config.otros_atributos) ? config.otros_atributos : [];

        if (config.requiere_talla && tallas.length > 0) {
            const selectedTalla = normalizedOption(eppInitialAttributes.talla);
            fields.push(`
                <label class="epp-delivery-field">
                    <span>Talla</span>
                    <select name="talla" required>
                        <option value="">Seleccionar talla</option>
                        ${tallas.map((talla) => `<option value="${escapeHtml(talla)}" ${normalizedOption(talla) === selectedTalla ? 'selected' : ''}>${escapeHtml(talla)}</option>`).join('')}
                    </select>
                </label>
            `);
        }

        if (config.requiere_color && colores.length > 0) {
            const selectedColor = normalizedOption(eppInitialAttributes.color);
            fields.push(`
                <label class="epp-delivery-field">
                    <span>Color</span>
                    <select name="color" required>
                        <option value="">Seleccionar color</option>
                        ${colores.map((color) => `<option value="${escapeHtml(color)}" ${normalizedOption(color) === selectedColor ? 'selected' : ''}>${escapeHtml(color)}</option>`).join('')}
                    </select>
                </label>
            `);
        }

        atributos.forEach((atributo, index) => {
            const nombre = String(atributo?.nombre || '').trim();
            const valores = optionList(atributo?.valores);
            if (nombre === '' || valores.length === 0) {
                return;
            }

            const selectedValue = normalizedOption(selectedAttributeValue(nombre, index));
            fields.push(`
                <label class="epp-delivery-field">
                    <span>${escapeHtml(nombre)}</span>
                    <input type="hidden" name="atributos[${index}][index]" value="${index}">
                    <input type="hidden" name="atributos[${index}][nombre]" value="${escapeHtml(nombre)}">
                    <select name="atributos[${index}][valor]" required>
                        <option value="">Seleccionar ${escapeHtml(nombre.toLowerCase())}</option>
                        ${valores.map((value) => `<option value="${escapeHtml(value)}" ${normalizedOption(value) === selectedValue ? 'selected' : ''}>${escapeHtml(value)}</option>`).join('')}
                    </select>
                </label>
            `);
        });

        eppDeliveryAttributes.innerHTML = fields.join('');
        eppDeliveryAttributes.hidden = fields.length === 0;
    };

    const kardexContent = kardex?.querySelector('[data-epp-last-content]');
    const kardexMessage = kardex?.querySelector('[data-epp-last-message]');
    const kardexStatus = kardex?.querySelector('[data-epp-last-status]');
    const kardexProgress = kardex?.querySelector('[data-epp-last-progress]');
    const kardexPeriods = kardex?.querySelector('[data-epp-last-periods]');
    const kardexNote = kardex?.querySelector('[data-epp-last-note]');

    const setKardexText = (selector, value) => {
        const node = kardex?.querySelector(selector);

        if (node) {
            node.textContent = value || '-';
        }
    };

    const resetKardex = (message = 'Selecciona trabajador y EPP para ver el ultimo movimiento registrado.') => {
        if (!kardex) {
            return;
        }

        kardex.dataset.state = 'empty';
        delete kardex.dataset.status;
        if (kardexStatus) {
            kardexStatus.textContent = 'Sin historial';
        }
        if (kardexMessage) {
            kardexMessage.textContent = message;
        }
        if (kardexContent) {
            kardexContent.hidden = true;
        }
        if (kardexProgress) {
            kardexProgress.style.width = '0%';
        }
        if (kardexPeriods) {
            kardexPeriods.hidden = true;
            kardexPeriods.innerHTML = '';
        }
        if (kardexNote) {
            kardexNote.hidden = true;
            kardexNote.textContent = '';
        }
    };

    const setKardexLoading = () => {
        if (!kardex) {
            return;
        }

        kardex.dataset.state = 'loading';
        delete kardex.dataset.status;
        if (kardexStatus) {
            kardexStatus.textContent = 'Consultando';
        }
        if (kardexMessage) {
            kardexMessage.textContent = 'Buscando la ultima entrega registrada para este trabajador y EPP...';
        }
        if (kardexContent) {
            kardexContent.hidden = true;
        }
    };

    const renderKardex = (data) => {
        if (!data) {
            resetKardex('No hay entregas previas para este trabajador y EPP.');
            return;
        }

        kardex.dataset.state = 'ready';
        kardex.dataset.status = String(data.estado || '').toUpperCase();
        if (kardexStatus) {
            kardexStatus.textContent = data.estado_label || data.estado || 'Registrado';
        }
        if (kardexMessage) {
            kardexMessage.textContent = `${data.epp || 'EPP'} - ${data.codigo || 'Sin codigo'}`;
        }
        if (kardexContent) {
            kardexContent.hidden = false;
        }

        setKardexText('[data-epp-last-date]', data.fecha_entrega);
        setKardexText('[data-epp-last-quantity]', data.cantidad ? `${data.cantidad} unidad(es)` : '-');
        setKardexText('[data-epp-last-expiry]', data.fecha_vencimiento_calendario);
        setKardexText('[data-epp-last-usage]', `${data.dias_uso_efectivo || 0}/${data.vida_dias || 0} dias`);
        setKardexText('[data-epp-last-user]', data.registrado_por);
        setKardexText('[data-epp-last-close]', data.fecha_cierre ? `${data.fecha_cierre} por ${data.cerrado_por || 'No registrado'}` : 'Entrega abierta');

        if (kardexProgress) {
            kardexProgress.style.width = `${Math.max(0, Math.min(100, Number(data.uso_porcentaje || 0)))}%`;
        }

        if (kardexPeriods) {
            const periods = Array.isArray(data.periodos_uso) ? data.periodos_uso : [];
            kardexPeriods.hidden = periods.length === 0;
            kardexPeriods.innerHTML = periods.map(period => `
                <span>${escapeHtml(period.desde)} al ${escapeHtml(period.hasta)} - ${escapeHtml(period.parada)} (${escapeHtml(period.dias)} dia${Number(period.dias) === 1 ? '' : 's'})</span>
            `).join('');
        }

        const note = data.motivo_cambio || data.observacion || '';
        if (kardexNote) {
            kardexNote.hidden = note === '';
            kardexNote.textContent = note;
        }
    };

    const refreshLastDelivery = async () => {
        if (!kardex) {
            return;
        }

        const personalId = hidden.value.trim();
        const eppId = eppSelect?.value || '';

        if (!lastDeliveryUrl || !personalId || !eppId) {
            resetKardex();
            return;
        }

        setKardexLoading();

        try {
            const url = new URL(lastDeliveryUrl, window.location.origin);
            url.searchParams.set('personal_id', personalId);
            url.searchParams.set('epp_id', eppId);

            const response = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                throw new Error('No se pudo consultar la ultima entrega.');
            }

            const payload = await response.json();
            renderKardex(payload.data || null);
        } catch (error) {
            resetKardex('No se pudo cargar la ultima entrega. Puedes registrar la nueva entrega normalmente.');
        }
    };

    const closeResults = () => {
        results.hidden = true;
        results.innerHTML = '';
    };

    input.addEventListener('input', () => {
        hidden.value = '';
        resetKardex();
        clearTimeout(timer);
        const q = input.value.trim();

        if (q.length < 2) {
            closeResults();
            return;
        }

        timer = setTimeout(async () => {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(q)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            const items = data.items || [];

            if (!items.length) {
                results.innerHTML = '<div class="epp-autocomplete-empty">Sin resultados</div>';
                results.hidden = false;
                return;
            }

            results.innerHTML = items.map(item => `
                <button type="button" data-id="${escapeHtml(item.id)}" data-label="${escapeHtml(item.label)}">
                    <strong>${escapeHtml(item.nombre)}</strong>
                    <span>${escapeHtml(item.documento)} · ${escapeHtml(item.puesto)} · ${escapeHtml(item.estado)}</span>
                </button>
            `).join('');
            results.hidden = false;
        }, 220);
    });

    results.addEventListener('click', (event) => {
        const option = event.target.closest('button[data-id]');
        if (!option) {
            return;
        }

        hidden.value = option.dataset.id;
        input.value = option.dataset.label;
        closeResults();
        refreshLastDelivery();
    });

    eppSelect?.addEventListener('change', () => {
        renderDeliveryAttributes();
        refreshLastDelivery();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.epp-autocomplete')) {
            closeResults();
        }
    });

    renderDeliveryAttributes();

    if (hidden.value && eppSelect?.value) {
        refreshLastDelivery();
    }
});
</script>
