@extends('layouts.app')

@section('title', 'Man Power - Proserge')

@php
    use App\Support\Rbac\PermissionMatrix;

    $permissions = session('user.permissions', []);
    $canCreateManPowerGroup = PermissionMatrix::allowsDirect($permissions, 'man_power', 'crear');
    $canAssignManPower = PermissionMatrix::allowsDirect($permissions, 'man_power', 'asignar');
    $canDuplicateManPower = PermissionMatrix::allowsDirect($permissions, 'man_power', 'duplicar');
    $canRegisterAttendance = PermissionMatrix::allowsDirect($permissions, 'asistencias', 'registrar');
    $canViewMiAsistencia = PermissionMatrix::allowsDirect($permissions, 'mi_asistencia', 'ver');
    $canViewTransportes = PermissionMatrix::allowsDirect($permissions, 'transportes', 'ver');

    $context = $context ?? [];
    $selected = $context['selected'] ?? [];
    $summary = $context['resumen'] ?? [];
    $parada = $context['rq_mina'] ?? $parada ?? null;
    $plan = $context['plan'] ?? null;
    $plans = collect($context['plans'] ?? []);
    $operationalGroups = collect($context['grupos_operativos'] ?? []);
    $assignments = collect($context['asignaciones'] ?? []);
    $availableAssignments = $assignments->where('disponible', true)->values();
    $pendingAssignments = $availableAssignments;
    $groups = collect($context['grupos_man_power'] ?? $grupos ?? []);
    $legacyGroups = collect($context['legacy']['grupos'] ?? []);
    $fecha = $selected['fecha'] ?? $fecha ?? now()->addDay()->toDateString();
    $turno = $selected['turno'] ?? $turno ?? 'DIA';
    $selectedRqMinaId = $selected['rq_mina_id'] ?? $selectedRqMinaId ?? '';
    $selectedPlanId = $selected['plan_id'] ?? $selectedPlanId ?? '';
    $search = $search ?? '';

    $paradaOptions = collect($context['paradas'] ?? $paradas ?? [])->map(function (array $item): array {
        $parts = collect([
            $item['mina_nombre'] ?? null,
            $item['area'] ?? null,
            $item['destino_nombre'] ?? null,
        ])->filter()->unique()->values();

        return [
            'id' => $item['rq_mina_id'],
            'label' => $parts->isNotEmpty() ? $parts->implode(' - ') : $item['rq_mina_id'],
        ];
    })->values();

    $selectedParadaLabel = $paradaOptions->firstWhere('id', $selectedRqMinaId)['label'] ?? $search;
    $defaultDestinoTipo = $parada['destino_tipo'] ?? 'MINA';
    $defaultDestinoId = $parada['destino_id'] ?? $parada['mina_id'] ?? '';
    $defaultDestinoNombre = $parada['destino_nombre'] ?? $parada['mina_nombre'] ?? '';
    $dateMin = $plan['fecha_inicio'] ?? $parada['fecha_inicio'] ?? null;
    $dateMax = $plan['fecha_fin'] ?? $parada['fecha_fin'] ?? null;
@endphp

