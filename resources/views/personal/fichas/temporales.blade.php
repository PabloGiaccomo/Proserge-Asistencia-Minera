@extends('layouts.app')

@section('title', 'Personal temporal y links - Proserge')

@section('content')
<div class="module-page ficha-workspace is-booting" id="temporalesPageRoot">
    @php
        $emailTemplate = $emailTemplate ?? [
            'subject' => \App\Modules\Personal\Services\PersonalFichaEmailTemplateService::DEFAULT_SUBJECT,
            'body' => \App\Modules\Personal\Services\PersonalFichaEmailTemplateService::DEFAULT_BODY,
            'default_subject' => \App\Modules\Personal\Services\PersonalFichaEmailTemplateService::DEFAULT_SUBJECT,
            'default_body' => \App\Modules\Personal\Services\PersonalFichaEmailTemplateService::DEFAULT_BODY,
            'placeholders' => ['{{ nombre }}', '{{ documento }}', '{{ vence }}', '{{ link }}', '{{ tipo_envio }}'],
        ];
    @endphp
    
    <div class="temporales-boot-overlay" id="temporalesBootOverlay" aria-hidden="true">
        <div class="temporales-boot-card">
            <div class="temporales-boot-head">
                <h2 class="temporales-boot-title">Cargando</h2>
                <span class="temporales-boot-status" id="temporalesBootStatus">Iniciando...</span>
            </div>
            <div class="temporales-boot-progress" aria-hidden="true">
                <div class="temporales-boot-progress-bar" id="temporalesBootProgressBar"></div>
            </div>
        </div>
    </div>
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal temporal y links</h1>
                <p class="page-subtitle">Trabajadores generados desde macro pendientes de completar, validar o activar.</p>
            </div>
            <div class="page-actions">
                <div class="temporales-actions-wrap">
                    <button type="button" class="btn btn-primary" id="temporalesActionsButton" aria-expanded="false" aria-haspopup="true">Acciones</button>
                    <div class="temporales-actions-menu" id="temporalesActionsMenu">
                        @allowedDirect('personal', 'editar')
                            <button type="button" class="temporales-action-item" id="openActivateLinkModal">Activar link</button>
                            <button type="button" class="temporales-action-item" id="openEmailTemplateModal">Editar correo de envio</button>
                            <button type="button" class="temporales-action-item" id="openBulkExtendModal">Ampliar links activos</button>
                        @endallowedDirect
                        @allowedDirect('personal', 'enviar')
                            <button type="button" class="temporales-action-item" id="openBulkEmailModal">Enviar comunicaciones</button>
                        @endallowedDirect
                    </div>
                </div>
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if(count(session('warning_lines', [])) > 0)
        <div class="ficha-alert ficha-alert-warning">
            @foreach(session('warning_lines', []) as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    @endif

    <div class="temporales-toast-stack" id="temporalesToastStack" aria-live="polite" aria-atomic="true"></div>
    <div class="bulk-email-progress" id="bulkEmailProgress" aria-live="polite" aria-atomic="true">
        <div class="bulk-email-progress-head">
            <h2 class="bulk-email-progress-title" id="bulkEmailProgressTitle">Enviando correos</h2>
            <span class="bulk-email-progress-count" id="bulkEmailProgressCount">0/0</span>
        </div>
        <div class="bulk-email-progress-track" aria-hidden="true">
            <div class="bulk-email-progress-bar" id="bulkEmailProgressBar"></div>
        </div>
        <div class="bulk-email-progress-detail" id="bulkEmailProgressDetail">Preparando envio...</div>
    </div>

    @allowedDirect('personal', 'editar')
        <div id="activateLinkModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('activateLinkModal')">
            <div class="modal-backdrop" onclick="closeModal('activateLinkModal')"></div>
            <div class="modal-content activate-link-modal" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Activar link temporal</h2>
                        <p class="modal-subtitle" style="margin:6px 0 0;">Busca el trabajador que debe aparecer en Temporales y links.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('activateLinkModal')" aria-label="Cerrar">X</button>
                </div>
                <form id="activateLinkForm" action="{{ route('personal.fichas.activate-link') }}" method="POST">
                    @csrf
                    <input type="hidden" name="personal_id" id="activateLinkPersonalId">
                    <label class="email-template-label">
                        Buscar trabajador
                        <input id="activateLinkSearch" class="ficha-input" type="search" autocomplete="off" placeholder="Nombre, DNI o puesto" data-search-url="{{ route('personal.fichas.activate-link.search') }}">
                    </label>
                    <div id="activateLinkResults" class="activate-link-results">
                        <div class="bulk-email-empty">Escribe al menos 2 caracteres para buscar.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('activateLinkModal')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="activateLinkSubmit" disabled>Activar link</button>
                    </div>
                </form>
            </div>
        </div>
    @endallowedDirect

    @allowedDirect('personal', 'editar')
        <div id="emailTemplateModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('emailTemplateModal')">
            <div class="modal-backdrop" onclick="closeModal('emailTemplateModal')"></div>
            <div class="modal-content email-template-modal" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Correo de envio</h2>
                        <p class="modal-subtitle" style="margin:6px 0 0;">Usa @{{ link }} para ubicar exactamente donde aparecera el enlace.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('emailTemplateModal')" aria-label="Cerrar">X</button>
                </div>
                <form id="emailTemplateForm" action="{{ route('personal.fichas.email-template.update') }}" method="POST">
                    @csrf
                    <div class="email-template-grid">
                        <div class="email-template-fields">
                            <label class="email-template-label">
                                Asunto
                                <input id="emailTemplateSubject" class="ficha-input" type="text" name="subject" maxlength="180" value="{{ $emailTemplate['subject'] }}" required>
                            </label>
                            <label class="email-template-label">
                                Mensaje
                                <textarea id="emailTemplateBody" class="ficha-input email-template-textarea" name="body" required>{{ $emailTemplate['body'] }}</textarea>
                            </label>
                            <div>
                                <div class="ficha-card-subtitle" style="margin-bottom:8px;">Insertar marcador</div>
                                <div class="email-template-placeholders">
                                    @foreach(($emailTemplate['placeholders'] ?? []) as $placeholder)
                                        <button type="button" class="email-token-btn" data-email-token="{{ $placeholder }}">{{ $placeholder }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <div id="emailTemplateWarning" class="email-template-warning">El mensaje debe incluir @{{ link }} para que el trabajador reciba el enlace.</div>
                        </div>
                        <div class="email-template-preview">
                            <div class="email-template-preview-head">
                                <div class="ficha-card-subtitle">Vista previa</div>
                                <div id="emailTemplatePreviewSubject" class="email-template-preview-subject"></div>
                            </div>
                            <div id="emailTemplatePreviewBody" class="email-template-preview-body"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" id="resetEmailTemplate">Restaurar base</button>
                        <button type="button" class="btn btn-outline" onclick="closeModal('emailTemplateModal')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="saveEmailTemplate">Guardar correo</button>
                    </div>
                </form>
            </div>
        </div>
    @endallowedDirect

    @allowedDirect('personal', 'enviar')
        <div id="bulkEmailModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('bulkEmailModal')">
            <div class="modal-backdrop" onclick="closeModal('bulkEmailModal')"></div>
            <div class="modal-content bulk-email-modal" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Enviar comunicaciones</h2>
                        <p class="modal-subtitle" style="margin:6px 0 0;">Solo aparecen trabajadores con link temporal habilitado.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('bulkEmailModal')" aria-label="Cerrar">X</button>
                </div>
                <form id="bulkEmailForm" action="{{ route('personal.fichas.send-bulk-email') }}" method="POST">
                    @csrf
                    <div class="bulk-email-filters">
                        <label class="bulk-email-filter-field">
                            Estado
                            <select id="bulkEmailEstadoFilter">
                                <option value="">Todos</option>
                                @foreach($estadoOptions as $stateKey => $stateLabel)
                                    @if($stateKey !== '')
                                        <option value="{{ $stateKey }}">{{ $stateLabel }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <label class="bulk-email-filter-field">
                            Vence desde
                            <input id="bulkEmailDateFrom" type="date">
                        </label>
                        <label class="bulk-email-filter-field">
                            Vence hasta
                            <input id="bulkEmailDateTo" type="date">
                        </label>
                        <button type="button" class="btn btn-outline bulk-email-filter-clear" id="bulkEmailClearFilters">Limpiar filtros</button>
                    </div>
                    <div class="bulk-email-toolbar">
                        <label class="bulk-email-select-all">
                            <input id="bulkEmailSelectAll" type="checkbox" checked>
                            Seleccionar todos
                        </label>
                        <div class="ficha-card-subtitle" id="bulkEmailCount">0 seleccionados</div>
                    </div>
                    <div class="bulk-email-list" id="bulkEmailList"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('bulkEmailModal')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="sendBulkEmailButton">Enviar seleccionados</button>
                    </div>
                </form>
            </div>
        </div>
    @endallowedDirect

    @allowedDirect('personal', 'editar')
        <div id="bulkExtendModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('bulkExtendModal')">
            <div class="modal-backdrop" onclick="closeModal('bulkExtendModal')"></div>
            <div class="modal-content bulk-extend-modal" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Ampliar links activos</h2>
                        <p class="modal-subtitle" style="margin:6px 0 0;">Se ampliaran todos los links habilitados hasta la fecha indicada.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeModal('bulkExtendModal')" aria-label="Cerrar">X</button>
                </div>
                <form id="bulkExtendForm" action="{{ route('personal.fichas.extend-bulk-active') }}" method="POST">
                    @csrf
                    <label class="bulk-extend-date">
                        Nueva fecha de vencimiento
                        <input id="bulkExtendDate" class="ficha-input" type="datetime-local" name="expires_at" required>
                    </label>
                    <div class="bulk-email-toolbar">
                        <div class="ficha-card-subtitle" id="bulkExtendCount">0 links activos</div>
                    </div>
                    <div class="bulk-email-list" id="bulkExtendList"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('bulkExtendModal')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="saveBulkExtendButton">Ampliar links</button>
                    </div>
                </form>
            </div>
        </div>
    @endallowedDirect

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title"><span id="temporalesCount">{{ $rowsTotal ?? $rows->count() }}</span> registros temporales</h2>
                <p class="ficha-card-subtitle">Los trabajadores con ficha pendiente aparecen aqui, pero el link solo se habilita cuando se presiona el boton correspondiente.</p>
            </div>
        </div>
        <div class="ficha-card-body">
            <div class="temporales-toolbar-search">
                @include('components.ui.simple-search', [
                    'searchId' => 'temporales-search',
                    'placeholder' => 'Buscar por nombre, documento, puesto o contrato...',
                    'showClear' => true,
                ])
            </div>
            <div class="ficha-batch-table-wrap">
                <table class="ficha-batch-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Documento</th>
                            <th>
                                <div class="dg-head-cell">
                                    <span>Estado</span>
                                    <button
                                        type="button"
                                        class="dg-filter-icon js-dg-filter-trigger {{ filled($estadoFilter ?? '') ? 'is-active' : '' }}"
                                        data-target="temporalesEstadoPopover"
                                        title="Filtrar Estado"
                                        aria-label="Filtrar Estado">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <line x1="4" y1="6" x2="20" y2="6"/>
                                            <line x1="7" y1="12" x2="17" y2="12"/>
                                            <line x1="10" y1="18" x2="14" y2="18"/>
                                        </svg>
                                    </button>
                                    <div id="temporalesEstadoPopover" class="dg-filter-popover" onclick="event.stopPropagation()">
                                        <label class="dg-popover-label" for="temporalesEstadoSelect">Estado</label>
                                        <select id="temporalesEstadoSelect" class="filter-compact-select">
                                            @foreach(($estadoOptions ?? []) as $estadoKey => $estadoLabel)
                                                <option value="{{ $estadoKey }}" {{ ($estadoFilter ?? '') === (string) $estadoKey ? 'selected' : '' }}>
                                                    {{ $estadoLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </th>
                            <th>Vence</th>
                            <th>
                                <div class="dg-head-cell">
                                    <span>Link</span>
                                    <button
                                        type="button"
                                        class="dg-filter-icon js-dg-filter-trigger"
                                        data-target="temporalesLinkPopover"
                                        title="Filtrar Link"
                                        aria-label="Filtrar Link">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <line x1="4" y1="6" x2="20" y2="6"/>
                                            <line x1="7" y1="12" x2="17" y2="12"/>
                                            <line x1="10" y1="18" x2="14" y2="18"/>
                                        </svg>
                                    </button>
                                    <div id="temporalesLinkPopover" class="dg-filter-popover" onclick="event.stopPropagation()">
                                        <label class="dg-popover-label" for="temporalesLinkSelect">Link</label>
                                        <select id="temporalesLinkSelect" class="filter-compact-select">
                                            <option value="">Todos</option>
                                            <option value="habilitado">Habilitados</option>
                                            <option value="deshabilitado">Deshabilitados</option>
                                        </select>
                                    </div>
                                </div>
                            </th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="temporalesRowsBody">
                        @forelse($rows as $index => $row)
                            @php
                                $ficha = $row['ficha'];
                                $personal = $row['personal'];
                                $link = $row['link'];
                                $correo = $row['correo'] ?? null;
                                $emailSentAt = $row['email_sent_at'] ?? null;
                                $missingFields = $row['missing_fields'] ?? [];
                                $missingDocuments = $row['missing_documents'] ?? [];
                                $statusClass = match($row['estado_key'] ?? $ficha->estado) {
                                    'LINK_ENVIADO_PENDIENTE' => 'ficha-status-sent',
                                    'LINK_ENVIADO_VENCIDO' => 'ficha-status-expired',
                                    'FICHA_ENVIADA' => 'ficha-status-sent',
                                    'APROBADO' => 'ficha-status-approved',
                                    'LINK_VENCIDO', 'VENCIDO', 'RECHAZADO' => 'ficha-status-expired',
                                    default => 'ficha-status-pending',
                                };
                            @endphp
                            <tr
                                class="js-person-card"
                                data-row-id="{{ $ficha->id }}"
                                data-nombre="{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}"
                                data-dni="{{ trim(($ficha->tipo_documento ?? '') . ' ' . ($ficha->numero_documento ?? '')) }}"
                                data-puesto="{{ $personal?->puesto ?: 'Puesto pendiente' }}"
                                data-contrato="{{ $ficha->macro_tipo_contrato ?: ($personal?->contrato ?: '') }}"
                                data-estado-key="{{ $row['estado_key'] ?? $ficha->estado }}"
                                data-estado="{{ $row['estado_label'] }}"
                                data-correo="{{ $correo ?? '' }}"
                                data-has-link="{{ $row['url'] ? '1' : '0' }}"
                                data-can-email="{{ ($row['url'] && $correo) ? '1' : '0' }}"
                                data-expires-at="{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '' }}"
                                data-expires-date="{{ optional($link?->expires_at)->toDateString() ?: '' }}"
                                data-celular="{{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '') }}">
                                <td>
                                    <strong>{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}</strong>
                                    <div class="ficha-card-subtitle">{{ $personal?->puesto ?: 'Puesto pendiente' }}</div>
                                    @if($correo)
                                        <div class="ficha-card-subtitle">{{ $correo }}</div>
                                    @endif
                                    @if($emailSentAt)
                                        <div class="ficha-card-subtitle" style="color:#2563eb; margin-top:4px;">
                                            Correo enviado: {{ optional($emailSentAt)->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                    @if(count($missingFields) > 0 || count($missingDocuments) > 0)
                                        <div class="ficha-card-subtitle" style="color:#b45309; margin-top:4px;">
                                            Celular: {{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '-') }}
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}</td>
                                <td><span class="ficha-status {{ $statusClass }}">{{ $row['estado_label'] }}</span></td>
                                <td>{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '-' }}</td>
                                <td>
                                    @if($row['url'])
                                        <div class="ficha-link-box">
                                            <input id="temporalLink{{ $index }}" class="ficha-input" type="text" value="{{ $row['url'] }}" readonly>
                                            <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="temporalLink{{ $index }}">Copiar</button>
                                        </div>
                                    @elseif(!empty($row['can_regularize']))
                                        <span class="ficha-card-subtitle">Link no habilitado todavia. Presiona "Habilitar link temporal".</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="temporal-action-buttons">
                                        <a
                                            href="{{ route('personal.fichas.review', $ficha->id) }}"
                                            class="btn {{ $ficha->estado === 'FICHA_ENVIADA' ? 'btn-primary' : 'btn-outline' }} btn-xs temporal-icon-btn"
                                            title="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}"
                                            aria-label="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <path d="M14 2v6h6"/>
                                                <path d="M16 13H8"/>
                                                <path d="M16 17H8"/>
                                                <path d="M10 9H8"/>
                                            </svg>
                                        </a>
                                        @if($correo && $row['url'] && \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'personal', 'enviar'))
                                            <button type="button"
                                                class="btn btn-outline btn-xs js-send-email temporal-icon-btn"
                                                data-send-url="{{ route('personal.fichas.send-email', $ficha->id) }}"
                                                data-idle-title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                aria-label="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @else
                                            <button type="button" class="btn btn-outline btn-xs temporal-icon-btn" disabled title="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" aria-label="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" style="opacity:.55; cursor:not-allowed;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @endif
                                        @allowedDirect('personal', 'editar')
                                            @if($row['url'] && $link && !$ficha->submitted_at)
                                                <form method="POST" action="{{ route('personal.fichas.extend', $ficha->id) }}" class="js-temporal-action-form">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Ampliar 3 dias" aria-label="Ampliar 3 dias">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="12" cy="12" r="10"/>
                                                            <path d="M12 6v6l4 2"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            @if(!empty($row['can_regularize']))
                                                <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" class="js-temporal-action-form">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline btn-xs temporal-icon-btn"
                                                        title="{{ $row['url'] ? 'Link temporal ya habilitado' : 'Habilitar link temporal' }}"
                                                        aria-label="{{ $row['url'] ? 'Link temporal ya habilitado' : 'Habilitar link temporal' }}"
                                                        @disabled($row['url'])>
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="7" cy="15" r="4"/>
                                                            <path d="M7 13v4"/>
                                                            <path d="M5 15h4"/>
                                                            <path d="M14 7h7"/>
                                                            <path d="M14 12h5"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                        @endallowedDirect
                                        @allowedDirect('personal', 'eliminar')
                                            <form method="POST" action="{{ route('personal.fichas.destroy', $ficha->id) }}" class="js-temporal-action-form" onsubmit="return confirm('Se eliminara este registro de Temporales y links, pero el trabajador seguira en Personal.');">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-xs temporal-icon-btn" title="Quitar de Temporales y links" aria-label="Quitar de Temporales y links">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18"/>
                                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                                        <path d="M10 11v6"/>
                                                        <path d="M14 11v6"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endallowedDirect
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="ficha-alert">No hay trabajadores temporales por ahora.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="personal-pagination-controls">
                <div class="personal-page-size">
                    Mostrar
                    <select id="temporalesPageSize" class="personal-page-size-select">
                    </select>
                    registros
                </div>
                <div class="personal-pagination-info" id="temporalesPaginationMeta"></div>
            </div>

            <div class="personal-pagination" id="temporalesPaginationWrap"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pageRoot = document.getElementById('temporalesPageRoot');
    const bootOverlay = document.getElementById('temporalesBootOverlay');
    const bootProgressBar = document.getElementById('temporalesBootProgressBar');
    const bootStatus = document.getElementById('temporalesBootStatus');
    const toastStack = document.getElementById('temporalesToastStack');
    const searchInput = document.getElementById('temporales-search');
    const searchClear = searchInput?.closest('.simple-search-wrapper')?.querySelector('[data-simple-search-clear]');
    const pageSizeSelect = document.getElementById('temporalesPageSize');
    const paginationMeta = document.getElementById('temporalesPaginationMeta');
    const paginationWrap = document.getElementById('temporalesPaginationWrap');
    const countBadge = document.getElementById('temporalesCount');
    const temporalesActionsButton = document.getElementById('temporalesActionsButton');
    const temporalesActionsMenu = document.getElementById('temporalesActionsMenu');
    const temporalesRowsBody = document.getElementById('temporalesRowsBody');
    const openActivateLinkButton = document.getElementById('openActivateLinkModal');
    const activateLinkForm = document.getElementById('activateLinkForm');
    const activateLinkSearch = document.getElementById('activateLinkSearch');
    const activateLinkResults = document.getElementById('activateLinkResults');
    const activateLinkPersonalId = document.getElementById('activateLinkPersonalId');
    const activateLinkSubmit = document.getElementById('activateLinkSubmit');
    const openEmailTemplateButton = document.getElementById('openEmailTemplateModal');
    const openBulkEmailButton = document.getElementById('openBulkEmailModal');
    const openBulkExtendButton = document.getElementById('openBulkExtendModal');
    const emailTemplateForm = document.getElementById('emailTemplateForm');
    const emailTemplateSubject = document.getElementById('emailTemplateSubject');
    const emailTemplateBody = document.getElementById('emailTemplateBody');
    const emailTemplatePreviewSubject = document.getElementById('emailTemplatePreviewSubject');
    const emailTemplatePreviewBody = document.getElementById('emailTemplatePreviewBody');
    const emailTemplateWarning = document.getElementById('emailTemplateWarning');
    const saveEmailTemplateButton = document.getElementById('saveEmailTemplate');
    const resetEmailTemplateButton = document.getElementById('resetEmailTemplate');
    const bulkEmailForm = document.getElementById('bulkEmailForm');
    const bulkEmailList = document.getElementById('bulkEmailList');
    const bulkEmailSelectAll = document.getElementById('bulkEmailSelectAll');
    const bulkEmailCount = document.getElementById('bulkEmailCount');
    const bulkEmailEstadoFilter = document.getElementById('bulkEmailEstadoFilter');
    const bulkEmailDateFrom = document.getElementById('bulkEmailDateFrom');
    const bulkEmailDateTo = document.getElementById('bulkEmailDateTo');
    const bulkEmailClearFilters = document.getElementById('bulkEmailClearFilters');
    const sendBulkEmailButton = document.getElementById('sendBulkEmailButton');
    const bulkEmailProgress = document.getElementById('bulkEmailProgress');
    const bulkEmailProgressTitle = document.getElementById('bulkEmailProgressTitle');
    const bulkEmailProgressCount = document.getElementById('bulkEmailProgressCount');
    const bulkEmailProgressBar = document.getElementById('bulkEmailProgressBar');
    const bulkEmailProgressDetail = document.getElementById('bulkEmailProgressDetail');
    const bulkExtendForm = document.getElementById('bulkExtendForm');
    const bulkExtendDate = document.getElementById('bulkExtendDate');
    const bulkExtendList = document.getElementById('bulkExtendList');
    const bulkExtendCount = document.getElementById('bulkExtendCount');
    const saveBulkExtendButton = document.getElementById('saveBulkExtendButton');
    const estadoSelect = document.getElementById('temporalesEstadoSelect');
    const linkSelect = document.getElementById('temporalesLinkSelect');
    const filterTriggers = Array.from(document.querySelectorAll('.js-dg-filter-trigger'));
    const filterPopovers = Array.from(document.querySelectorAll('.dg-filter-popover'));
    const emailTemplateDefaultSubject = @json($emailTemplate['default_subject']);
    const emailTemplateDefaultBody = @json($emailTemplate['default_body']);
    const emailTokenNombre = '@{{ nombre }}';
    const emailTokenDocumento = '@{{ documento }}';
    const emailTokenVence = '@{{ vence }}';
    const emailTokenLink = '@{{ link }}';
    const emailTokenTipoEnvio = '@{{ tipo_envio }}';
    const bulkEmailBatchSize = 10;
    let pageSize = Number(pageSizeSelect?.value || 10);
    let currentPage = 1;
    let lastEmailTemplateField = emailTemplateBody;

    function setBootProgress(value, message) {
        if (bootProgressBar) {
            bootProgressBar.style.width = Math.max(0, Math.min(100, value)) + '%';
        }
        if (bootStatus && message) {
            bootStatus.textContent = message;
        }
    }

    function finishBootLoading() {
        setBootProgress(100, 'Vista lista');
        window.requestAnimationFrame(function () {
            setTimeout(function () {
                if (pageRoot) {
                    pageRoot.classList.remove('is-booting');
                }
                if (bootOverlay) {
                    bootOverlay.setAttribute('aria-hidden', 'true');
                }
            }, 80);
        });
    }

    function getRows() {
        return Array.from(document.querySelectorAll('.js-person-card'));
    }

    function showToast(message, options) {
        if (!toastStack || !message) {
            return;
        }

        const settings = options || {};
        const toast = document.createElement('div');
        toast.className = 'temporales-toast';
        if (settings.allowHtml) {
            toast.innerHTML = message;
        } else {
            toast.textContent = message;
        }
        toastStack.appendChild(toast);

        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, settings.duration || 2600);
    }

    function escapeHtml(value) {
        return (value || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function replaceEmailTokens(value, htmlMode) {
        const sample = {
            nombre: 'Juan Perez',
            documento: 'DNI 12345678',
            vence: '15/05/2026 18:00',
            link: 'https://proserge.local/ficha-colaborador/ejemplo',
            tipoEnvio: 'Envio',
        };
        const linkValue = htmlMode
            ? '<span class="email-preview-link">' + escapeHtml(sample.link) + '</span>'
            : sample.link;
        let output = htmlMode ? escapeHtml(value || '') : (value || '').toString();

        [
            [emailTokenNombre, sample.nombre],
            [emailTokenDocumento, sample.documento],
            [emailTokenVence, sample.vence],
            [emailTokenTipoEnvio, sample.tipoEnvio],
            [emailTokenLink, linkValue],
        ].forEach(function (item) {
            output = output.split(item[0]).join(htmlMode && item[0] !== emailTokenLink ? escapeHtml(item[1]) : item[1]);
        });

        return output;
    }

    function renderEmailTemplatePreview() {
        if (!emailTemplateSubject || !emailTemplateBody) {
            return;
        }

        const subject = replaceEmailTokens(emailTemplateSubject.value, false);
        const bodyHtml = replaceEmailTokens(emailTemplateBody.value, true).replace(/\n/g, '<br>');
        const hasLink = emailTemplateBody.value.indexOf(emailTokenLink) !== -1;

        if (emailTemplatePreviewSubject) {
            emailTemplatePreviewSubject.textContent = subject || 'Sin asunto';
        }
        if (emailTemplatePreviewBody) {
            emailTemplatePreviewBody.innerHTML = bodyHtml || '<span class="ficha-card-subtitle">Sin mensaje</span>';
        }
        if (emailTemplateWarning) {
            emailTemplateWarning.classList.toggle('is-visible', !hasLink);
        }
        if (saveEmailTemplateButton) {
            saveEmailTemplateButton.disabled = !hasLink;
        }
    }

    function insertEmailToken(token) {
        const field = lastEmailTemplateField || emailTemplateBody;
        if (!field) {
            return;
        }

        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        const before = field.value.slice(0, start);
        const after = field.value.slice(end);
        field.value = before + token + after;
        field.focus();
        field.selectionStart = field.selectionEnd = start + token.length;
        renderEmailTemplatePreview();
    }

    function closeActionsMenu() {
        if (temporalesActionsMenu) {
            temporalesActionsMenu.classList.remove('is-open');
        }
        if (temporalesActionsButton) {
            temporalesActionsButton.setAttribute('aria-expanded', 'false');
        }
    }

    if (temporalesActionsButton && temporalesActionsMenu) {
        temporalesActionsButton.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = !temporalesActionsMenu.classList.contains('is-open');
            closeAllPopovers();
            temporalesActionsMenu.classList.toggle('is-open', willOpen);
            temporalesActionsButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        temporalesActionsMenu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    if (openEmailTemplateButton) {
        openEmailTemplateButton.addEventListener('click', function () {
            closeActionsMenu();
            renderEmailTemplatePreview();
            openModal('emailTemplateModal');
        });
    }

    [emailTemplateSubject, emailTemplateBody].forEach(function (field) {
        if (!field) return;
        field.addEventListener('focus', function () {
            lastEmailTemplateField = field;
        });
        field.addEventListener('input', renderEmailTemplatePreview);
    });

    document.querySelectorAll('.email-token-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            insertEmailToken(button.dataset.emailToken || '');
        });
    });

    if (resetEmailTemplateButton) {
        resetEmailTemplateButton.addEventListener('click', function () {
            if (emailTemplateSubject) {
                emailTemplateSubject.value = emailTemplateDefaultSubject;
            }
            if (emailTemplateBody) {
                emailTemplateBody.value = emailTemplateDefaultBody;
                lastEmailTemplateField = emailTemplateBody;
            }
            renderEmailTemplatePreview();
        });
    }

    if (emailTemplateForm) {
        emailTemplateForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!emailTemplateBody || emailTemplateBody.value.indexOf(emailTokenLink) === -1) {
                renderEmailTemplatePreview();
                return;
            }

            if (saveEmailTemplateButton) {
                saveEmailTemplateButton.disabled = true;
                saveEmailTemplateButton.textContent = 'Guardando...';
            }

            fetch(emailTemplateForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                },
                body: new FormData(emailTemplateForm),
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            throw new Error(data.error || data.message || 'No se pudo guardar el correo');
                        }

                        return data;
                    });
                })
                .then(function (data) {
                    showToast(data.message || 'Plantilla de correo actualizada.');
                    closeModal('emailTemplateModal');
                })
                .catch(function (error) {
                    alert(error.message || 'No se pudo guardar el correo');
                })
                .finally(function () {
                    if (saveEmailTemplateButton) {
                        saveEmailTemplateButton.textContent = 'Guardar correo';
                        renderEmailTemplatePreview();
                    }
                });
        });
    }

    function updateBulkEmailCount() {
        if (!bulkEmailList) {
            return;
        }

        const enabledChecks = Array.from(bulkEmailList.querySelectorAll('.js-bulk-email-check:not(:disabled)'));
        const checked = enabledChecks.filter(function (input) {
            return input.checked;
        });

        if (bulkEmailCount) {
            bulkEmailCount.textContent = checked.length + ' seleccionado(s)';
        }
        if (bulkEmailSelectAll) {
            bulkEmailSelectAll.checked = enabledChecks.length > 0 && checked.length === enabledChecks.length;
            bulkEmailSelectAll.indeterminate = checked.length > 0 && checked.length < enabledChecks.length;
            bulkEmailSelectAll.disabled = enabledChecks.length === 0;
        }
        if (sendBulkEmailButton) {
            sendBulkEmailButton.disabled = checked.length === 0;
        }
    }

    function bulkEmailFiltersActive() {
        return Boolean(
            (bulkEmailEstadoFilter?.value || '').trim()
            || (bulkEmailDateFrom?.value || '').trim()
            || (bulkEmailDateTo?.value || '').trim()
        );
    }

    function filteredBulkEmailRows() {
        const estado = (bulkEmailEstadoFilter?.value || '').trim().toUpperCase();
        const fromDate = (bulkEmailDateFrom?.value || '').trim();
        const toDate = (bulkEmailDateTo?.value || '').trim();

        return getRows().filter(function (row) {
            const expiresDate = (row.dataset.expiresDate || '').trim();

            if (row.dataset.hasLink !== '1') {
                return false;
            }

            if (estado && (row.dataset.estadoKey || '').trim().toUpperCase() !== estado) {
                return false;
            }

            if (fromDate && (!expiresDate || expiresDate < fromDate)) {
                return false;
            }

            if (toDate && (!expiresDate || expiresDate > toDate)) {
                return false;
            }

            return true;
        });
    }

    function renderBulkEmailList() {
        if (!bulkEmailList) {
            return;
        }

        const rows = filteredBulkEmailRows();

        if (rows.length === 0) {
            bulkEmailList.innerHTML = '<div class="bulk-email-empty">' + (bulkEmailFiltersActive()
                ? 'No hay trabajadores con link habilitado que coincidan con esos filtros.'
                : 'No hay trabajadores con link habilitado para enviar correo.') + '</div>';
            updateBulkEmailCount();
            return;
        }

        bulkEmailList.innerHTML = rows.map(function (row) {
            const canEmail = row.dataset.canEmail === '1';
            const name = row.dataset.nombre || 'Trabajador pendiente';
            const email = row.dataset.correo || '';
            const rowId = row.dataset.rowId || '';
            const estado = row.dataset.estado || '-';
            const expiresAt = row.dataset.expiresAt || '-';
            const meta = (canEmail ? email : 'Sin correo valido registrado') + ' | ' + estado + ' | Vence: ' + expiresAt;

            return '<label class="bulk-email-person ' + (canEmail ? '' : 'is-disabled') + '">' +
                '<input type="checkbox" class="js-bulk-email-check" value="' + escapeHtml(rowId) + '"' + (canEmail ? ' checked' : ' disabled') + '>' +
                '<span>' +
                    '<span class="bulk-email-person-name">' + escapeHtml(name) + '</span>' +
                    '<span class="bulk-email-person-meta">' + escapeHtml(meta) + '</span>' +
                '</span>' +
            '</label>';
        }).join('');

        bulkEmailList.querySelectorAll('.js-bulk-email-check').forEach(function (input) {
            input.addEventListener('change', updateBulkEmailCount);
        });
        updateBulkEmailCount();
    }

    function activeLinkRows() {
        return getRows().filter(function (row) {
            return row.dataset.hasLink === '1';
        });
    }

    function toDatetimeLocalValue(date) {
        const pad = function (value) {
            return String(value).padStart(2, '0');
        };

        return date.getFullYear() + '-' +
            pad(date.getMonth() + 1) + '-' +
            pad(date.getDate()) + 'T' +
            pad(date.getHours()) + ':' +
            pad(date.getMinutes());
    }

    function renderBulkExtendList() {
        if (!bulkExtendList) {
            return;
        }

        const rows = activeLinkRows();
        if (bulkExtendCount) {
            bulkExtendCount.textContent = rows.length + ' link(s) activo(s)';
        }
        if (saveBulkExtendButton) {
            saveBulkExtendButton.disabled = rows.length === 0;
        }

        if (rows.length === 0) {
            bulkExtendList.innerHTML = '<div class="bulk-email-empty">No hay links habilitados para ampliar.</div>';
            return;
        }

        bulkExtendList.innerHTML = rows.map(function (row) {
            const name = row.dataset.nombre || 'Trabajador pendiente';
            const expiresAt = row.dataset.expiresAt || '-';

            return '<div class="bulk-email-person">' +
                '<span></span>' +
                '<span>' +
                    '<span class="bulk-email-person-name">' + escapeHtml(name) + '</span>' +
                    '<span class="bulk-email-person-meta">Vence actual: ' + escapeHtml(expiresAt) + '</span>' +
                '</span>' +
            '</div>';
        }).join('');
    }

    if (openBulkEmailButton) {
        openBulkEmailButton.addEventListener('click', function () {
            closeActionsMenu();
            syncBulkEmailDateBounds();
            renderBulkEmailList();
            openModal('bulkEmailModal');
        });
    }

    if (openBulkExtendButton) {
        openBulkExtendButton.addEventListener('click', function () {
            closeActionsMenu();
            const now = new Date();
            const minDate = new Date(now.getTime() + 5 * 60 * 1000);
            const defaultDate = new Date(now.getTime() + 24 * 60 * 60 * 1000);
            if (bulkExtendDate) {
                bulkExtendDate.min = toDatetimeLocalValue(minDate);
                if (!bulkExtendDate.value) {
                    bulkExtendDate.value = toDatetimeLocalValue(defaultDate);
                }
            }
            renderBulkExtendList();
            openModal('bulkExtendModal');
        });
    }

    function resetActivateLinkModal() {
        if (activateLinkSearch) {
            activateLinkSearch.value = '';
        }
        if (activateLinkPersonalId) {
            activateLinkPersonalId.value = '';
        }
        if (activateLinkSubmit) {
            activateLinkSubmit.disabled = true;
        }
        if (activateLinkResults) {
            activateLinkResults.innerHTML = '<div class="bulk-email-empty">Escribe al menos 2 caracteres para buscar.</div>';
        }
    }

    function renderActivateLinkResults(items) {
        if (!activateLinkResults) {
            return;
        }

        if (!items || items.length === 0) {
            activateLinkResults.innerHTML = '<div class="bulk-email-empty">No se encontraron trabajadores disponibles.</div>';
            return;
        }

        activateLinkResults.innerHTML = items.map(function (item) {
            return '<button type="button" class="activate-link-result" data-personal-id="' + escapeHtml(item.id || '') + '">' +
                '<strong>' + escapeHtml(item.nombre || 'Trabajador') + '</strong>' +
                '<span>' + escapeHtml(item.documento || '-') + ' - ' + escapeHtml(item.puesto || '-') + '</span>' +
                '<span>' + escapeHtml(item.correo || 'Sin correo registrado') + '</span>' +
            '</button>';
        }).join('');
    }

    let activateLinkSearchTimer = null;
    function searchActivateLinkWorkers() {
        if (!activateLinkSearch || !activateLinkResults) {
            return;
        }

        const query = activateLinkSearch.value.trim();
        if (activateLinkPersonalId) {
            activateLinkPersonalId.value = '';
        }
        if (activateLinkSubmit) {
            activateLinkSubmit.disabled = true;
        }

        if (query.length < 2) {
            activateLinkResults.innerHTML = '<div class="bulk-email-empty">Escribe al menos 2 caracteres para buscar.</div>';
            return;
        }

        activateLinkResults.innerHTML = '<div class="bulk-email-empty">Buscando...</div>';
        fetch(activateLinkSearch.dataset.searchUrl + '?q=' + encodeURIComponent(query), {
            headers: {
                'Accept': 'application/json',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('No se pudo buscar trabajadores.');
            }

            return response.json();
        }).then(function (data) {
            renderActivateLinkResults(data.items || []);
        }).catch(function (error) {
            activateLinkResults.innerHTML = '<div class="bulk-email-empty">' + escapeHtml(error.message || 'No se pudo buscar trabajadores.') + '</div>';
        });
    }

    if (openActivateLinkButton) {
        openActivateLinkButton.addEventListener('click', function () {
            closeActionsMenu();
            resetActivateLinkModal();
            openModal('activateLinkModal');
            window.setTimeout(function () {
                activateLinkSearch?.focus();
            }, 60);
        });
    }

    if (activateLinkSearch) {
        activateLinkSearch.addEventListener('input', function () {
            window.clearTimeout(activateLinkSearchTimer);
            activateLinkSearchTimer = window.setTimeout(searchActivateLinkWorkers, 220);
        });
    }

    if (activateLinkResults) {
        activateLinkResults.addEventListener('click', function (event) {
            const button = event.target.closest('.activate-link-result');
            if (!button) {
                return;
            }

            activateLinkResults.querySelectorAll('.activate-link-result').forEach(function (node) {
                node.classList.remove('is-selected');
            });
            button.classList.add('is-selected');
            if (activateLinkPersonalId) {
                activateLinkPersonalId.value = button.dataset.personalId || '';
            }
            if (activateLinkSubmit) {
                activateLinkSubmit.disabled = !button.dataset.personalId;
            }
        });
    }

    if (activateLinkForm) {
        activateLinkForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!activateLinkPersonalId?.value) {
                showToast('Selecciona un trabajador.');
                return;
            }

            if (activateLinkSubmit) {
                activateLinkSubmit.disabled = true;
                activateLinkSubmit.textContent = 'Activando...';
            }

            fetch(activateLinkForm.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
                body: new FormData(activateLinkForm),
            }).then(function (response) {
                if (response.ok) {
                    return response.json();
                }

                return response.json().then(function (data) {
                    throw new Error(data.error || 'No se pudo activar el link.');
                });
            }).then(function (data) {
                if (data.row_html) {
                    upsertRowFromHtml(data.row_html);
                    renderGrid(true);
                }
                closeModal('activateLinkModal');
                showToast(data.message || 'Link temporal activado.');
            }).catch(function (error) {
                alert(error.message || 'No se pudo activar el link.');
            }).finally(function () {
                if (activateLinkSubmit) {
                    activateLinkSubmit.textContent = 'Activar link';
                    activateLinkSubmit.disabled = !activateLinkPersonalId?.value;
                }
            });
        });
    }

    function syncBulkEmailDateBounds() {
        if (bulkEmailDateFrom && bulkEmailDateTo) {
            bulkEmailDateTo.min = bulkEmailDateFrom.value || '';
            bulkEmailDateFrom.max = bulkEmailDateTo.value || '';
        }
    }

    [bulkEmailEstadoFilter, bulkEmailDateFrom, bulkEmailDateTo].forEach(function (control) {
        if (!control) {
            return;
        }

        control.addEventListener('change', function () {
            syncBulkEmailDateBounds();
            renderBulkEmailList();
        });
    });

    if (bulkEmailClearFilters) {
        bulkEmailClearFilters.addEventListener('click', function () {
            if (bulkEmailEstadoFilter) {
                bulkEmailEstadoFilter.value = '';
            }
            if (bulkEmailDateFrom) {
                bulkEmailDateFrom.value = '';
            }
            if (bulkEmailDateTo) {
                bulkEmailDateTo.value = '';
            }
            syncBulkEmailDateBounds();
            renderBulkEmailList();
        });
    }

    if (bulkExtendForm) {
        bulkExtendForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const rows = activeLinkRows();
            const selectedIds = rows.map(function (row) {
                return row.dataset.rowId || '';
            }).filter(Boolean);

            if (selectedIds.length === 0 || !bulkExtendDate?.value) {
                renderBulkExtendList();
                return;
            }

            if (saveBulkExtendButton) {
                saveBulkExtendButton.disabled = true;
                saveBulkExtendButton.textContent = 'Ampliando...';
            }

            const formData = new FormData();
            formData.append('expires_at', bulkExtendDate.value);
            selectedIds.forEach(function (id) {
                formData.append('ficha_ids[]', id);
            });

            fetch(bulkExtendForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token()),
                },
                body: formData,
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            throw new Error(data.error || data.message || 'No se pudieron ampliar los links');
                        }

                        return data;
                    });
                })
                .then(function (data) {
                    closeModal('bulkExtendModal');
                    const skippedCount = Array.isArray(data.skipped) ? data.skipped.length : 0;
                    showToast((data.message || 'Links ampliados.') + (skippedCount > 0 ? ' ' + skippedCount + ' omitido(s).' : ''), { duration: 3600 });
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                })
                .catch(function (error) {
                    alert(error.message || 'No se pudieron ampliar los links');
                    renderBulkExtendList();
                })
                .finally(function () {
                    if (saveBulkExtendButton) {
                        saveBulkExtendButton.textContent = 'Ampliar links';
                    }
                });
        });
    }

    if (bulkEmailSelectAll) {
        bulkEmailSelectAll.addEventListener('change', function () {
            if (!bulkEmailList) {
                return;
            }

            bulkEmailList.querySelectorAll('.js-bulk-email-check:not(:disabled)').forEach(function (input) {
                input.checked = bulkEmailSelectAll.checked;
            });
            updateBulkEmailCount();
        });
    }

    function chunkArray(items, size) {
        const chunks = [];
        for (let index = 0; index < items.length; index += size) {
            chunks.push(items.slice(index, index + size));
        }

        return chunks;
    }

    function updateBulkProgress(processed, total, title, detail, complete) {
        if (!bulkEmailProgress) {
            return;
        }

        const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        bulkEmailProgress.classList.add('is-visible');
        bulkEmailProgress.classList.toggle('is-complete', !!complete);
        if (bulkEmailProgressTitle) {
            bulkEmailProgressTitle.textContent = title || 'Enviando correos';
        }
        if (bulkEmailProgressCount) {
            bulkEmailProgressCount.textContent = processed + '/' + total;
        }
        if (bulkEmailProgressBar) {
            bulkEmailProgressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (bulkEmailProgressDetail) {
            bulkEmailProgressDetail.textContent = detail || '';
        }
    }

    function hideBulkProgressAfterDelay(delay) {
        if (!bulkEmailProgress) {
            return;
        }

        window.setTimeout(function () {
            bulkEmailProgress.classList.remove('is-visible', 'is-complete');
            if (bulkEmailProgressBar) {
                bulkEmailProgressBar.style.width = '0%';
            }
        }, delay || 2600);
    }

    function postBulkEmailChunk(ids) {
        const formData = new FormData();
        ids.forEach(function (id) {
            formData.append('ficha_ids[]', id);
        });

        return fetch(bulkEmailForm.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: formData,
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok && !Array.isArray(data.failed)) {
                    throw new Error(data.error || data.message || 'No se pudieron enviar los correos');
                }

                return data;
            });
        });
    }

    if (bulkEmailForm) {
        bulkEmailForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            const selectedIds = bulkEmailList
                ? Array.from(bulkEmailList.querySelectorAll('.js-bulk-email-check:checked:not(:disabled)')).map(function (input) {
                    return input.value;
                })
                : [];

            if (selectedIds.length === 0) {
                updateBulkEmailCount();
                return;
            }

            if (sendBulkEmailButton) {
                sendBulkEmailButton.disabled = true;
                sendBulkEmailButton.textContent = 'Enviando...';
            }

            closeModal('bulkEmailModal');

            const chunks = chunkArray(selectedIds, bulkEmailBatchSize);
            let processed = 0;
            let sentCount = 0;
            let failedCount = 0;

            updateBulkProgress(0, selectedIds.length, 'Enviando correos', 'Preparando tandas de ' + bulkEmailBatchSize + ' correos...', false);

            try {
                for (let index = 0; index < chunks.length; index += 1) {
                    const chunk = chunks[index];
                    updateBulkProgress(
                        processed,
                        selectedIds.length,
                        'Enviando correos',
                        'Enviando tanda ' + (index + 1) + ' de ' + chunks.length + '...',
                        false
                    );

                    const data = await postBulkEmailChunk(chunk);
                    const sent = Array.isArray(data.sent) ? data.sent.length : 0;
                    const failed = Array.isArray(data.failed) ? data.failed.length : 0;
                    sentCount += sent;
                    failedCount += failed;
                    processed += chunk.length;

                    updateBulkProgress(
                        processed,
                        selectedIds.length,
                        'Enviando correos',
                        sentCount + ' enviados, ' + failedCount + ' con observacion.',
                        false
                    );
                }

                updateBulkProgress(
                    selectedIds.length,
                    selectedIds.length,
                    'Correos enviados',
                    sentCount + ' correo(s) enviado(s).' + (failedCount > 0 ? ' ' + failedCount + ' no se pudieron enviar.' : ''),
                    true
                );
                hideBulkProgressAfterDelay(2800);
            } catch (error) {
                updateBulkProgress(
                    processed,
                    selectedIds.length,
                    'Envio detenido',
                    error.message || 'No se pudieron enviar todos los correos.',
                    false
                );
                alert(error.message || 'No se pudieron enviar los correos');
            } finally {
                if (sendBulkEmailButton) {
                    sendBulkEmailButton.textContent = 'Enviar seleccionados';
                }
                updateBulkEmailCount();
            }
        });
    }

    function closeAllPopovers() {
        filterPopovers.forEach(function (popover) {
            popover.classList.remove('is-open');
        });
        closeActionsMenu();
    }

    function positionPopover(trigger, popover) {
        const rect = trigger.getBoundingClientRect();
        const popoverWidth = popover.offsetWidth || 210;
        const viewportWidth = window.innerWidth;
        const left = Math.min(
            Math.max(12, rect.right - popoverWidth),
            Math.max(12, viewportWidth - popoverWidth - 12)
        );

        popover.style.top = `${rect.bottom + 8}px`;
        popover.style.left = `${left}px`;
    }

    filterTriggers.forEach(function (trigger) {
        const targetId = trigger.dataset.target;
        const popover = targetId ? document.getElementById(targetId) : null;
        if (!popover) return;

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = !popover.classList.contains('is-open');
            closeAllPopovers();
            if (willOpen) {
                popover.classList.add('is-open');
                positionPopover(trigger, popover);
            }
        });
    });

    document.addEventListener('click', function () {
        closeAllPopovers();
    });

    window.addEventListener('resize', function () {
        filterTriggers.forEach(function (trigger) {
            const targetId = trigger.dataset.target;
            const popover = targetId ? document.getElementById(targetId) : null;
            if (popover && popover.classList.contains('is-open')) {
                positionPopover(trigger, popover);
            }
        });
    });

    setBootProgress(18, 'Cargando registros...');

    if (estadoSelect) {
        estadoSelect.addEventListener('change', function () {
            syncFilterTriggerStates();
            renderGrid(true);
        });
    }

    if (linkSelect) {
        linkSelect.addEventListener('change', function () {
            syncFilterTriggerStates();
            renderGrid(true);
        });
    }

    function syncFilterTriggerStates() {
        filterTriggers.forEach(function (trigger) {
            const targetId = trigger.dataset.target;
            const hasValue = targetId === 'temporalesEstadoPopover'
                ? (estadoSelect?.value || '').trim().length > 0
                : targetId === 'temporalesLinkPopover'
                    ? (linkSelect?.value || '').trim().length > 0
                    : false;

            trigger.classList.toggle('is-active', hasValue);
        });
    }

    function normalizeText(value) {
        return (value || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function applyFilters() {
        const search = normalizeText(searchInput?.value || '');
        const searchTokens = search.split(' ').filter(Boolean);
        const estado = normalizeText(estadoSelect?.value || '');
        const linkState = (linkSelect?.value || '').trim();

        return getRows().filter(function (row) {
            const searchable = normalizeText([
                row.dataset.nombre,
                row.dataset.dni,
                row.dataset.puesto,
                row.dataset.contrato,
                row.dataset.estado,
                row.dataset.correo,
                row.dataset.celular,
            ].join(' '));

            if (searchTokens.length && !searchTokens.every(function (token) {
                return searchable.includes(token);
            })) {
                return false;
            }

            if (estado && normalizeText(row.dataset.estado || '') !== estado) {
                return false;
            }

            if (linkState === 'habilitado' && row.dataset.hasLink !== '1') {
                return false;
            }

            if (linkState === 'deshabilitado' && row.dataset.hasLink === '1') {
                return false;
            }

            return true;
        });
    }

    function renderPagination(totalPages) {
        if (!paginationWrap) {
            return;
        }

        if (totalPages <= 1) {
            paginationWrap.innerHTML = '';
            return;
        }

        const maxVisible = 7;
        const visiblePages = [];

        if (totalPages <= maxVisible) {
            for (let page = 1; page <= totalPages; page += 1) {
                visiblePages.push(page);
            }
        } else {
            const pages = new Set([1, totalPages]);
            const around = Math.max(1, Math.floor((maxVisible - 3) / 2));
            const start = Math.max(2, currentPage - around);
            const end = Math.min(totalPages - 1, currentPage + around);

            for (let page = start; page <= end; page += 1) {
                pages.add(page);
            }

            const ordered = Array.from(pages).sort(function (a, b) {
                return a - b;
            });

            ordered.forEach(function (page, index) {
                if (index > 0 && page - ordered[index - 1] > 1) {
                    visiblePages.push('ellipsis');
                }
                visiblePages.push(page);
            });
        }

        let html = '';
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage - 1) + '"' + (currentPage === 1 ? ' disabled' : '') + '>&lsaquo;</button>';
        visiblePages.forEach(function (page) {
            if (page === 'ellipsis') {
                html += '<span class="personal-pager-ellipsis">...</span>';
                return;
            }

            html += '<button type="button" class="personal-pager-btn ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage + 1) + '"' + (currentPage === totalPages ? ' disabled' : '') + '>&rsaquo;</button>';
        paginationWrap.innerHTML = html;
    }

    function clampPage(page, totalPages) {
        if (Number.isNaN(page) || page < 1) {
            return 1;
        }

        if (page > totalPages) {
            return totalPages;
        }

        return page;
    }

    function buildPageSizeOptions(totalCount) {
        if (!pageSizeSelect) {
            return;
        }

        const total = Math.max(1, Number(totalCount || getRows().length || 1));
        const base = [10, 50, 100, 200, 300, total];
        const values = Array.from(new Set(base.filter(function (value) {
            return Number.isFinite(value) && value > 0 && value <= total;
        }))).sort(function (a, b) {
            return a - b;
        });

        if (values.length === 0) {
            values.push(total || 1);
        }

        pageSizeSelect.innerHTML = values.map(function (value) {
            return '<option value="' + value + '">' + value + '</option>';
        }).join('');
    }

    function syncPageSizeOptions(totalCount, preferredValue) {
        if (!pageSizeSelect) {
            return;
        }

        buildPageSizeOptions(totalCount);

        const optionValues = Array.from(pageSizeSelect.options).map(function (option) {
            return String(option.value);
        });
        const preferred = String(preferredValue || pageSize || pageSizeSelect.value || '');

        if (preferred && optionValues.indexOf(preferred) !== -1) {
            pageSizeSelect.value = preferred;
        } else if (optionValues.length > 0) {
            pageSizeSelect.value = optionValues[optionValues.length - 1];
        }

        pageSize = Number(pageSizeSelect.value || optionValues[0] || 10);
    }

    function renderGrid(resetPage) {
        if (resetPage) {
            currentPage = 1;
        }

        const filtered = applyFilters();
        const total = filtered.length;
        syncPageSizeOptions(total, pageSize);
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = clampPage(currentPage, totalPages);

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

        getRows().forEach(function (row) {
            row.style.display = 'none';
        });

        filtered.slice(start, end).forEach(function (row) {
            row.style.display = 'table-row';
        });

        if (paginationMeta) {
            paginationMeta.textContent = total === 0
                ? '0 resultados'
                : 'Mostrando ' + (start + 1) + '-' + Math.min(end, total) + ' de ' + total;
        }

        if (countBadge) {
            countBadge.textContent = String(total);
        }

        renderPagination(totalPages);
    }

    setBootProgress(42, 'Aplicando filtros...');

    if (searchInput) {
        const syncSearchClear = function () {
            if (searchClear) {
                searchClear.style.display = searchInput.value.trim().length > 0 ? 'flex' : 'none';
            }
        };

        searchInput.addEventListener('input', function () {
            syncSearchClear();
            renderGrid(true);
        });

        syncSearchClear();
    }

    if (searchInput && searchClear) {
        searchClear.addEventListener('click', function () {
            searchInput.value = '';
            renderGrid(true);
            searchClear.style.display = 'none';
            searchInput.focus();
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-page]');
            if (!button || button.hasAttribute('disabled')) {
                return;
            }

            currentPage = Number(button.dataset.page || 1);
            renderGrid(false);
        });
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            pageSize = Number(pageSizeSelect.value || 10);
            renderGrid(true);
        });
    }

    function rebindRow(row) {
        if (!row) {
            return;
        }

        bindCopyButtons(row);
        bindSendEmailButtons(row);
        bindActionForms(row);
    }

    function replaceRowFromHtml(currentRow, html) {
        if (!currentRow || !html) {
            return currentRow;
        }

        const template = document.createElement('tbody');
        template.innerHTML = html.trim();
        const nextRow = template.querySelector('.js-person-card');

        if (!nextRow) {
            return currentRow;
        }

        currentRow.replaceWith(nextRow);
        rebindRow(nextRow);
        return nextRow;
    }

    function upsertRowFromHtml(html) {
        if (!html || !temporalesRowsBody) {
            return null;
        }

        const template = document.createElement('tbody');
        template.innerHTML = html.trim();
        const nextRow = template.querySelector('.js-person-card');

        if (!nextRow) {
            return null;
        }

        const currentRow = nextRow.dataset.rowId
            ? document.querySelector('.js-person-card[data-row-id="' + nextRow.dataset.rowId + '"]')
            : null;

        const emptyRow = temporalesRowsBody.querySelector('td[colspan="6"]')?.closest('tr');
        if (emptyRow) {
            emptyRow.remove();
        }

        if (currentRow) {
            currentRow.replaceWith(nextRow);
        } else {
            temporalesRowsBody.prepend(nextRow);
        }

        rebindRow(nextRow);
        return nextRow;
    }

    function bindCopyButtons(scope) {
        (scope || document).querySelectorAll('.js-copy-ficha-link').forEach(function (button) {
            if (button.dataset.boundCopy === '1') return;
            button.dataset.boundCopy = '1';

            button.addEventListener('click', async function () {
                const input = document.getElementById(button.dataset.target);
                if (!input) return;
                input.select();
                input.setSelectionRange(0, 99999);
                try {
                    await navigator.clipboard.writeText(input.value);
                    button.textContent = 'Copiado';
                    setTimeout(() => button.textContent = 'Copiar', 1800);
                } catch (error) {
                    document.execCommand('copy');
                }
            });
        });
    }

    function bindSendEmailButtons(scope) {
        (scope || document).querySelectorAll('.js-send-email').forEach(function (button) {
            if (button.dataset.boundEmail === '1') return;
            button.dataset.boundEmail = '1';

            button.addEventListener('click', function () {
                if (button.disabled) return;

                const originalTitle = button.getAttribute('title') || button.dataset.idleTitle || 'Enviar al correo';
                const row = button.closest('.js-person-card');
                const workerName = (row?.dataset.nombre || 'trabajador').trim();
                const resend = /volver a enviar/i.test(originalTitle);
                button.disabled = true;
                button.setAttribute('title', 'Enviando correo...');
                button.setAttribute('aria-label', 'Enviando correo...');

                fetch(button.dataset.sendUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'Accept': 'application/json',
                    },
                }).then(function (response) {
                    if (response.ok) {
                        return response.json().then(function (data) {
                            button.dataset.idleTitle = 'Volver a enviar correo';
                            button.setAttribute('title', 'Volver a enviar correo');
                            button.setAttribute('aria-label', 'Volver a enviar correo');
                            if (data.row_html && row) {
                                replaceRowFromHtml(row, data.row_html);
                                renderGrid(false);
                            }
                            showToast((resend ? 'Correo reenviado a ' : 'Correo enviado a ') + workerName);
                        });
                    }

                    return response.json().then(function (data) {
                        throw new Error(data.error || 'Error al enviar el correo');
                    });
                }).catch(function (error) {
                    alert(error.message || 'Error de conexion al enviar el correo');
                    button.setAttribute('title', originalTitle);
                    button.setAttribute('aria-label', originalTitle);
                }).finally(function () {
                    button.disabled = false;
                });
            });
        });
    }

    function bindActionForms(scope) {
        (scope || document).querySelectorAll('.js-temporal-action-form').forEach(function (form) {
            if (form.dataset.boundAction === '1') return;
            form.dataset.boundAction = '1';

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                }

                const row = form.closest('.js-person-card');
                const workerName = (row?.dataset.nombre || 'trabajador').trim();

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'Accept': 'application/json',
                    },
                }).then(function (response) {
                    if (response.ok) {
                        return response.json();
                    }

                    return response.json().then(function (data) {
                        throw new Error(data.error || 'No se pudo completar la acción.');
                    });
                }).then(function (data) {
                    if (data.removed) {
                        row?.remove();
                        renderGrid(false);
                        showToast(data.message || ('Registro quitado de Temporales y links para ' + workerName));
                        return;
                    }

                    if (data.row_html && row) {
                        replaceRowFromHtml(row, data.row_html);
                        renderGrid(false);
                    }

                    showToast(data.message || 'Acción completada.');
                }).catch(function (error) {
                    alert(error.message || 'No se pudo completar la acción.');
                }).finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                });
            });
        });
    }

    bindCopyButtons(document);
    bindSendEmailButtons(document);
    bindActionForms(document);

    setBootProgress(76, 'Mostrando filas...');

    const successMessage = @json(session('success'));

    if (successMessage) {
        showToast(successMessage);
    }

    syncFilterTriggerStates();
    renderGrid(true);

    window.requestAnimationFrame(function () {
        setBootProgress(94, 'Ajustando vista final...');
        finishBootLoading();
    });
});
</script>
@endpush
