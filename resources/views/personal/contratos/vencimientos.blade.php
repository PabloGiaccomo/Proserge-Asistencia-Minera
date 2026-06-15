@extends('layouts.app')

@section('title', 'Contratos por vencer - Proserge')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canManage = \App\Support\Rbac\PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']);
    $canDownloadContractFormats = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'personal', 'exportar');
    $month = (int) ($filters['mes'] ?? now()->month);
    $year = (int) ($filters['anio'] ?? now()->year);
    $today = \Illuminate\Support\Carbon::today();
    $formatDate = function ($date): string {
        if (!$date) {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return '-';
        }
    };
    $decisionLabel = fn ($value): string => $decisionOptions[$value ?: \App\Models\PersonalContrato::DECISION_PENDIENTE] ?? 'Pendiente';
    $reasonLabel = fn ($value): string => $value ? ($reasonOptions[$value] ?? $value) : '-';
    $cessationReasonOptions = $cessationReasonOptions ?? [];
    $contractTypeOptions = $contractTypeOptions ?? [];
    $currentQuery = request()->query();
    $monthNames = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ];
@endphp

<style>
.expiry-page {
    display: grid;
    gap: 16px;
}
.expiry-filters {
    display: grid;
    grid-template-columns: minmax(140px, 0.8fr) minmax(120px, 0.7fr) minmax(190px, 1.2fr) minmax(170px, 1fr) minmax(170px, 1fr) auto;
    gap: 12px;
    align-items: end;
}
.expiry-filters label {
    display: grid;
    gap: 5px;
    color: #475569;
    font-size: 12px;
    font-weight: 800;
}
.expiry-filters input,
.expiry-filters select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 9px 10px;
    color: #0f172a;
    background: #fff;
}
.expiry-filter-actions {
    display: flex;
    align-items: end;
    gap: 8px;
    min-height: 100%;
}
.expiry-filter-note {
    margin: 10px 0 0;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
}
.expiry-page.is-filtering .expiry-table-wrap,
.expiry-page.is-filtering .card:nth-of-type(2) {
    opacity: 0.62;
    pointer-events: none;
}
.expiry-table-wrap {
    overflow-x: auto;
}
.expiry-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.expiry-actions-menu {
    position: relative;
}
.expiry-actions-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.expiry-actions-list {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    z-index: 30;
    min-width: 230px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 8px;
    background: #ffffff;
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.14);
}
.expiry-actions-list[hidden] {
    display: none;
}
.expiry-actions-list button {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 0;
    border-radius: 9px;
    padding: 10px 11px;
    background: transparent;
    color: #0f172a;
    font-weight: 800;
    text-align: left;
    cursor: pointer;
}
.expiry-actions-list button:hover {
    background: #f8fafc;
}
.expiry-table {
    width: 100%;
    min-width: 1180px;
    border-collapse: collapse;
}
.expiry-select-cell {
    width: 42px;
    min-width: 42px;
    text-align: center !important;
}
.expiry-select-check {
    width: 16px;
    height: 16px;
    accent-color: #0d9488;
    cursor: pointer;
}
.expiry-contract-row {
    cursor: pointer;
    transition: background-color 0.14s ease;
}
.expiry-contract-row:hover {
    background: #f8fafc;
}
.expiry-contract-row.is-selected {
    background: #ecfeff;
}
.expiry-table th,
.expiry-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 11px;
    text-align: left;
    vertical-align: top;
}
.expiry-table th {
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
}
.expiry-badge {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 800;
    background: #e2e8f0;
    color: #334155;
}
.expiry-badge.warn {
    background: #fef3c7;
    color: #92400e;
}
.expiry-badge.danger {
    background: #fee2e2;
    color: #991b1b;
}
.expiry-badge.ok {
    background: #dcfce7;
    color: #166534;
}
.expiry-actions {
    display: grid;
    gap: 8px;
    min-width: 260px;
}
.expiry-actions details {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px;
    background: #fff;
}
.expiry-actions summary {
    cursor: pointer;
    color: #0f172a;
    font-weight: 800;
    font-size: 13px;
}
.expiry-action-form {
    display: grid;
    gap: 8px;
    margin-top: 8px;
}
.expiry-action-form select,
.expiry-action-form input,
.expiry-action-form textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 9px;
    font-size: 13px;
}
.expiry-action-form textarea {
    min-height: 68px;
    resize: vertical;
}
.expiry-checkline {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
}
.expiry-checkline input {
    width: auto;
    margin-top: 2px;
}
.expiry-muted {
    color: #64748b;
    font-size: 12px;
    line-height: 1.35;
}
@media (max-width: 1100px) {
    .expiry-filters {
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    }
}
</style>