@push('styles')
<style>
    .mp-shell { color:#071b3a; }
    .mp-page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .mp-page-title { margin:0; font-size:28px; font-weight:800; color:#071b3a; }
    .mp-page-subtitle { margin:4px 0 0; color:#5d708c; font-size:15px; }
    .mp-card { background:#fff; border:1px solid #d8e4f2; border-radius:16px; box-shadow:0 10px 24px rgba(7,27,58,.06); }
    .mp-card + .mp-card, .mp-summary + .mp-card { margin-top:16px; }
    .mp-card-head { padding:18px 22px; border-bottom:1px solid #e6edf5; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
    .mp-card-title { margin:0; font-size:18px; font-weight:800; color:#071b3a; }
    .mp-card-subtitle { margin:5px 0 0; color:#657792; font-size:14px; }
    .mp-card-body { padding:20px 22px; }
    .mp-filter-grid { display:grid; grid-template-columns:minmax(260px,1.4fr) minmax(220px,1fr) 170px 150px auto; gap:14px; align-items:end; }
    .mp-form-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
    .mp-field { display:flex; flex-direction:column; gap:7px; }
    .mp-field label { color:#334763; font-size:12px; font-weight:800; text-transform:uppercase; }
    .mp-input, .mp-select, .mp-textarea { width:100%; border:1px solid #cddbef; border-radius:12px; padding:12px 14px; color:#071b3a; background:#fff; font:inherit; min-height:46px; }
    .mp-textarea { min-height:78px; resize:vertical; }
    .mp-input:focus, .mp-select:focus, .mp-textarea:focus { outline:none; border-color:#18c7b5; box-shadow:0 0 0 3px rgba(24,199,181,.14); }
    .mp-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .mp-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border-radius:12px; padding:12px 16px; border:1px solid #cddbef; background:#fff; color:#071b3a; font-weight:800; text-decoration:none; cursor:pointer; min-height:44px; }
    .mp-btn-primary { background:#18c7b5; border-color:#18c7b5; color:#fff; box-shadow:0 8px 18px rgba(24,199,181,.24); }
    .mp-btn-soft { background:#eefdfa; border-color:#8ee8dd; color:#007f76; }
    .mp-btn-danger { color:#c62828; border-color:#ffc8c8; background:#fff7f7; }
    .mp-btn[disabled], .mp-mini-btn[disabled] { opacity:.55; cursor:not-allowed; box-shadow:none; }
    .mp-summary { display:grid; grid-template-columns:repeat(8,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
    .mp-summary-item { padding:14px 16px; background:#fff; border:1px solid #d8e4f2; border-radius:14px; }
    .mp-summary-label { display:block; color:#657792; font-size:12px; font-weight:700; }
    .mp-summary-value { display:block; margin-top:6px; font-size:20px; font-weight:900; color:#071b3a; }
    .mp-alert { border-radius:14px; padding:14px 16px; margin-bottom:16px; font-weight:700; }
    .mp-alert-ok { background:#e9fbf4; color:#08745f; border:1px solid #aeeedc; }
    .mp-alert-error { background:#fff1f2; color:#b42318; border:1px solid #fecdd3; }
    .mp-chip { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; background:#edf4ff; color:#23508a; font-size:12px; font-weight:800; }
    .mp-chip-ok { background:#dcfce7; color:#166534; }
    .mp-chip-warn { background:#fff7cc; color:#8a5a00; }
    .mp-chip-danger { background:#ffe4e6; color:#b42318; }
    .mp-chip-neutral { background:#eef2f7; color:#475569; }
    .mp-operative-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
    .mp-operative-card { border:1px solid #d8e4f2; border-radius:14px; background:#fbfdff; padding:16px; display:grid; gap:12px; }
    .mp-operative-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .mp-operative-title { margin:0; font-size:16px; font-weight:900; color:#071b3a; }
    .mp-meta { margin:4px 0 0; color:#657792; font-size:12px; line-height:1.35; }
    .mp-progress { height:8px; border-radius:99px; background:#e6edf5; overflow:hidden; }
    .mp-progress span { display:block; height:100%; background:#18c7b5; }
    .mp-activity-list, .mp-person-list { display:grid; gap:8px; }
    .mp-activity-row, .mp-person-row { padding:10px 12px; border:1px solid #e6edf5; background:#fff; border-radius:12px; }
    .mp-activity-row { display:flex; justify-content:space-between; gap:12px; }
    .mp-people-toolbar { display:flex; gap:12px; align-items:center; justify-content:space-between; margin:18px 0 12px; flex-wrap:wrap; }
    .mp-people-search { max-width:420px; flex:1 1 260px; }
    .mp-people-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; max-height:520px; overflow:auto; padding-right:4px; }
    .mp-person { border:1px solid #d8e4f2; border-radius:14px; padding:12px; display:grid; grid-template-columns:auto 1fr; gap:10px; align-items:flex-start; background:#fbfdff; min-height:118px; }
    .mp-person.is-used { opacity:.72; background:#f5f8fc; }
    .mp-person[draggable="true"] { cursor:grab; }
    .mp-person.is-dragging { opacity:.45; border-color:#18c7b5; box-shadow:0 10px 24px rgba(24,199,181,.18); }
    .mp-person-main { min-width:0; }
    .mp-person-name { display:block; color:#071b3a; font-size:13px; font-weight:900; line-height:1.35; }
    .mp-person-meta { display:block; margin-top:3px; color:#657792; font-size:12px; line-height:1.35; }
    .mp-person-actions { grid-column:1 / -1; display:flex; gap:8px; flex-wrap:wrap; }
    .mp-mini-btn { border:1px solid #cddbef; border-radius:10px; background:#fff; color:#071b3a; font-size:12px; font-weight:800; padding:7px 9px; cursor:pointer; }
    .mp-mini-btn.is-supervisor { color:#007f76; border-color:#8ee8dd; background:#eefdfa; }
    .mp-groups-layout { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
    .mp-group { border:1px solid #d8e4f2; border-radius:14px; background:#fff; overflow:hidden; }
    .mp-group.is-drop-ready { border-color:#18c7b5; box-shadow:0 0 0 3px rgba(24,199,181,.12); }
    .mp-group.is-drop-error { border-color:#fca5a5; box-shadow:0 0 0 3px rgba(239,68,68,.12); }
    .mp-group-head { padding:14px 16px; background:#f8fbff; border-bottom:1px solid #e6edf5; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .mp-group-title { margin:0; font-size:15px; font-weight:900; color:#071b3a; }
    .mp-group-body { padding:14px 16px; }
    .mp-empty { padding:22px; text-align:center; color:#657792; border:1px dashed #cddbef; border-radius:14px; background:#fbfdff; }
    .mp-toast-stack { position:fixed; right:22px; bottom:22px; z-index:1100; display:grid; gap:10px; width:min(360px, calc(100vw - 32px)); pointer-events:none; }
    .mp-toast { border:1px solid #d8e4f2; border-left:5px solid #18c7b5; border-radius:14px; background:#fff; box-shadow:0 18px 44px rgba(7,27,58,.18); padding:13px 15px; color:#071b3a; font-weight:800; transform:translateY(10px); opacity:0; transition:opacity .18s ease, transform .18s ease; pointer-events:auto; }
    .mp-toast.is-visible { transform:translateY(0); opacity:1; }
    .mp-toast.is-ok { border-left-color:#16a34a; }
    .mp-toast.is-error { border-left-color:#dc2626; }
    .mp-toast.is-info { border-left-color:#18c7b5; }
    .mp-toast small { display:block; margin-top:3px; color:#657792; font-weight:700; }
    .mp-modal-backdrop { position:fixed; inset:0; z-index:1200; display:flex; align-items:center; justify-content:center; padding:18px; background:rgba(7,27,58,.42); }
    .mp-modal-backdrop.hidden { display:none; }
    .mp-modal-panel { width:min(460px, 100%); border:1px solid #d8e4f2; border-radius:18px; background:#fff; box-shadow:0 24px 70px rgba(7,27,58,.25); overflow:hidden; }
    .mp-modal-head { padding:18px 20px; border-bottom:1px solid #e6edf5; }
    .mp-modal-head h3 { margin:0; color:#071b3a; font-size:19px; font-weight:900; }
    .mp-modal-head p { margin:5px 0 0; color:#657792; font-size:14px; line-height:1.45; }
    .mp-modal-body { padding:18px 20px; color:#334763; font-size:14px; line-height:1.5; }
    .mp-modal-warning { display:flex; gap:10px; align-items:flex-start; border:1px solid #fed7aa; border-radius:14px; background:#fff7ed; color:#9a3412; padding:12px 14px; font-weight:800; }
    .mp-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e6edf5; background:#fbfdff; }
    .mp-form-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
    .mp-checkbox { width:18px; height:18px; accent-color:#18c7b5; margin-top:2px; }
    .mp-table-wrap { overflow-x:auto; }
    .mp-table { width:100%; border-collapse:collapse; min-width:860px; }
    .mp-table th, .mp-table td { padding:12px; border-bottom:1px solid #e6edf5; text-align:left; vertical-align:top; }
    .mp-table th { background:#f8fbff; color:#334763; font-size:12px; text-transform:uppercase; }
    @media (max-width:1300px) { .mp-summary { grid-template-columns:repeat(4,minmax(0,1fr)); } }
    @media (max-width:1100px) {
        .mp-filter-grid, .mp-form-grid, .mp-operative-grid, .mp-groups-layout { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .mp-people-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:720px) {
        .mp-page-head, .mp-card-head, .mp-group-head, .mp-operative-head { flex-direction:column; }
        .mp-filter-grid, .mp-form-grid, .mp-summary, .mp-operative-grid, .mp-groups-layout, .mp-people-grid { grid-template-columns:1fr; }
        .mp-card-body, .mp-card-head { padding:16px; }
    }
</style>
@endpush

@section('content')
<div class="mp-shell">
    <div class="mp-page-head">
        <div>
            <h1 class="mp-page-title">Man Power</h1>
            <p class="mp-page-subtitle">Distribucion diaria por plan operativo, grupo, paradero y responsable de lista.</p>
        </div>
        <div class="mp-actions">
            <a href="{{ route('man-power.index') }}" class="mp-btn">Volver a paradas</a>
            @if($canViewMiAsistencia)
                <a href="{{ route('mi-asistencia.index') }}" class="mp-btn">Mi asistencia</a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="mp-alert mp-alert-ok">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="mp-alert mp-alert-error">{{ $errors->first() }}</div>
    @endif

    <section class="mp-card">
        <div class="mp-card-head">
            <div>
                <h2 class="mp-card-title">Contexto de planificacion</h2>
                <p class="mp-card-subtitle">La vista se actualiza automaticamente al cambiar parada, plan, fecha o turno.</p>
            </div>
            @if($plan)
                <span class="mp-chip {{ ($plan['archivado'] ?? false) ? 'mp-chip-warn' : 'mp-chip-ok' }}">{{ $plan['codigo'] }} - {{ $plan['estado'] }}</span>
            @endif
        </div>
        <div class="mp-card-body">
            <form method="GET" action="{{ route('man-power.grupos') }}" class="mp-filter-grid" id="mpWorkFilter">
                <div class="mp-field">
                    <label for="mpParadaSearch">Parada / destino</label>
                    <input type="hidden" id="rq_mina_id" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
                    <input class="mp-input" id="mpParadaSearch" name="q" type="search" list="mpParadasList" value="{{ $selectedParadaLabel }}" placeholder="Ej. AQP, C2, Taller Bustamante, Boroo" autocomplete="off">
                    <datalist id="mpParadasList">
                        @foreach($paradaOptions as $option)
                            <option value="{{ $option['label'] }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="mp-field">
                    <label for="plan_id">Plan operativo</label>
                    <select class="mp-select js-auto-filter" id="plan_id" name="plan_id">
                        <option value="">Sin plan / legacy</option>
                        @foreach($plans as $item)
                            <option value="{{ $item['id'] }}" @selected($selectedPlanId === $item['id'])>{{ $item['codigo'] }} - {{ $item['nombre'] }} ({{ $item['estado'] }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="mp-field">
                    <label for="fecha">Dia de trabajo</label>
                    <input class="mp-input js-auto-filter" id="fecha" name="fecha" type="date" value="{{ $fecha }}" @if($dateMin) min="{{ $dateMin }}" @endif @if($dateMax) max="{{ $dateMax }}" @endif>
                </div>
                <div class="mp-field">
                    <label for="turno">Turno</label>
                    <select class="mp-select js-auto-filter" id="turno" name="turno">
                        <option value="DIA" @selected($turno === 'DIA')>Dia</option>
                        <option value="NOCHE" @selected($turno === 'NOCHE')>Noche</option>
                    </select>
                </div>
                <div class="mp-actions">
                    <button class="mp-btn" id="mpClearFilters" type="button">Limpiar</button>
                </div>
            </form>
        </div>
    </section>

    <div class="mp-summary">
        <div class="mp-summary-item"><span class="mp-summary-label">Aprobados RR. HH.</span><span class="mp-summary-value">{{ $summary['total_aprobado_activo'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Disponibles</span><span class="mp-summary-value">{{ $summary['total_disponible'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Distribuidos</span><span class="mp-summary-value">{{ $summary['total_distribuido'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Requeridos</span><span class="mp-summary-value">{{ $summary['requeridos_por_plan'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Brecha</span><span class="mp-summary-value">{{ $summary['brecha'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Titulares</span><span class="mp-summary-value">{{ $summary['titulares_distribuidos'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Suplentes</span><span class="mp-summary-value">{{ $summary['suplentes_distribuidos'] ?? 0 }}</span></div>
        <div class="mp-summary-item"><span class="mp-summary-label">Adicionales</span><span class="mp-summary-value">{{ $summary['adicionales_distribuidos'] ?? 0 }}</span></div>
    </div>

    @if(!$parada)
        <section class="mp-card">
            <div class="mp-card-body">
                <div class="mp-empty">No hay paradas atendidas por RQ Proserge para el alcance seleccionado.</div>
            </div>
        </section>
    @else
        <section class="mp-card">
            <div class="mp-card-head">
                <div>
                    <h2 class="mp-card-title">Grupos operativos del plan</h2>
                    <p class="mp-card-subtitle">{{ $parada['mina_nombre'] ?? 'Sin mina' }} - {{ $parada['area'] ?? 'Sin area' }} - {{ $fecha }} / {{ $turno }}</p>
                </div>
                <span class="mp-chip">{{ $operationalGroups->count() }} grupo(s)</span>
            </div>
            <div class="mp-card-body">
                @if($operationalGroups->isEmpty())
                    <div class="mp-empty">Este plan no tiene grupos operativos para mostrar. Los grupos legacy siguen visibles mas abajo.</div>
                @else
                    <div class="mp-operative-grid">
                        @foreach($operationalGroups as $opGroup)
                            @php
                                $createdForOp = $groups->where('rq_mina_actividad_grupo_id', $opGroup['id']);
                                $assigned = $createdForOp->sum('asignado');
                                $required = (int) ($opGroup['requerido'] ?? 0);
                                $percent = $required > 0 ? min(100, round(($assigned / $required) * 100)) : 0;
                            @endphp
                            <article class="mp-operative-card">
                                <div class="mp-operative-head">
                                    <div>
                                        <h3 class="mp-operative-title">{{ $opGroup['nombre'] }}</h3>
                                        <p class="mp-meta">{{ $opGroup['area'] ?? 'Sin area' }} - {{ $opGroup['modulo'] ?? 'Sin modulo' }}</p>
                                    </div>
                                    <span class="mp-chip {{ $assigned >= $required && $required > 0 ? 'mp-chip-ok' : 'mp-chip-warn' }}">{{ $assigned }}/{{ $required }}</span>
                                </div>
                                <div class="mp-progress"><span style="width: {{ $percent }}%;"></span></div>
                                <p class="mp-meta">Supervisor operativo: {{ $opGroup['supervisor_operativo'] ?: 'No definido' }}</p>
                                <p class="mp-meta">Supervisor seguridad: {{ $opGroup['supervisor_seguridad'] ?: 'No definido' }}</p>
                                <div class="mp-activity-list">
                                    @foreach(collect($opGroup['actividades'] ?? [])->take(4) as $activity)
                                        <div class="mp-activity-row">
                                            <span>{{ $activity['sait'] ?: $activity['sector'] ?: 'Actividad' }}</span>
                                            <strong>{{ $activity['cantidad_planificada'] }}</strong>
                                        </div>
                                    @endforeach
                                </div>
                                @if($canCreateManPowerGroup && !($plan['archivado'] ?? false))
                                    <button class="mp-btn mp-btn-soft js-manage-group" type="button" data-group-id="{{ $opGroup['id'] }}">Gestionar grupo</button>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        @if($canCreateManPowerGroup && !($plan['archivado'] ?? false))
            <section class="mp-card" id="mpCreateCard">
                <div class="mp-card-head">
                    <div>
                        <h2 class="mp-card-title">Nuevo grupo diario</h2>
                        <p class="mp-card-subtitle">Selecciona el grupo operativo, actividades, responsable y trabajadores disponibles.</p>
                    </div>
                    <span class="mp-chip">{{ $defaultDestinoNombre ?: 'Destino por definir' }}</span>
                </div>
                <div class="mp-card-body">
                    <form method="POST" action="{{ route('man-power.guardar-grupo') }}" id="mpGroupForm">
                        @csrf
                        <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
                        <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlanId }}">
                        <input type="hidden" name="fecha" value="{{ $fecha }}">
                        <input type="hidden" name="turno" value="{{ $turno }}">
                        <input type="hidden" name="destino_tipo" value="{{ $defaultDestinoTipo }}">
                        <input type="hidden" name="destino_id" value="{{ $defaultDestinoId }}">

                        <div class="mp-form-grid">
                            <div class="mp-field">
                                <label for="rq_mina_actividad_grupo_id">Grupo operativo</label>
                                <select class="mp-select" id="rq_mina_actividad_grupo_id" name="rq_mina_actividad_grupo_id">
                                    <option value="">Grupo legacy / libre</option>
                                    @foreach($operationalGroups as $opGroup)
                                        <option value="{{ $opGroup['id'] }}">{{ $opGroup['nombre'] }} - Req. {{ $opGroup['requerido'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mp-field">
                                <label for="servicio">Nombre del servicio</label>
                                <input class="mp-input" id="servicio" name="servicio" value="{{ old('servicio', $parada['area'] ?? '') }}" placeholder="Ej. Soporte almacen mina" required>
                            </div>
                            <div class="mp-field">
                                <label for="area">Area</label>
                                <input class="mp-input" id="area" name="area" value="{{ old('area', $parada['area'] ?? '') }}" placeholder="Ej. C2, AQP, ALM" required>
                            </div>
                            <div class="mp-field">
                                <label for="paradero">Paradero / destino</label>
                                <input class="mp-input" id="paradero" name="paradero" value="{{ old('paradero') }}" placeholder="Ej. Tacna y Arica, Taller Bustamante">
                            </div>
                            <div class="mp-field">
                                <label for="horario_salida">Horario</label>
                                <input class="mp-input" id="horario_salida" name="horario_salida" type="time" value="{{ old('horario_salida', '07:00') }}" required>
                            </div>
                            <div class="mp-field">
                                <label for="supervisor_id">Responsable de lista</label>
                                <select class="mp-select" id="supervisor_id" name="supervisor_id" required>
                                    <option value="">Seleccionar responsable</option>
                                    @foreach($availableAssignments as $assignment)
                                        <option value="{{ $assignment['personal_id'] }}" @selected(old('supervisor_id') === $assignment['personal_id'])>
                                            {{ $assignment['trabajador'] }} - DNI {{ $assignment['dni'] ?: '-' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mp-field">
                                <label for="estado">Estado</label>
                                <select class="mp-select" id="estado" name="estado">
                                    <option value="BORRADOR">Borrador</option>
                                    <option value="PROGRAMADO">Programado</option>
                                </select>
                            </div>
                            <div class="mp-field">
                                <label for="justificacion_brecha">Justificacion de brecha</label>
                                <input class="mp-input" id="justificacion_brecha" name="justificacion_brecha" value="{{ old('justificacion_brecha') }}" placeholder="Solo si faltan integrantes">
                            </div>
                            <div class="mp-field" style="grid-column:span 4;">
                                <label for="observaciones">Observaciones</label>
                                <textarea class="mp-textarea" id="observaciones" name="observaciones" placeholder="Indicaciones de movilidad, punto de encuentro o nota operativa">{{ old('observaciones') }}</textarea>
                            </div>
                        </div>

                        <div class="mp-people-toolbar">
                            <div>
                                <h3 class="mp-card-title" style="font-size:16px;">Personal disponible de RQ Proserge</h3>
                                <p class="mp-card-subtitle">Solo aparecen asignaciones activas, vigentes para la fecha y no distribuidas en el mismo turno.</p>
                            </div>
                            <input class="mp-input mp-people-search" id="mpPeopleSearch" type="search" placeholder="Buscar por trabajador, DNI o puesto">
                        </div>

                        @if($availableAssignments->isEmpty())
                            <div class="mp-empty">No hay asignaciones disponibles para distribuir en esta fecha y turno.</div>
                        @else
                            <div class="mp-people-grid" id="mpPeopleGrid">
                                @foreach($availableAssignments as $assignment)
                                    <label
                                        class="mp-person js-mp-draggable-person"
                                        data-search="{{ strtolower(($assignment['trabajador'] ?? '').' '.($assignment['dni'] ?? '').' '.($assignment['puesto_asignado'] ?? '').' '.($assignment['cargo_solicitado'] ?? '')) }}"
                                        data-assignment-id="{{ $assignment['rq_proserge_detalle_id'] }}"
                                        data-worker-name="{{ $assignment['trabajador'] }}"
                                        draggable="{{ $canAssignManPower ? 'true' : 'false' }}"
                                    >
                                        <input class="mp-checkbox js-person-checkbox" type="checkbox" name="rq_proserge_detalle_ids[]" value="{{ $assignment['rq_proserge_detalle_id'] }}">
                                        <span class="mp-person-main">
                                            <span class="mp-person-name">{{ $assignment['trabajador'] }}</span>
                                            <span class="mp-person-meta">DNI {{ $assignment['dni'] ?: '-' }} - {{ $assignment['puesto_asignado'] ?? 'Sin puesto' }}</span>
                                            <span class="mp-person-meta">
                                                <span class="mp-chip">{{ $assignment['posicion'] ?: 'SIN CLASIFICAR' }}</span>
                                                <span class="mp-chip {{ $assignment['tipo'] === 'ADICIONAL' ? 'mp-chip-warn' : 'mp-chip-neutral' }}">{{ $assignment['tipo'] ?: 'SIN TIPO' }}</span>
                                            </span>
                                            <span class="mp-person-meta">Rango: {{ $assignment['fecha_inicio'] }} a {{ $assignment['fecha_fin'] }}</span>
                                        </span>
                                        <span class="mp-person-actions">
                                            <button class="mp-mini-btn js-set-supervisor" type="button" data-id="{{ $assignment['personal_id'] }}">Responsable</button>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        <div class="mp-form-footer">
                            <button class="mp-btn" type="reset">Quitar seleccion</button>
                            <button class="mp-btn mp-btn-primary" type="submit">Crear grupo</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif

        <section class="mp-card">
            <div class="mp-card-head">
                <div>
                    <h2 class="mp-card-title">Grupos diarios creados</h2>
                    <p class="mp-card-subtitle">Distribuciones por plan, grupo operativo, paradero y responsable.</p>
                </div>
                <span class="mp-chip">{{ $groups->count() }} grupo(s)</span>
            </div>
            <div class="mp-card-body">
                @if($groups->isEmpty())
                    <div class="mp-empty">Todavia no hay grupos para esta fecha, turno y parada.</div>
                @else
                    <div class="mp-groups-layout">
                        @foreach($groups as $grupo)
                            @php
                                $personal = collect($grupo['detalle'] ?? []);
                                $activePersonal = $personal->where('estado_distribucion', 'ASIGNADO');
                                $attendanceStatus = $grupo['asistencia']['estado'] ?? 'PENDIENTE';
                            @endphp
                            <article
                                class="mp-group js-mp-group-dropzone"
                                data-group-id="{{ $grupo['id'] }}"
                                data-add-url="{{ route('man-power.agregar-personal', $grupo['id']) }}"
                            >
                                <div class="mp-group-head">
                                    <div>
                                        <h3 class="mp-group-title">{{ $grupo['nombre_snapshot'] ?: ($grupo['servicio'] ?? 'Grupo de trabajo') }}</h3>
                                        <p class="mp-meta">{{ $grupo['area'] ?? 'Sin area' }} - {{ $grupo['paradero'] ?: 'Sin paradero' }} - {{ $grupo['horario_salida'] ?? '-' }}</p>
                                        @if($grupo['legacy'] ?? false)
                                            <span class="mp-chip mp-chip-warn">Grupo legacy sin trazabilidad completa</span>
                                        @endif
                                    </div>
                                    <span class="mp-chip {{ ($grupo['brecha'] ?? 0) > 0 ? 'mp-chip-warn' : 'mp-chip-ok' }}">
                                        {{ $grupo['asignado'] ?? 0 }}/{{ $grupo['requerido'] ?? 0 }}
                                    </span>
                                </div>
                                <div class="mp-group-body">
                                    <p class="mp-meta">Responsable: {{ $grupo['supervisor']['nombre_completo'] ?? 'Sin responsable' }}</p>
                                    <p class="mp-meta">Asistencia: {{ $attendanceStatus }}</p>
                                    <div class="mp-actions" style="margin:12px 0;">
                                        <a class="mp-btn mp-btn-soft" href="{{ route('man-power.grupo-detalle', $grupo['id']) }}">Ver detalle</a>
                                        @if($canViewTransportes)
                                            <a class="mp-btn" href="{{ route('transporte.planificacion', ['rq_mina_id' => $selectedRqMinaId, 'rq_mina_plan_id' => $selectedPlanId, 'fecha' => $fecha, 'turno' => $turno]) }}">Gestionar transporte</a>
                                        @endif
                                        @if($canRegisterAttendance)
                                            <a class="mp-btn mp-btn-primary" href="{{ route('asistencia.marcar', $grupo['id']) }}">Tomar lista</a>
                                        @endif
                                        @if($canDuplicateManPower)
                                            <form method="POST" action="{{ route('man-power.copiar-grupo', $grupo['id']) }}" class="js-copy-form">
                                                @csrf
                                                <input type="hidden" name="fecha_destino" value="{{ \Illuminate\Support\Carbon::parse($fecha)->addDay()->toDateString() }}">
                                                <input type="hidden" name="turno_destino" value="{{ $turno }}">
                                                <input type="hidden" name="copiar_integrantes" value="1">
                                                <button class="mp-btn" type="submit">Copiar a manana</button>
                                            </form>
                                        @endif
                                    </div>
                                    <div class="mp-person-list">
                                        @forelse($personal as $detalle)
                                            @php $persona = $detalle['personal'] ?? []; @endphp
                                            <div class="mp-person-row">
                                                <div>
                                                    <strong>{{ $persona['nombre_completo'] ?? 'Trabajador' }}</strong>
                                                    <p class="mp-meta">
                                                        {{ $detalle['puesto_asignado_snapshot'] ?: ($persona['puesto'] ?? 'Sin puesto') }}
                                                        - {{ $detalle['posicion_asignacion_snapshot'] ?: 'SIN CLASIFICAR' }}
                                                        - {{ $detalle['tipo_asignacion_snapshot'] ?: 'SIN TIPO' }}
                                                    </p>
                                                    @if(($detalle['estado_distribucion'] ?? 'ASIGNADO') !== 'ASIGNADO')
                                                        <span class="mp-chip mp-chip-neutral">{{ $detalle['estado_distribucion'] }}</span>
                                                    @endif
                                                </div>
                                                @if($canAssignManPower && ($detalle['estado_distribucion'] ?? 'ASIGNADO') === 'ASIGNADO')
                                                    <form method="POST" action="{{ route('man-power.retirar-personal', [$grupo['id'], $detalle['id']]) }}" class="js-retire-form">
                                                        @csrf
                                                        <input type="hidden" name="motivo" value="Retiro operativo desde Man Power">
                                                        <button class="mp-mini-btn mp-btn-danger" type="submit">Retirar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @empty
                                            <div class="mp-empty">Grupo sin integrantes.</div>
                                        @endforelse
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="mp-card">
            <div class="mp-card-head">
                <div>
                    <h2 class="mp-card-title">Personal pendiente</h2>
                    <p class="mp-card-subtitle">Asignaciones aprobadas que aun no tienen grupo para esta fecha y turno.</p>
                </div>
                <span class="mp-chip">{{ $pendingAssignments->count() }} pendiente(s)</span>
            </div>
            <div class="mp-card-body">
                @if($pendingAssignments->isEmpty())
                    <div class="mp-empty">No queda personal pendiente para distribuir.</div>
                @else
                    <div class="mp-table-wrap">
                        <table class="mp-table">
                            <thead>
                                <tr>
                                    <th>Trabajador</th>
                                    <th>DNI</th>
                                    <th>Cargo solicitado</th>
                                    <th>Puesto asignado</th>
                                    <th>Posicion</th>
                                    <th>Tipo</th>
                                    <th>Rango</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pendingAssignments as $assignment)
                                    <tr>
                                        <td><strong>{{ $assignment['trabajador'] }}</strong></td>
                                        <td>{{ $assignment['dni'] ?: '-' }}</td>
                                        <td>{{ $assignment['cargo_solicitado'] ?: '-' }}</td>
                                        <td>{{ $assignment['puesto_asignado'] ?: '-' }}</td>
                                        <td>{{ $assignment['posicion'] ?: 'SIN CLASIFICAR' }}</td>
                                        <td>{{ $assignment['tipo'] ?: 'SIN TIPO' }}</td>
                                        <td>{{ $assignment['fecha_inicio'] }} a {{ $assignment['fecha_fin'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        @if($legacyGroups->isNotEmpty())
            <section class="mp-card">
                <div class="mp-card-head">
                    <div>
                        <h2 class="mp-card-title">Historicos sin trazabilidad completa</h2>
                        <p class="mp-card-subtitle">Siguen visibles, pero deben regularizarse con el backfill cuando exista coincidencia unica.</p>
                    </div>
                    <span class="mp-chip mp-chip-warn">{{ $legacyGroups->count() }} legacy</span>
                </div>
            </section>
        @endif
    @endif
</div>
<div class="mp-toast-stack" id="mpToastStack" aria-live="polite" aria-atomic="true"></div>
<div class="mp-modal-backdrop hidden" id="mpRetireModal" aria-hidden="true">
    <div class="mp-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mpRetireModalTitle">
        <div class="mp-modal-head">
            <h3 id="mpRetireModalTitle">Retirar integrante</h3>
            <p>Esta accion retirara al trabajador del grupo diario, pero conservara el historial.</p>
        </div>
        <div class="mp-modal-body">
            <div class="mp-modal-warning">
                <span>!</span>
                <span id="mpRetireModalText">Confirma que deseas retirar a este integrante del grupo.</span>
            </div>
        </div>
        <div class="mp-modal-footer">
            <button class="mp-btn" type="button" data-retire-cancel>Cancelar</button>
            <button class="mp-btn mp-btn-danger" type="button" data-retire-confirm>Retirar</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const filterForm = document.getElementById('mpWorkFilter');
    const paradaSearch = document.getElementById('mpParadaSearch');
    const rqMinaInput = document.getElementById('rq_mina_id');
    const paradaOptions = @json($paradaOptions);
    const cleanUrl = @json(route('man-power.grupos'));
    const peopleSearch = document.getElementById('mpPeopleSearch');
    const peopleGrid = document.getElementById('mpPeopleGrid');
    const supervisorSelect = document.getElementById('supervisor_id');
    const clearFiltersButton = document.getElementById('mpClearFilters');
    const groupSelect = document.getElementById('rq_mina_actividad_grupo_id');
    const toastStack = document.getElementById('mpToastStack');
    const retireModal = document.getElementById('mpRetireModal');
    const retireModalText = document.getElementById('mpRetireModalText');
    const retireConfirmButton = retireModal?.querySelector('[data-retire-confirm]');
    const retireCancelButton = retireModal?.querySelector('[data-retire-cancel]');
    const csrfToken = @json(csrf_token());
    let filterTimer = null;
    let draggedPerson = null;
    let pendingRetireForm = null;

    function submitFilters(delay) {
        if (!filterForm) {
            return;
        }

        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(function () {
            filterForm.submit();
        }, delay);
    }

    if (filterForm) {
        filterForm.querySelectorAll('.js-auto-filter').forEach(function (field) {
            field.addEventListener('change', function () {
                submitFilters(0);
            });
        });
    }

    if (paradaSearch && rqMinaInput) {
        paradaSearch.addEventListener('input', function () {
            const value = paradaSearch.value.trim().toLowerCase();
            const exact = paradaOptions.find(function (option) {
                return String(option.label || '').trim().toLowerCase() === value;
            });

            rqMinaInput.value = exact ? exact.id : '';
            submitFilters(exact ? 0 : 500);
        });
    }

    if (clearFiltersButton) {
        clearFiltersButton.addEventListener('click', function () {
            window.clearTimeout(filterTimer);
            window.location.assign(cleanUrl);
        });
    }

    if (peopleSearch && peopleGrid) {
        peopleSearch.addEventListener('input', function () {
            const term = peopleSearch.value.trim().toLowerCase();
            peopleGrid.querySelectorAll('.mp-person').forEach(function (item) {
                const text = item.dataset.search || '';
                item.style.display = text.includes(term) ? 'grid' : 'none';
            });
        });
    }

    document.querySelectorAll('.js-set-supervisor').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = button.dataset.id;
            if (!id || !supervisorSelect) {
                return;
            }

            supervisorSelect.value = id;
            const card = button.closest('.mp-person');
            const checkbox = card ? card.querySelector('.js-person-checkbox') : null;
            if (checkbox && !checkbox.disabled) {
                checkbox.checked = true;
            }

            document.querySelectorAll('.js-set-supervisor').forEach(function (item) {
                item.classList.remove('is-supervisor');
            });
            button.classList.add('is-supervisor');
        });
    });

    document.querySelectorAll('.js-manage-group').forEach(function (button) {
        button.addEventListener('click', function () {
            if (groupSelect) {
                groupSelect.value = button.dataset.groupId || '';
            }

            const card = document.getElementById('mpCreateCard');
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    function showToast(message, type, detail) {
        if (!toastStack || !message) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = `mp-toast is-${type || 'info'}`;
        toast.innerHTML = `${message}${detail ? `<small>${detail}</small>` : ''}`;
        toastStack.appendChild(toast);

        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, type === 'error' ? 4200 : 2400);
    }

    document.querySelectorAll('.js-mp-draggable-person[draggable="true"]').forEach(function (card) {
        card.addEventListener('dragstart', function (event) {
            draggedPerson = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', card.dataset.assignmentId || '');
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');
            draggedPerson = null;
            document.querySelectorAll('.js-mp-group-dropzone').forEach(function (group) {
                group.classList.remove('is-drop-ready', 'is-drop-error');
            });
        });
    });

    document.querySelectorAll('.js-mp-group-dropzone').forEach(function (group) {
        group.addEventListener('dragover', function (event) {
            if (!draggedPerson || !group.dataset.addUrl) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
            group.classList.add('is-drop-ready');
        });

        group.addEventListener('dragleave', function (event) {
            if (!group.contains(event.relatedTarget)) {
                group.classList.remove('is-drop-ready');
            }
        });

        group.addEventListener('drop', async function (event) {
            event.preventDefault();
            group.classList.remove('is-drop-ready');

            const assignmentId = event.dataTransfer.getData('text/plain') || draggedPerson?.dataset.assignmentId || '';
            if (!assignmentId || !group.dataset.addUrl) {
                group.classList.add('is-drop-error');
                showToast('No se pudo identificar al trabajador seleccionado.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('rq_proserge_detalle_id', assignmentId);
            showToast('Agregando personal al grupo...', 'info', draggedPerson?.dataset.workerName || '');

            try {
                const response = await fetch(group.dataset.addUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: formData,
                });
                const data = await response.json().catch(function () {
                    return {};
                });

                if (!response.ok || data.ok === false) {
                    throw new Error(data.message || 'No se pudo agregar personal al grupo.');
                }

                showToast('Personal agregado al grupo.', 'ok', 'Actualizando lista...');
                window.setTimeout(function () {
                    window.location.reload();
                }, 450);
            } catch (error) {
                group.classList.add('is-drop-error');
                showToast(error.message || 'No se pudo agregar personal al grupo.', 'error');
            }
        });
    });

    function closeRetireModal() {
        retireModal?.classList.add('hidden');
        retireModal?.setAttribute('aria-hidden', 'true');
        pendingRetireForm = null;
    }

    function openRetireModal(form) {
        pendingRetireForm = form;
        const row = form.closest('.mp-person-row');
        const workerName = row?.querySelector('strong')?.textContent?.trim() || 'este integrante';

        if (retireModalText) {
            retireModalText.textContent = `Confirma que deseas retirar a ${workerName} de este grupo diario.`;
        }

        retireModal?.classList.remove('hidden');
        retireModal?.setAttribute('aria-hidden', 'false');
        window.setTimeout(function () {
            retireConfirmButton?.focus();
        }, 0);
    }

    document.querySelectorAll('.js-retire-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();
            openRetireModal(form);
        });
    });

    retireCancelButton?.addEventListener('click', closeRetireModal);
    retireModal?.addEventListener('click', function (event) {
        if (event.target === retireModal) {
            closeRetireModal();
        }
    });

    retireConfirmButton?.addEventListener('click', function () {
        if (!pendingRetireForm) {
            closeRetireModal();
            return;
        }

        pendingRetireForm.dataset.confirmed = '1';
        pendingRetireForm.submit();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && retireModal && !retireModal.classList.contains('hidden')) {
            closeRetireModal();
        }
    });
})();
</script>
@endpush
