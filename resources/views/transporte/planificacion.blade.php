@extends('layouts.app')

@section('title', 'Transporte operativo - Proserge')

@php
    use App\Models\TransporteServicio;
    use App\Support\Rbac\PermissionMatrix;

    $permissions = session('user.permissions', []);
    $canCreate = PermissionMatrix::allowsDirect($permissions, 'transportes', 'crear');
    $canUpdate = PermissionMatrix::allowsDirectAny($permissions, 'transportes', ['editar', 'actualizar', 'entregar', 'recepcionar']);
    $selected = $selected ?? [];
    $summary = $resumen ?? [];
    $selectedRq = $selected['rq_mina_id'] ?? '';
    $selectedPlan = $selected['rq_mina_plan_id'] ?? '';
    $selectedFecha = $selected['fecha'] ?? now()->addDay()->toDateString();
    $selectedTurno = $selected['turno'] ?? 'A';
    $paradas = collect($paradas ?? []);
    $plans = collect($plans ?? []);
    $requirements = collect($requerimientos ?? []);
    $services = collect($servicios ?? []);
    $groups = collect($grupos_man_power ?? []);
    $pendingPassengers = collect($pasajeros_pendientes ?? []);
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
    .tr-btn-primary { border-color:#18c7b5; background:#18c7b5; color:#fff; box-shadow:0 8px 18px rgba(24,199,181,.22); }
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
    @media (max-width:1100px) {
        .tr-summary { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .tr-filter-grid, .tr-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:720px) {
        .tr-head, .tr-card-head { flex-direction:column; }
        .tr-summary, .tr-filter-grid, .tr-grid { grid-template-columns:1fr; }
    }
</style>
@endpush

@section('content')
<div class="tr-shell">
    <div class="tr-head">
        <div>
            <h1 class="tr-title">Transporte operativo</h1>
            <p class="tr-subtitle">Planificacion de unidades, pasajeros y brechas por parada, plan, fecha y turno.</p>
        </div>
        <a class="tr-btn" href="{{ route('logistica.index', ['tab' => 'servicios']) }}">Volver a Logistica</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
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
                        @foreach($plans as $plan)
                            <option value="{{ $plan['id'] }}" @selected($selectedPlan === $plan['id'])>{{ $plan['codigo'] }} - {{ $plan['nombre'] }}</option>
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

    @if($canCreate && $selectedRq !== '')
        <section class="tr-card">
            <div class="tr-card-head">
                <div>
                    <h2 class="tr-card-title">Nuevo servicio</h2>
                    <p class="tr-card-subtitle">Crea la unidad concreta para esta fecha y turno. Se guarda en BORRADOR si faltan placa, conductor o capacidad.</p>
                </div>
            </div>
            <div class="tr-card-body">
                <form method="POST" action="{{ route('transporte.servicios.store') }}">
                    @csrf
                    <input type="hidden" name="rq_mina_id" value="{{ $selectedRq }}">
                    <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlan }}">
                    <input type="hidden" name="fecha" value="{{ $selectedFecha }}">
                    <input type="hidden" name="turno" value="{{ $selectedTurno }}">
                    <div class="tr-grid">
                        <label class="tr-field"><span>Tipo</span><select class="tr-select" name="tipo"><option value="PERSONAL">Personal</option><option value="CARGA">Carga</option></select></label>
                        <label class="tr-field"><span>Tramo</span><select class="tr-select" name="tramo"><option value="IDA">Ida</option><option value="RETORNO">Retorno</option><option value="TRASLADO_INTERNO">Traslado interno</option></select></label>
                        <label class="tr-field"><span>Transportista</span><input class="tr-input" name="transportista" placeholder="Empresa o proveedor"></label>
                        <label class="tr-field"><span>Vehiculo</span><input class="tr-input" name="tipo_vehiculo" placeholder="Van, bus, camioneta"></label>
                        <label class="tr-field"><span>Placa</span><input class="tr-input" name="placa" placeholder="ABC-123"></label>
                        <label class="tr-field"><span>Capacidad</span><input class="tr-input" type="number" min="0" name="capacidad"></label>
                        <label class="tr-field"><span>Hora salida</span><input class="tr-input" type="time" name="hora_salida"></label>
                        <label class="tr-field"><span>Hora retorno</span><input class="tr-input" type="time" name="hora_retorno"></label>
                        <label class="tr-field"><span>Origen</span><input class="tr-input" name="origen"></label>
                        <label class="tr-field"><span>Destino</span><input class="tr-input" name="destino"></label>
                        <label class="tr-field"><span>Estado</span><select class="tr-select" name="estado"><option value="BORRADOR">Borrador</option><option value="ASIGNADO">Asignado</option></select></label>
                        <label class="tr-field"><span>Alcance Man Power</span><select class="tr-select" name="alcances[0][grupo_trabajo_id]"><option value="">Sin grupo</option>@foreach($groups as $group)<option value="{{ $group['id'] }}">{{ $group['servicio'] }} - {{ $group['paradero'] }}</option>@endforeach</select></label>
                    </div>
                    <div class="tr-actions" style="margin-top:14px">
                        <button class="tr-btn tr-btn-primary" type="submit">Crear servicio</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    <section class="tr-card">
        <div class="tr-card-head">
            <div>
                <h2 class="tr-card-title">Requerimientos del plan</h2>
                <p class="tr-card-subtitle">RQ Mina conserva lo requerido; la ejecucion se gestiona como servicio.</p>
            </div>
            <span class="tr-badge">{{ $requirements->count() }} requerimientos</span>
        </div>
        <div class="tr-table-wrap">
            <table class="tr-table">
                <thead><tr><th>Grupo / SAIT</th><th>Tipo</th><th>Solicitado</th><th>Origen / destino</th><th>Estado</th><th>Compatibilidad</th></tr></thead>
                <tbody>
                    @forelse($requirements as $row)
                        <tr>
                            <td><strong>{{ $row['grupo_operativo'] ?: 'Sin grupo' }}</strong><br><span class="tr-muted">{{ $row['sait'] ?: $row['alcance'] ?: '-' }}</span></td>
                            <td>{{ $row['tipo'] }}</td>
                            <td>{{ $row['solicitado'] ?: '-' }}<br><span class="tr-muted">Unid: {{ $row['cantidad_unidades_requeridas'] ?: '-' }} · Cap: {{ $row['capacidad_requerida'] ?: '-' }}</span></td>
                            <td>{{ $row['origen'] ?: '-' }}<br><span class="tr-muted">{{ $row['destino'] ?: '-' }}</span></td>
                            <td><span class="tr-badge">{{ $row['estado'] ?: 'REQUERIDO' }}</span></td>
                            <td>@if($row['legacy'])<span class="tr-badge tr-badge-warn">{{ $row['legacy_label'] }}</span>@else<span class="tr-badge tr-badge-ok">Estructurado</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="tr-empty">No hay requerimientos para el contexto seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="tr-card">
        <div class="tr-card-head">
            <div>
                <h2 class="tr-card-title">Servicios asignados</h2>
                <p class="tr-card-subtitle">Capacidad, ocupacion, pasajeros y acciones por unidad.</p>
            </div>
            <span class="tr-badge">{{ $services->count() }} servicios</span>
        </div>
        <div class="tr-table-wrap">
            <table class="tr-table">
                <thead><tr><th>Servicio</th><th>Unidad</th><th>Capacidad</th><th>Alcances</th><th>Pasajeros</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse($services as $service)
                        @php
                            $metrics = $service['metricas'] ?? [];
                            $servicePassengers = collect($service['pasajeros'] ?? [])->where('estado', 'ASIGNADO');
                            $serviceGroupIds = collect($service['alcances'] ?? [])->pluck('grupo_man_power')->filter();
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
                                @if($canUpdate)
                                    <div class="tr-mini-form">
                                        <form method="POST" action="{{ route('transporte.servicios.estado', $service['id']) }}">
                                            @csrf
                                            <input type="hidden" name="estado" value="CONFIRMADO">
                                            <button class="tr-btn tr-btn-soft" type="submit">Confirmar</button>
                                        </form>
                                        <form method="POST" action="{{ route('transporte.servicios.pasajeros', $service['id']) }}">
                                            @csrf
                                            <select class="tr-select" name="grupo_trabajo_detalle_id">
                                                <option value="">Agregar pasajero</option>
                                                @foreach($pendingPassengers as $candidate)
                                                    <option value="{{ $candidate['grupo_trabajo_detalle_id'] }}">{{ $candidate['trabajador'] }} - {{ $candidate['grupo'] }}</option>
                                                @endforeach
                                            </select>
                                            <button class="tr-btn" type="submit">Agregar</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="tr-muted">Sin permiso</span>
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
@endsection
