@php
    $permissions = session('user.permissions', []);
    $canConfigureEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'configurar');
    $canEditEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'editar');
    $canRegisterEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'registrar');
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
@endphp

<div
    class="epp-screen {{ $embedded ? 'epp-screen-embedded' : '' }}"
    data-personal-search-url="{{ route('epps.personal.buscar') }}"
    data-last-delivery-url="{{ route('epps.entregas.ultima') }}"
    @if($errors->any()) data-open-modal="{{ old('personal_id') || old('epp_id') ? 'eppDeliveryModal' : (old('catalog_edit_id') ? 'eppCatalogListModal' : 'eppCatalogModal') }}" @endif
>
    <header class="epp-header">
        <div>
            <h1>{{ $embedded ? 'Entregas y cambios de EPP' : 'Logistica EPP' }}</h1>
            <p>{{ $embedded ? 'Gestiona entregas, cambios, devoluciones y catalogo desde Logistica.' : 'Registra entregas, cambios y uso efectivo de EPP por trabajador.' }}</p>
        </div>
        @if($canManageEpps)
            <div class="epp-actions" data-epp-actions>
                <button type="button" class="epp-btn epp-btn-primary epp-actions-trigger" data-epp-actions-toggle>
                    Acciones
                    <span aria-hidden="true">v</span>
                </button>
                <div class="epp-actions-menu" data-epp-actions-menu hidden>
                    @if($canConfigureEpps)
                        <button type="button" data-epp-open-modal="eppCatalogModal">Agregar a catalogo</button>
                    @endif
                    @if($canEditEpps)
                        <button type="button" data-epp-open-modal="eppCatalogListModal">Catalogo de EPP</button>
                    @endif
                    @if($canRegisterEpps)
                        <button type="button" data-epp-open-modal="eppDeliveryModal">Registrar entrega</button>
                    @endif
                </div>
            </div>
        @endif
    </header>

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

    <section class="epp-panel">
        <div class="epp-panel-header">
            <div>
                <h2>Seguimiento de EPP</h2>
                <p>Filtra entregas activas, cambios o devoluciones registradas.</p>
            </div>
        </div>
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
                        <span>{{ $epp->codigo }} Â· {{ $epp->vida_util_dias }} dias</span>
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
                            <option value="{{ $epp->id }}" @selected(old('epp_id') === $epp->id)>{{ $epp->nombre }} Â· {{ $epp->vida_util_dias }} dias</option>
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
            <span class="epp-count">{{ $entregasTotal }} registros</span>
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
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $personal?->nombre_completo ?? 'Trabajador no encontrado' }}</strong>
                                <span>{{ $item['documento'] }} Â· {{ $personal?->puesto ?: 'Sin puesto' }}</span>
                            </td>
                            <td>
                                <strong>{{ $epp?->nombre ?? 'EPP no encontrado' }}</strong>
                                <span>{{ $epp?->codigo ?? '-' }} Â· {{ $item['vida_dias'] }} dias de vida</span>
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
                                                <span>{{ \Carbon\Carbon::parse($periodo['desde'])->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($periodo['hasta'])->format('d/m/Y') }} Â· {{ $periodo['dias'] }} dias</span>
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
                            <td>
                                @if($canRegisterEpps && $isActive)
                                    <button type="button" class="epp-btn epp-btn-light epp-row-action-button" data-epp-open-modal="eppCloseModal{{ $entrega->id }}">
                                        Registrar movimiento
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
                                                                <option value="DEVUELTO">Entrego EPP</option>
                                                                <option value="CAMBIADO">Cambio de EPP</option>
                                                            </select>
                                                        </label>
                                                        <label class="epp-close-field">
                                                            <span>Fecha</span>
                                                            <input type="date" name="devuelto_at" value="{{ now()->toDateString() }}" required>
                                                        </label>
                                                    </div>
                                                    <label class="epp-close-field">
                                                        <span>Motivo</span>
                                                        <input type="text" name="motivo_cambio" placeholder="Motivo de cambio o entrega">
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
                                @else
                                    <span class="epp-no-action">Sin accion pendiente</span>
                                @endif
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
                        <span class="epp-page-link is-disabled" aria-hidden="true">â€¹</span>
                    @else
                        <a class="epp-page-link" href="{{ $entregas->previousPageUrl() }}" aria-label="Pagina anterior">â€¹</a>
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
                        <a class="epp-page-link" href="{{ $entregas->nextPageUrl() }}" aria-label="Pagina siguiente">â€º</a>
                    @else
                        <span class="epp-page-link is-disabled" aria-hidden="true">â€º</span>
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
                            <select name="epp_id" id="eppSelect" required>
                                <option value="">Seleccionar EPP</option>
                                @foreach($eppsActivos as $epp)
                                    <option value="{{ $epp->id }}" @selected(old('epp_id') === $epp->id)>{{ $epp->nombre }} - {{ $epp->vida_util_dias }} dias</option>
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
                        <label class="epp-field-wide">
                            Observacion
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
    const input = document.getElementById('eppPersonalSearch');
    const hidden = document.getElementById('eppPersonalId');
    const results = document.getElementById('eppPersonalResults');
    const eppSelect = document.getElementById('eppSelect');
    const kardex = document.getElementById('eppLastDeliveryCard');
    const lastDeliveryUrl = screen?.dataset.lastDeliveryUrl || '';

    const actions = document.querySelector('[data-epp-actions]');
    const actionsToggle = document.querySelector('[data-epp-actions-toggle]');
    const actionsMenu = document.querySelector('[data-epp-actions-menu]');

    const openEppModal = (id) => {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }

        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        modal.querySelector('input, select, textarea, button')?.focus({ preventScroll: true });
    };

    const closeEppModal = (modal) => {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.style.overflow = '';
    };

    actionsToggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        actionsMenu.hidden = !actionsMenu.hidden;
    });

    document.querySelectorAll('[data-epp-open-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            actionsMenu && (actionsMenu.hidden = true);
            openEppModal(button.dataset.eppOpenModal);
        });
    });

    document.querySelectorAll('.epp-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal || event.target.closest('[data-epp-close-modal]')) {
                closeEppModal(modal);
            }
        });
    });

    document.querySelectorAll('[data-epp-toggle-target]').forEach((checkbox) => {
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

    document.querySelectorAll('[data-epp-code-source]').forEach((source) => {
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

    document.addEventListener('click', (event) => {
        if (actions && !actions.contains(event.target)) {
            actionsMenu && (actionsMenu.hidden = true);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            actionsMenu && (actionsMenu.hidden = true);
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
        if (kardexStatus) {
            kardexStatus.textContent = data.estado || 'Registrado';
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
                    <span>${escapeHtml(item.documento)} Â· ${escapeHtml(item.puesto)} Â· ${escapeHtml(item.estado)}</span>
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

    eppSelect?.addEventListener('change', refreshLastDelivery);

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.epp-autocomplete')) {
            closeResults();
        }
    });

    if (hidden.value && eppSelect?.value) {
        refreshLastDelivery();
    }
});
</script>