<div class="module-page expiry-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Vencimientos de contratos</h1>
                <p class="page-subtitle">Contratos activos con fecha fin dentro del mes seleccionado.</p>
            </div>
            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Personal</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-header"><span class="card-title">Filtros</span></div>
        <div class="card-body">
            <form method="GET" action="{{ route('personal.contratos.expiring') }}" class="expiry-filters" id="expiryFiltersForm" autocomplete="off">
                <label>
                    Mes
                    <select name="mes" data-auto-filter>
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" @selected($month === $i)>{{ str_pad((string) $i, 2, '0', STR_PAD_LEFT) }} - {{ $monthNames[$i] }}</option>
                        @endfor
                    </select>
                </label>
                <label>
                    Anio
                    <input type="number" name="anio" min="2000" max="2100" value="{{ $year }}" data-auto-filter>
                </label>
                <label>
                    Cargo / puesto
                    <input type="search" name="cargo" value="{{ $filters['cargo'] ?? '' }}" placeholder="Buscar cargo o puesto" data-auto-filter data-filter-delay="450">
                </label>
                <label>
                    Estado laboral
                    <select name="estado_laboral" data-auto-filter>
                        <option value="">Todos</option>
                        @foreach(['ACTIVO' => 'Activo', 'FALTA_CONTRATO' => 'Falta contrato', 'INACTIVO' => 'Inactivo', 'CESADO' => 'Cesado'] as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['estado_laboral'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Tipo de contrato
                    <select name="tipo_contrato" data-auto-filter>
                        <option value="">Todos</option>
                        @foreach($contractTypeOptions as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['tipo_contrato'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="expiry-filter-actions">
                    <a href="{{ route('personal.contratos.expiring') }}" class="btn btn-outline btn-sm">Limpiar</a>
                </div>
            </form>
            <p class="expiry-filter-note">Los filtros se aplican automaticamente al cambiar un campo.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header expiry-card-header">
            <div>
                <span class="card-title">Contratos encontrados: {{ $contratos->count() }}</span>
                @if($canDownloadContractFormats && $contratos->isNotEmpty())
                    <div class="expiry-muted" id="expirySelectedCount">0 trabajadores seleccionados</div>
                @endif
            </div>
            @if($canDownloadContractFormats && $contratos->isNotEmpty())
                <div class="expiry-actions-menu" data-expiry-actions-menu>
                    <button type="button" class="btn btn-primary btn-sm expiry-actions-toggle" id="expiryActionsToggle" aria-expanded="false" aria-controls="expiryActionsList">
                        Acciones
                        <span aria-hidden="true">▼</span>
                    </button>
                    <div class="expiry-actions-list" id="expiryActionsList" hidden>
                        <button type="button" data-expiry-contract-format-action>
                            <span>Renovacion de contrato</span>
                            <small id="expiryActionsSelectedBadge">0 sel.</small>
                        </button>
                    </div>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if($contratos->isEmpty())
                <p class="text-muted" style="margin:0;">No hay contratos activos con vencimiento en el periodo seleccionado.</p>
            @else
                <div class="expiry-table-wrap">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                @if($canDownloadContractFormats)
                                    <th class="expiry-select-cell">
                                        <input type="checkbox" id="expirySelectAllWorkers" class="expiry-select-check" aria-label="Seleccionar todos los contratos visibles">
                                    </th>
                                @endif
                                <th>Trabajador</th>
                                <th>Documento</th>
                                <th>Cargo / area</th>
                                <th>Periodo</th>
                                <th>Dias</th>
                                <th>Estados</th>
                                <th>Decision</th>
                                <th>Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contratos as $contrato)
                                @php
                                    $personal = $contrato->personal;
                                    $end = $contrato->fecha_fin;
                                    $days = $end ? $today->diffInDays($end, false) : null;
                                    $decision = $contrato->estado_decision_renovacion ?: \App\Models\PersonalContrato::DECISION_PENDIENTE;
                                    $hasPreparation = \App\Models\PersonalContrato::query()
                                        ->where('personal_id', $contrato->personal_id)
                                        ->where('estado', \App\Models\PersonalContrato::ESTADO_PREPARACION)
                                        ->exists();
                                    $hasSigned = $contrato->hasSignedFile();
                                    $nextStart = $end ? $end->copy()->addDay()->toDateString() : now()->toDateString();
                                    $canCloseNotRenewed = $contrato->decision_final === \App\Models\PersonalContrato::DECISION_NO_RENOVAR
                                        && strtoupper((string) $contrato->estado) === \App\Models\PersonalContrato::ESTADO_ACTIVO;
                                    $defaultCeaseDate = $end && $days !== null && $days <= 0
                                        ? $end->toDateString()
                                        : $today->toDateString();
                                    $workerDocument = $personal?->numero_documento ?: $personal?->dni;
                                    $workerPayload = [
                                        'id' => (string) ($personal?->id ?? ''),
                                        'nombre' => (string) ($personal?->nombre_completo ?: 'Trabajador'),
                                        'documento' => (string) ($workerDocument ?: ''),
                                        'puesto' => (string) ($contrato->puesto ?: $personal?->puesto ?: ''),
                                    ];
                                @endphp
                                <tr class="expiry-contract-row"
                                    data-contract-worker='@json($workerPayload)'
                                    data-worker-name="{{ $personal?->nombre_completo ?: 'Trabajador' }}"
                                    data-worker-document="{{ $workerDocument ?: '' }}"
                                    data-worker-position="{{ $contrato->puesto ?: $personal?->puesto ?: '' }}">
                                    @if($canDownloadContractFormats)
                                        <td class="expiry-select-cell" onclick="event.stopPropagation()">
                                            <input
                                                type="checkbox"
                                                class="expiry-select-check js-expiry-contract-worker-check"
                                                data-contract-format-worker-check
                                                value="{{ $personal?->id ?? '' }}"
                                                data-worker-name="{{ $personal?->nombre_completo ?: 'Trabajador' }}"
                                                data-worker-payload='@json($workerPayload)'
                                                aria-label="Seleccionar {{ $personal?->nombre_completo ?: 'trabajador' }} para formato de contrato"
                                                @disabled(!$personal?->id)>
                                        </td>
                                    @endif
                                    <td>
                                        <strong>{{ $personal?->nombre_completo ?: 'Sin trabajador' }}</strong>
                                        <div class="expiry-muted">Contrato #{{ $contrato->contrato_numero }}</div>
                                    </td>
                                    <td>{{ $personal?->tipo_documento ?: 'DNI' }} {{ $workerDocument }}</td>
                                    <td>
                                        <strong>{{ $contrato->puesto ?: $personal?->puesto ?: '-' }}</strong>
                                        <div class="expiry-muted">{{ $contrato->area ?: '-' }}</div>
                                    </td>
                                    <td>
                                        {{ $formatDate($contrato->fecha_inicio) }} al {{ $formatDate($contrato->fecha_fin) }}
                                    </td>
                                    <td>
                                        @if($days === null)
                                            <span class="expiry-badge">Sin fecha</span>
                                        @elseif($days < 0)
                                            <span class="expiry-badge danger">Vencido hace {{ abs($days) }} dias</span>
                                        @elseif($days <= 7)
                                            <span class="expiry-badge danger">Vence en {{ $days }} dias</span>
                                        @elseif($days <= 30)
                                            <span class="expiry-badge warn">Vence en {{ $days }} dias</span>
                                        @else
                                            <span class="expiry-badge ok">{{ $days }} dias</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div><span class="expiry-badge">{{ ucfirst(strtolower($personal?->estado ?: '-')) }}</span></div>
                                        <div style="margin-top:5px;"><span class="expiry-badge">{{ ucfirst(strtolower($contrato->estado)) }}</span></div>
                                        @if($hasPreparation)
                                            <div class="expiry-muted" style="margin-top:6px;">Renovacion en preparacion</div>
                                        @endif
                                        @unless($hasSigned)
                                            <div class="expiry-muted" style="margin-top:6px;">Falta contrato firmado vigente</div>
                                        @endunless
                                    </td>
                                    <td>
                                        <strong>{{ $decisionLabel($decision) }}</strong>
                                        @if($contrato->decision_final)
                                            <div class="expiry-muted">Final: {{ $decisionLabel($contrato->decision_final) }}</div>
                                        @endif
                                        @if($contrato->motivo_no_renovacion)
                                            <div class="expiry-muted">Motivo: {{ $reasonLabel($contrato->motivo_no_renovacion) }}</div>
                                        @endif
                                        @if($contrato->observacion_decision)
                                            <div class="expiry-muted">{{ $contrato->observacion_decision }}</div>
                                        @endif
                                        @if($canCloseNotRenewed)
                                            @if($days !== null && $days <= 0)
                                                <div class="expiry-muted">Listo para cierre manual.</div>
                                            @else
                                                <div class="expiry-muted">Requiere confirmacion para cierre anticipado.</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        {{ $contrato->decisionUsuario?->personal?->nombre_completo ?: $contrato->decisionUsuario?->email ?: '-' }}
                                        <div class="expiry-muted">{{ $contrato->fecha_decision ? $contrato->fecha_decision->format('d/m/Y H:i') : 'Sin registro' }}</div>
                                    </td>
                                    <td>
                                        <div class="expiry-actions">
                                            <a href="{{ route('personal.show', $contrato->personal_id) }}" class="btn btn-outline btn-xs">Ver trabajador</a>
                                            @if($canManage)
                                                <details>
                                                    <summary>Registrar decision</summary>
                                                    <form method="POST" action="{{ route('personal.contratos.decision', array_merge(['contractId' => $contrato->id], $currentQuery)) }}" class="expiry-action-form">
                                                        @csrf
                                                        <select name="estado_decision_renovacion" required>
                                                            @foreach($decisionOptions as $key => $label)
                                                                <option value="{{ $key }}" @selected($decision === $key)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                        <select name="motivo_no_renovacion">
                                                            <option value="">Motivo si no renueva</option>
                                                            @foreach($reasonOptions as $key => $label)
                                                                <option value="{{ $key }}" @selected($contrato->motivo_no_renovacion === $key)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                        <textarea name="observacion_decision" maxlength="5000" placeholder="Observacion">{{ $contrato->observacion_decision }}</textarea>
                                                        <button type="submit" class="btn btn-primary btn-xs">Guardar decision</button>
                                                    </form>
                                                </details>
                                                @if($contrato->decision_final === \App\Models\PersonalContrato::DECISION_RENOVAR && !$hasPreparation && $hasSigned)
                                                    <details>
                                                        <summary>Preparar renovacion</summary>
                                                        <form method="POST" action="{{ route('personal.contratos.prepare-from-decision', array_merge(['contractId' => $contrato->id], $currentQuery)) }}" class="expiry-action-form">
                                                            @csrf
                                                            <input type="date" name="fecha_inicio" value="{{ old('fecha_inicio', $nextStart) }}" required>
                                                            <input type="date" name="fecha_fin" value="{{ old('fecha_fin') }}">
                                                            <textarea name="observacion_renovacion" maxlength="5000" placeholder="Observacion de renovacion">{{ old('observacion_renovacion') }}</textarea>
                                                            <button type="submit" class="btn btn-primary btn-xs">Preparar renovacion</button>
                                                        </form>
                                                    </details>
                                                @elseif($hasPreparation)
                                                    <span class="expiry-muted">Ya existe contrato en preparacion.</span>
                                                @elseif(!$hasSigned)
                                                    <span class="expiry-muted">Regulariza el contrato firmado antes de renovar.</span>
                                                @endif
                                                @if($canCloseNotRenewed)
                                                    <details>
                                                        <summary>Cerrar contrato / registrar cese</summary>
                                                        <form method="POST" action="{{ route('personal.contratos.close-not-renewed', array_merge(['contractId' => $contrato->id], $currentQuery)) }}" class="expiry-action-form">
                                                            @csrf
                                                            @if($days !== null && $days > 0)
                                                                <div class="expiry-muted">El contrato aun no vence. Confirme si desea cerrar anticipadamente.</div>
                                                                <label class="expiry-checkline">
                                                                    <input type="checkbox" name="confirmar_cierre_anticipado" value="1">
                                                                    <span>Confirmo cierre anticipado.</span>
                                                                </label>
                                                            @endif
                                                            <input type="date" name="fecha_cese" value="{{ old('fecha_cese', $defaultCeaseDate) }}">
                                                            <select name="motivo_cese_controlado" required>
                                                                <option value="">Motivo de cese</option>
                                                                @foreach($cessationReasonOptions as $key => $label)
                                                                    <option value="{{ $key }}" @selected(old('motivo_cese_controlado', \App\Models\PersonalContrato::CESE_NO_RENOVACION_CONTRATO) === $key)>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                            <textarea name="observacion_cierre_no_renovacion" maxlength="5000" placeholder="Observacion de cierre{{ $days !== null && $days > 0 ? ' obligatoria si es anticipado' : '' }}">{{ old('observacion_cierre_no_renovacion') }}</textarea>
                                                            <textarea name="observacion_cese_controlado" maxlength="5000" placeholder="Observacion de cese">{{ old('observacion_cese_controlado') }}</textarea>
                                                            <button type="submit" class="btn btn-danger btn-xs">Cerrar contrato / registrar cese</button>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

@include('personal.partials.contract-format-modal', [
    'contractFormatCanDownload' => $canDownloadContractFormats,
    'contractFormatSelectedWorkerSelector' => '.js-expiry-contract-worker-check:checked',
])

<script>
(function () {
    const form = document.getElementById('expiryFiltersForm');
    if (!form) return;

    let timer = null;
    let isSubmitting = false;

    const submitFilters = function (delay) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (isSubmitting) return;
            isSubmitting = true;
            document.querySelector('.expiry-page')?.classList.add('is-filtering');
            form.submit();
        }, delay || 0);
    };

    form.querySelectorAll('[data-auto-filter]').forEach(function (field) {
        const eventName = field.tagName === 'INPUT' && field.type === 'search' ? 'input' : 'change';
        field.addEventListener(eventName, function () {
            const delay = Number(field.dataset.filterDelay || 0);
            submitFilters(delay);
        });
    });
})();

