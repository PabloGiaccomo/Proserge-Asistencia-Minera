@extends('layouts.app')

@section('title', 'Contratos por vencer - Proserge')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canManage = \App\Support\Rbac\PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']);
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
    $currentQuery = request()->query();
@endphp

<style>
.expiry-page {
    display: grid;
    gap: 16px;
}
.expiry-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
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
.expiry-table-wrap {
    overflow-x: auto;
}
.expiry-table {
    width: 100%;
    min-width: 1180px;
    border-collapse: collapse;
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
</style>

<div class="module-page expiry-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Contratos por vencer</h1>
                <p class="page-subtitle">Control simple por periodo y decision individual de renovacion.</p>
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
            <form method="GET" action="{{ route('personal.contratos.expiring') }}" class="expiry-filters">
                <label>
                    Mes
                    <select name="mes">
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" @selected($month === $i)>{{ str_pad((string) $i, 2, '0', STR_PAD_LEFT) }}</option>
                        @endfor
                    </select>
                </label>
                <label>
                    Anio
                    <input type="number" name="anio" min="2000" max="2100" value="{{ $year }}">
                </label>
                <label>
                    Area
                    <input type="text" name="area" value="{{ $filters['area'] ?? '' }}" placeholder="Area">
                </label>
                <label>
                    Cargo
                    <input type="text" name="cargo" value="{{ $filters['cargo'] ?? '' }}" placeholder="Puesto o cargo">
                </label>
                <label>
                    Decision
                    <select name="estado_decision">
                        <option value="">Todas</option>
                        @foreach($decisionOptions as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['estado_decision'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Estado laboral
                    <select name="estado_laboral">
                        <option value="">Todos</option>
                        @foreach(['ACTIVO' => 'Activo', 'FALTA_CONTRATO' => 'Falta contrato', 'INACTIVO' => 'Inactivo', 'CESADO' => 'Cesado'] as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['estado_laboral'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Estado contractual
                    <select name="estado_contractual">
                        <option value="ACTIVO" @selected(($filters['estado_contractual'] ?? 'ACTIVO') === 'ACTIVO')>Activo</option>
                    </select>
                </label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                    <a href="{{ route('personal.contratos.expiring') }}" class="btn btn-outline btn-sm">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Contratos encontrados: {{ $contratos->count() }}</span>
        </div>
        <div class="card-body">
            @if($contratos->isEmpty())
                <p class="text-muted" style="margin:0;">No hay contratos activos con vencimiento en el periodo seleccionado.</p>
            @else
                <div class="expiry-table-wrap">
                    <table class="expiry-table">
                        <thead>
                            <tr>
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
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $personal?->nombre_completo ?: 'Sin trabajador' }}</strong>
                                        <div class="expiry-muted">Contrato #{{ $contrato->contrato_numero }}</div>
                                    </td>
                                    <td>{{ $personal?->tipo_documento ?: 'DNI' }} {{ $personal?->numero_documento ?: $personal?->dni }}</td>
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
@endsection
