@extends('layouts.app')

@section('title', 'Transporte operativo - Proserge')

@php
    use App\Support\Rbac\PermissionMatrix;

    $permissions = session('user.permissions', []);
    $selected = $selected ?? [];
    $summary = $resumen ?? [];
    $selectedRq = $selected['rq_mina_id'] ?? '';
    $selectedPlan = $selected['rq_mina_plan_id'] ?? '';
    $selectedFecha = $selected['fecha'] ?? now()->addDay()->toDateString();
    $selectedTurno = $selected['turno'] ?? 'A';
    $paradas = collect($paradas ?? []);
    $plans = collect($plans ?? []);
    $lista = $lista ?? [];
    $canReturnToPlan = $selectedRq !== '' && $selectedPlan !== ''
        && ($rq_mina['id'] ?? null) === $selectedRq
        && ($plan['id'] ?? null) === $selectedPlan
        && request()->query('rq_mina_id') === $selectedRq
        && request()->query('rq_mina_plan_id') === $selectedPlan
        && PermissionMatrix::allowsDirect($permissions, 'rq_mina', 'ver')
        && PermissionMatrix::allowsDirect($permissions, 'rq_mina', 'editar');
    $requirements = collect($requerimientos ?? []);
    $services = collect($servicios ?? []);
    $groups = collect($grupos_man_power ?? []);
    $availableDetails = $groups->flatMap(fn (array $group) => $group['integrantes'] ?? []);
    $transportedByLeg = $services->groupBy('tramo')->map(
        fn ($legServices) => $legServices->flatMap(fn (array $service) => $service['pasajeros'] ?? [])
            ->where('estado', 'ASIGNADO')->pluck('personal_id')->unique()
    );
    $drivers = collect($conductores ?? []);
    $formContext = [...$selected, 'lista' => $lista];
    $missingLabels = [
        'placa' => 'Placa', 'conductor' => 'Conductor', 'capacidad' => 'Capacidad',
        'alcance' => 'Alcance', 'sobreocupacion' => 'Resolver la sobreocupación',
    ];
@endphp