(function () {
    const checks = Array.from(document.querySelectorAll('.js-expiry-contract-worker-check'));
    const selectAll = document.getElementById('expirySelectAllWorkers');
    const selectedCount = document.getElementById('expirySelectedCount');
    const selectedBadge = document.getElementById('expiryActionsSelectedBadge');
    const actionsToggle = document.getElementById('expiryActionsToggle');
    const actionsList = document.getElementById('expiryActionsList');
    const contractFormatAction = document.querySelector('[data-expiry-contract-format-action]');

    if (checks.length === 0) return;

    const selectedChecks = function () {
        return checks.filter(function (input) {
            return input.checked && !input.disabled && String(input.value || '').trim() !== '';
        });
    };

    const updateSelection = function () {
        const selected = selectedChecks();
        const enabled = checks.filter(function (input) { return !input.disabled; });

        checks.forEach(function (input) {
            input.closest('tr')?.classList.toggle('is-selected', input.checked && !input.disabled);
        });

        if (selectAll) {
            selectAll.checked = enabled.length > 0 && selected.length === enabled.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
            selectAll.disabled = enabled.length === 0;
        }

        if (selectedCount) {
            selectedCount.textContent = selected.length + ' trabajador(es) seleccionado(s)';
        }
        if (selectedBadge) {
            selectedBadge.textContent = selected.length + ' sel.';
        }
    };

    const isInteractiveTarget = function (target) {
        return !!target.closest('a, button, input, select, textarea, label, form, details, summary, [role="button"], [contenteditable="true"]');
    };

    checks.forEach(function (input) {
        input.addEventListener('change', updateSelection);
        input.closest('tr')?.addEventListener('click', function (event) {
            if (isInteractiveTarget(event.target) || input.disabled) {
                return;
            }

            input.checked = !input.checked;
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
    });

    selectAll?.addEventListener('change', function () {
        checks.forEach(function (input) {
            if (!input.disabled) {
                input.checked = selectAll.checked;
            }
        });
        updateSelection();
    });

    actionsToggle?.addEventListener('click', function (event) {
        event.stopPropagation();
        const isHidden = actionsList?.hasAttribute('hidden');
        actionsList?.toggleAttribute('hidden', !isHidden);
        actionsToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-expiry-actions-menu]')) {
            actionsList?.setAttribute('hidden', '');
            actionsToggle?.setAttribute('aria-expanded', 'false');
        }
    });

    contractFormatAction?.addEventListener('click', function () {
        actionsList?.setAttribute('hidden', '');
        actionsToggle?.setAttribute('aria-expanded', 'false');
        if (typeof window.openContractFormatModal === 'function') {
            window.openContractFormatModal();
        }
    });

    updateSelection();
})();
</script>
@endsection