@push('styles')
<style>
    .tr-shell { color:#071b3a; }
    .tr-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
    .tr-title { margin:0; font-size:28px; font-weight:800; }
    .tr-subtitle { margin:4px 0 0; color:#5d708c; }
    .tr-card { background:#fff; border:1px solid #d8e4f2; border-radius:16px; box-shadow:0 10px 24px rgba(7,27,58,.06); margin-bottom:16px; }
    .tr-card-head { padding:18px 22px; border-bottom:1px solid #e7eef7; display:flex; justify-content:space-between; gap:14px; align-items:flex-start; }
    .tr-card-body { padding:20px 22px; }
    .tr-card-title { margin:0; font-size:18px; font-weight:800; }
    .tr-card-subtitle { margin:5px 0 0; color:#657792; font-size:14px; }
    .tr-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
    .tr-filter-grid { display:grid; grid-template-columns:minmax(260px,1.4fr) minmax(220px,1fr) 160px 150px auto; gap:14px; align-items:end; }
    .tr-field { display:flex; flex-direction:column; gap:7px; }
    .tr-field label { color:#334763; font-size:12px; font-weight:800; text-transform:uppercase; }
    .tr-input, .tr-select, .tr-textarea { width:100%; border:1px solid #cddbef; border-radius:12px; padding:12px 14px; min-height:46px; color:#071b3a; background:#fff; font:inherit; }
    .tr-textarea { min-height:76px; resize:vertical; }
    .tr-input:focus, .tr-select:focus, .tr-textarea:focus { outline:none; border-color:#18c7b5; box-shadow:0 0 0 3px rgba(24,199,181,.14); }
    .tr-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .tr-btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; border-radius:12px; border:1px solid #cddbef; padding:10px 14px; color:#071b3a; background:#fff; font-weight:800; text-decoration:none; cursor:pointer; }
    .tr-btn-primary { border-color:#087568; background:#087568; color:#fff; }
    .tr-btn-soft { background:#eefdfa; border-color:#8ee8dd; color:#007f76; }
    .tr-btn-danger { color:#b42318; border-color:#ffc9c4; background:#fff7f6; }
    .tr-summary { display:grid; grid-template-columns:repeat(8,minmax(0,1fr)); gap:12px; }
    .tr-summary-item { border:1px solid #d8e4f2; border-radius:14px; padding:14px 16px; background:#fff; }
    .tr-summary-item span { display:block; color:#657792; font-size:13px; }
    .tr-summary-item strong { display:block; margin-top:6px; font-size:24px; }
    .tr-table-wrap { overflow-x:auto; }
    .tr-table { width:100%; border-collapse:collapse; min-width:1050px; }
    .tr-table th { background:#f5f8fc; color:#4a5d78; font-size:12px; text-align:left; padding:13px; text-transform:uppercase; }
    .tr-table td { border-top:1px solid #e2eaf4; padding:14px 13px; vertical-align:top; }
    .tr-badge { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; background:#edf5ff; color:#1d4f91; font-size:12px; font-weight:800; }
    .tr-badge-warn { background:#fff4d8; color:#8a5200; }
    .tr-badge-ok { background:#dcfce7; color:#166534; }
    .tr-muted { color:#657792; font-size:13px; }
    .tr-mini-form { display:grid; gap:8px; min-width:220px; }
    .tr-empty { padding:22px; color:#657792; text-align:center; }
    .tr-table-wrap, .tr-field { min-width:0; }
    .tr-shell .tr-card { scroll-margin-top:88px; }
    .tr-form-notes { grid-column:1/-1; }
    .tr-form-actions { margin-top:14px; }
    .tr-form-error { margin:0 0 12px; padding:10px; border:1px solid #dc8c88; border-radius:8px; color:#8e231e; background:#fff7f6; }
    .tr-form-error[data-success] { border-color:#85c4a4; color:#145635; background:#effaf3; }
    .tr-form-error:focus, .tr-btn:focus-visible { outline:2px solid #1d4f91; outline-offset:2px; }
    .tr-btn:disabled { opacity:.7; cursor:wait; }
    .tr-gaps { margin:0; padding-left:18px; }
    @media (max-width:1100px) {
        .tr-summary { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .tr-filter-grid, .tr-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:720px) {
        .tr-head, .tr-card-head { flex-direction:column; }
        .tr-summary { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .tr-filter-grid, .tr-grid { grid-template-columns:minmax(0,1fr); }
        .tr-services-table { min-width:0; display:block; }
        .tr-services-table thead { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
        .tr-services-table tbody, .tr-services-table tr, .tr-services-table td { display:block; }
        .tr-services-table tr { padding:10px 16px; border-top:1px solid #d8e4f2; }
        .tr-services-table td { padding:6px 0; border:0; overflow-wrap:anywhere; }
        .tr-mini-form { min-width:0; }
    }
</style>
@endpush

@section('content')
<div class="tr-shell" data-transport-planning data-return-path="{{ route('transporte.planificacion', absolute: false) }}">
    <div class="tr-head">
        <div>
            <h1 class="tr-title">Transporte operativo</h1>
            <p class="tr-subtitle">Planificacion de unidades, pasajeros y brechas por parada, plan, fecha y turno.</p>
        </div>
        <div class="tr-actions">
            @if($canReturnToPlan)
                <a class="tr-btn tr-btn-soft" href="{{ route('rq-mina.plan', ['id' => $selectedRq, 'plan_id' => $selectedPlan, 'lista' => $lista]) }}">Volver al plan de la parada</a>
            @endif
            <a class="tr-btn" href="{{ route('logistica.index', ['tab' => 'servicios']) }}">Volver a Logística</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error') && !old('form_key'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif

    <section class="tr-card">
        <div class="tr-card-head">
            <div>
                <h2 class="tr-card-title">Contexto</h2>
                <p class="tr-card-subtitle">Los filtros se aplican al cargar la planificacion.</p>
            </div>
        </div>
        <div class="tr-card-body">
            <form method="GET" action="{{ route('transporte.planificacion') }}" class="tr-filter-grid">
                @foreach($lista as $key => $value)
                    @if(is_scalar($value))
                        <input type="hidden" name="lista[{{ $key }}]" value="{{ $value }}">
                    @endif
                @endforeach
                <label class="tr-field">
                    <span>Parada</span>
                    <select class="tr-select" name="rq_mina_id">
                        @foreach($paradas as $parada)
                            <option value="{{ $parada['id'] }}" @selected($selectedRq === $parada['id'])>
                                {{ $parada['mina_nombre'] ?? 'Sin mina' }} - {{ $parada['destino_nombre'] ?? 'Sin destino' }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="tr-field">
                    <span>Plan</span>
                    <select class="tr-select" name="rq_mina_plan_id">
                        <option value="">Plan disponible</option>
                        @foreach($plans as $planOption)
                            <option value="{{ $planOption['id'] }}" @selected($selectedPlan === $planOption['id'])>{{ $planOption['codigo'] }} - {{ $planOption['nombre'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="tr-field">
                    <span>Fecha</span>
                    <input class="tr-input" type="date" name="fecha" value="{{ $selectedFecha }}">
                </label>
                <label class="tr-field">
                    <span>Turno</span>
                    <select class="tr-select" name="turno">
                        <option value="A" @selected($selectedTurno === 'A')>A - Dia</option>
                        <option value="B" @selected($selectedTurno === 'B')>B - Noche</option>
                    </select>
                </label>
                <div class="tr-actions">
                    <button class="tr-btn tr-btn-primary" type="submit">Actualizar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="tr-summary">
        @foreach([
            'unidades_requeridas' => 'Unidades req.',
            'servicios_creados' => 'Servicios',
            'servicios_confirmados' => 'Confirmados',
            'capacidad_total' => 'Capacidad',
            'personas_distribuidas' => 'Distribuidos',
            'personas_con_transporte' => 'Con transporte',
            'personas_sin_transporte' => 'Pendientes',
            'sobreocupacion' => 'Sobreocupacion',
        ] as $key => $label)
            <article class="tr-summary-item">
                <span>{{ $label }}</span>
                <strong>{{ number_format((float) data_get($summary, $key, 0)) }}</strong>
            </article>
        @endforeach
    </section>

    @if(!empty($editService))
        <section class="tr-card" id="transporte-editar" aria-labelledby="transporte-editar-title">
            <div class="tr-card-head">
                <div>
                    <h2 class="tr-card-title" id="transporte-editar-title">Editar servicio</h2>
                    <p class="tr-card-subtitle">{{ $editService['placa'] ?: 'Sin placa' }} · {{ $editService['fecha'] }} · Turno {{ $editService['turno'] }}</p>
                </div>
            </div>
            <div class="tr-card-body">
                @include('transporte.partials.servicio-form', ['formKey' => $editService['id'], 'values' => $editService])
            </div>
        </section>
    @endif

    @if(!empty($can_create))
        <section class="tr-card" id="transporte-nuevo" aria-labelledby="transporte-nuevo-title">
            <div class="tr-card-head">
                <div>
                    <h2 class="tr-card-title" id="transporte-nuevo-title">Nuevo servicio</h2>
                    <p class="tr-card-subtitle">La unidad se guarda en borrador para esta fecha y turno. Completa los datos antes de confirmar.</p>
                </div>
            </div>
            <div class="tr-card-body">
                @include('transporte.partials.servicio-form', ['formKey' => 'nuevo', 'values' => []])
            </div>
        </section>
    @endif

    <section class="tr-card">
        <div class="tr-card-head">
            <div>
                <h2 class="tr-card-title">Requerimientos del plan</h2>
                <p class="tr-card-subtitle">RQ Mina conserva lo requerido; Logistica registra placa, documentos y recepcion. La ejecucion de pasajeros se gestiona como servicio.</p>
            </div>
            <div class="tr-actions">
                <span class="tr-badge">{{ $requirements->count() }} requerimientos</span>
                @if($selectedRq !== '')
                    <a class="tr-btn tr-btn-soft" href="{{ route('logistica.index', ['tab' => 'servicios', 'servicio_parada_id' => $selectedRq]) }}">Atender en Logistica</a>
                @endif
            </div>
        </div>
        <div class="tr-table-wrap">
            <table class="tr-table">
                <thead><tr><th>Grupo / SAIT</th><th>Tipo</th><th>Solicitado</th><th>Origen / destino</th><th>Estado</th><th>Atencion logistica</th><th>Compatibilidad</th></tr></thead>
                <tbody>
                    @forelse($requirements as $row)
                        <tr>
                            <td><strong>{{ $row['grupo_operativo'] ?: 'Sin grupo' }}</strong><br><span class="tr-muted">{{ $row['sait'] ?: $row['alcance'] ?: '-' }}</span></td>
                            <td>{{ $row['tipo'] }}</td>
                            <td>{{ $row['solicitado'] ?: '-' }}<br><span class="tr-muted">Unid: {{ $row['cantidad_unidades_requeridas'] ?: '-' }} · Cap: {{ $row['capacidad_requerida'] ?: '-' }}</span></td>
                            <td>{{ $row['origen'] ?: '-' }}<br><span class="tr-muted">{{ $row['destino'] ?: '-' }}</span></td>
                            <td><span class="tr-badge">{{ $row['estado_logistico_label'] ?? ($row['estado'] ?: 'Requerido') }}</span></td>
                            <td>
                                @if($row['atendido'])
                                    <strong>{{ $row['placas_asignadas'] ?: 'Sin placa registrada' }}</strong><br>
                                    <span class="tr-muted">{{ $row['origen_label'] }} · Recepcion: {{ $row['recepcion_label'] }}</span><br>
                                    <span class="tr-muted">{{ $row['fechas_uso'] !== '' ? $row['fechas_uso'] : 'Sin fechas de uso' }}</span>
                                @else
                                    <span class="tr-muted">Pendiente de atencion de Logistica</span>
                                @endif
                            </td>
                            <td>@if($row['legacy'])<span class="tr-badge tr-badge-warn">{{ $row['legacy_label'] }}</span>@else<span class="tr-badge tr-badge-ok">Estructurado</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="tr-empty">No hay requerimientos para el contexto seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tr-card" id="transporte-servicios">
        <div class="tr-card-head">
            <div>
                <h2 class="tr-card-title">Servicios asignados</h2>
                <p class="tr-card-subtitle">Capacidad, ocupacion, pasajeros y acciones por unidad.</p>
            </div>
            <span class="tr-badge">{{ $services->count() }} servicios</span>
        </div>
        <div class="tr-table-wrap">
            <table class="tr-table tr-services-table">
                <thead><tr><th>Servicio</th><th>Unidad</th><th>Capacidad</th><th>Alcances</th><th>Pasajeros</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse($services as $service)
                        @php
                            $metrics = $service['metricas'] ?? [];
                            $servicePassengers = collect($service['pasajeros'] ?? [])->where('estado', 'ASIGNADO');
                            $serviceGroupIds = collect($service['alcances'] ?? [])->pluck('grupo_trabajo_id')->filter();
                            $serviceCandidates = $availableDetails->whereIn('grupo_trabajo_id', $serviceGroupIds)
                                ->whereNotIn('personal_id', $transportedByLeg->get($service['tramo'], []))->unique('personal_id');
                            $capabilities = $service['capabilities'] ?? [];
                            $missing = $service['faltantes_confirmacion'] ?? [];
                        @endphp
                        <tr>
                            <td><strong>{{ $service['tipo'] }} · {{ $service['tramo'] }}</strong><br><span class="tr-muted">{{ $service['estado'] }} · {{ $service['fecha'] }} · Turno {{ $service['turno'] }}</span></td>
                            <td>{{ $service['placa'] ?: 'Sin placa' }}<br><span class="tr-muted">{{ $service['tipo_vehiculo'] ?: '-' }} · {{ $service['conductor'] ?: 'Sin conductor' }}</span></td>
                            <td><strong>{{ data_get($metrics, 'ocupacion', '-') }} / {{ data_get($metrics, 'capacidad', '-') }}</strong><br><span class="tr-muted">Disp: {{ data_get($metrics, 'asientos_disponibles', '-') }} · Sobre: {{ data_get($metrics, 'sobreocupacion', 0) }}</span></td>
                            <td>
                                @forelse($service['alcances'] as $alcance)
                                    <span class="tr-badge">{{ $alcance['grupo_man_power'] ?: ($alcance['grupo_operativo'] ?: ($alcance['sait_snapshot'] ?: 'Alcance')) }}</span>
                                @empty
                                    <span class="tr-badge tr-badge-warn">Sin alcance</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse($servicePassengers as $passenger)
                                    <div>{{ $passenger['trabajador'] }} <span class="tr-muted">{{ $passenger['dni'] }}</span></div>
                                @empty
                                    <span class="tr-muted">Sin pasajeros</span>
                                @endforelse
                            </td>
                            <td>
                                @if($missing && !in_array($service['estado'], ['CONFIRMADO', 'FINALIZADO', 'CANCELADO', 'ANULADO'], true) && empty($plan['archivado']))
                                    <p class="tr-muted"><strong>Falta para confirmar</strong></p>
                                    <ul class="tr-gaps">
                                        @foreach($missing as $item)
                                            <li>{{ $missingLabels[$item] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if(!empty($capabilities['editar']) || (!empty($capabilities['confirmar']) && !$missing) || !empty($capabilities['agregar_pasajeros']))
                                    <div class="tr-mini-form">
                                        @if(!empty($capabilities['editar']))
                                            <a class="tr-btn" href="{{ route('transporte.planificacion', [...$formContext, 'editar' => $service['id']]) }}#transporte-editar">Editar servicio</a>
                                        @endif
                                        @if(!empty($capabilities['confirmar']) && !$missing)
                                            <form method="POST" action="{{ route('transporte.servicios.estado', $service['id']) }}" data-transport-form>
                                                @csrf
                                                @include('transporte.partials.context-fields')
                                                <input type="hidden" name="estado" value="CONFIRMADO">
                                                <div class="tr-form-error" data-transport-error role="alert" tabindex="-1" hidden></div>
                                                <button class="tr-btn tr-btn-soft" type="submit">Confirmar</button>
                                            </form>
                                        @endif
                                        @if(!empty($capabilities['agregar_pasajeros']))
                                            @if($serviceCandidates->isEmpty())
                                                <span class="tr-muted">No hay candidatos para agregar en el alcance.</span>
                                            @else
                                                <form method="POST" action="{{ route('transporte.servicios.pasajeros', $service['id']) }}" data-transport-form>
                                                    @csrf
                                                    @include('transporte.partials.context-fields')
                                                    <div class="tr-form-error" data-transport-error role="alert" tabindex="-1" hidden></div>
                                                    <label class="tr-field">
                                                        <span>Agregar pasajero</span>
                                                        <select class="tr-select" name="grupo_trabajo_detalle_id" required>
                                                            <option value="">Seleccionar pasajero</option>
                                                            @foreach($serviceCandidates as $candidate)
                                                                <option value="{{ $candidate['grupo_trabajo_detalle_id'] }}">{{ $candidate['trabajador'] }} - {{ $candidate['grupo'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <button class="tr-btn" type="submit">Agregar pasajero</button>
                                                </form>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="tr-empty">No hay servicios creados para esta fecha y turno.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@include('transporte.partials.planning-script')
@endsection
