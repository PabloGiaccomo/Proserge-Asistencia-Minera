@extends('layouts.app')

@section('title', 'Man Power - Paradas')

@php
    $paradas = collect($data ?? []);
    $unidades = collect($unidades ?? []);
    $fecha = request('fecha', now()->addDay()->toDateString());
    $search = trim((string) request('q', ''));
    $estado = strtoupper((string) request('estado', ''));
    $selectedUnidad = trim((string) request('unidad_minera', ''));
    $totalParadas = $paradas->count();
    $totalAsignados = $paradas->sum('asignados_fecha');
    $totalGrupos = $paradas->sum('grupos_fecha');
    $sinGrupos = $paradas->filter(fn (array $item): bool => (int) ($item['grupos_fecha'] ?? 0) === 0)->count();

    $estadoLabel = function (?string $value): string {
        $value = strtoupper((string) $value);

        return match ($value) {
            'BORRADOR' => 'Borrador',
            'ENVIADO' => 'Enviado',
            'APROBADO' => 'Aprobado',
            'ATENDIDO' => 'Atendido',
            'CERRADO' => 'Cerrado',
            'CANCELADO' => 'Cancelado',
            default => $value !== '' ? ucfirst(strtolower($value)) : 'Sin estado',
        };
    };

    $estadoClass = function (?string $value): string {
        return match (strtoupper((string) $value)) {
            'APROBADO', 'ATENDIDO', 'ENVIADO' => 'mp-status-ok',
            'BORRADOR' => 'mp-status-warn',
            'CERRADO', 'CANCELADO' => 'mp-status-off',
            default => 'mp-status-neutral',
        };
    };
@endphp

@push('styles')
<style>
    .mp-paradas { color:#071b3a; }
    .mp-page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .mp-page-title { margin:0; font-size:28px; font-weight:800; color:#071b3a; }
    .mp-page-subtitle { margin:5px 0 0; color:#5d708c; font-size:15px; }
    .mp-card { background:#fff; border:1px solid #d8e4f2; border-radius:16px; box-shadow:0 10px 24px rgba(7,27,58,.06); overflow:hidden; }
    .mp-card + .mp-card { margin-top:16px; }
    .mp-card-head { padding:18px 22px; border-bottom:1px solid #e6edf5; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
    .mp-card-title { margin:0; font-size:18px; font-weight:800; color:#071b3a; }
    .mp-card-subtitle { margin:5px 0 0; color:#657792; font-size:14px; }
    .mp-card-body { padding:20px 22px; }
    .mp-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
    .mp-summary-item { border:1px solid #d8e4f2; border-radius:14px; background:#fff; padding:15px 16px; }
    .mp-summary-label { display:block; color:#657792; font-size:12px; font-weight:800; text-transform:uppercase; }
    .mp-summary-value { display:block; color:#071b3a; font-size:24px; font-weight:900; margin-top:6px; }
    .mp-filter-grid { display:grid; grid-template-columns:minmax(220px,1fr) minmax(260px,1.35fr) 170px 180px auto auto; gap:12px; align-items:end; }
    .mp-field { display:flex; flex-direction:column; gap:7px; }
    .mp-field label { color:#334763; font-size:12px; font-weight:800; text-transform:uppercase; }
    .mp-input, .mp-select { width:100%; border:1px solid #cddbef; border-radius:12px; padding:12px 14px; color:#071b3a; background:#fff; font:inherit; min-height:46px; }
    .mp-input:focus, .mp-select:focus { outline:none; border-color:#18c7b5; box-shadow:0 0 0 3px rgba(24,199,181,.14); }
    .mp-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:44px; border-radius:12px; padding:12px 16px; border:1px solid #cddbef; background:#fff; color:#071b3a; font-weight:800; text-decoration:none; cursor:pointer; white-space:nowrap; }
    .mp-btn-primary { background:#18c7b5; border-color:#18c7b5; color:#fff; box-shadow:0 8px 18px rgba(24,199,181,.24); }
    .mp-btn-soft { background:#eefdfa; border-color:#8ee8dd; color:#007f76; }
    .mp-list { display:grid; gap:12px; }
    .mp-unit-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:20px 0 10px; padding:0 2px; }
    .mp-unit-heading:first-child { margin-top:0; }
    .mp-unit-heading strong { display:block; margin-top:3px; color:#071b3a; font-size:16px; }
    .mp-parada-row { border:1px solid #d8e4f2; border-radius:14px; background:#fbfdff; padding:16px; display:grid; grid-template-columns:minmax(260px,1.25fr) repeat(4,minmax(100px,.55fr)) auto; gap:14px; align-items:center; }
    .mp-parada-main { min-width:0; }
    .mp-parada-title { margin:0; color:#071b3a; font-size:17px; font-weight:900; line-height:1.3; }
    .mp-parada-meta { margin:5px 0 0; color:#657792; font-size:13px; line-height:1.4; }
    .mp-stat-label { display:block; color:#657792; font-size:11px; font-weight:800; text-transform:uppercase; }
    .mp-stat-value { display:block; color:#071b3a; font-size:18px; font-weight:900; margin-top:4px; }
    .mp-chip { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; font-size:12px; font-weight:800; }
    .mp-status-ok { background:#dcfce7; color:#166534; }
    .mp-status-warn { background:#fff7cc; color:#8a5a00; }
    .mp-status-off { background:#eef2f7; color:#475569; }
    .mp-status-neutral { background:#edf4ff; color:#23508a; }
    .mp-plan { margin-top:8px; display:inline-flex; max-width:100%; }
    .mp-empty { padding:26px; text-align:center; color:#657792; border:1px dashed #cddbef; border-radius:14px; background:#fbfdff; }
    .mp-actions { display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; }
    @media (max-width:1180px) {
        .mp-filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .mp-parada-row { grid-template-columns:1fr 1fr 1fr; }
        .mp-actions { justify-content:flex-start; }
    }
    @media (max-width:760px) {
        .mp-page-head, .mp-card-head { flex-direction:column; }
        .mp-summary, .mp-filter-grid, .mp-parada-row { grid-template-columns:1fr; }
        .mp-card-head, .mp-card-body { padding:16px; }
    }
</style>
@endpush

@section('content')
<div class="mp-paradas">
    <div class="mp-page-head">
        <div>
            <h1 class="mp-page-title">Man Power</h1>
            <p class="mp-page-subtitle">Selecciona la unidad minera y luego entra a una parada activa para organizar sus grupos.</p>
        </div>
    </div>

    <div class="mp-summary">
        <div class="mp-summary-item">
            <span class="mp-summary-label">Unidades visibles</span>
            <span class="mp-summary-value">{{ $selectedUnidad !== '' ? 1 : $paradas->pluck('unidad_minera')->filter()->unique()->count() }}</span>
        </div>
        <div class="mp-summary-item">
            <span class="mp-summary-label">Asignados al dia</span>
            <span class="mp-summary-value">{{ $totalAsignados }}</span>
        </div>
        <div class="mp-summary-item">
            <span class="mp-summary-label">Grupos creados</span>
            <span class="mp-summary-value">{{ $totalGrupos }}</span>
        </div>
        <div class="mp-summary-item">
            <span class="mp-summary-label">Sin grupo diario</span>
            <span class="mp-summary-value">{{ $sinGrupos }}</span>
        </div>
    </div>

    <section class="mp-card">
        <div class="mp-card-head">
            <div>
                <h2 class="mp-card-title">Paradas activas para Man Power</h2>
                <p class="mp-card-subtitle">El alcance respeta las unidades mineras asignadas al planner y solo muestra paradas vigentes con personal de RQ Proserge.</p>
            </div>
            <span class="mp-chip mp-status-neutral">{{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</span>
        </div>
        <div class="mp-card-body">
            <form method="GET" action="{{ route('man-power.index') }}" class="mp-filter-grid">
                <div class="mp-field">
                    <label for="unidad_minera">Unidad minera</label>
                    <select class="mp-select" id="unidad_minera" name="unidad_minera">
                        <option value="">Todas mis unidades</option>
                        @foreach($unidades as $unidad)
                            <option value="{{ $unidad }}" @selected($selectedUnidad === $unidad)>{{ $unidad }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mp-field">
                    <label for="q">Buscar parada</label>
                    <input class="mp-input" id="q" name="q" value="{{ $search }}" placeholder="Mina, area, destino o estado">
                </div>
                <div class="mp-field">
                    <label for="fecha">Fecha</label>
                    <input class="mp-input" id="fecha" name="fecha" type="date" value="{{ $fecha }}">
                </div>
                <div class="mp-field">
                    <label for="estado">Estado RQ</label>
                    <select class="mp-select" id="estado" name="estado">
                        <option value="">Activas</option>
                        <option value="BORRADOR" @selected($estado === 'BORRADOR')>Borrador</option>
                        <option value="ENVIADO" @selected($estado === 'ENVIADO')>Enviado</option>
                        <option value="APROBADO" @selected($estado === 'APROBADO')>Aprobado</option>
                        <option value="ATENDIDO" @selected($estado === 'ATENDIDO')>Atendido</option>
                    </select>
                </div>
                <button class="mp-btn mp-btn-primary" type="submit">Filtrar</button>
                <a class="mp-btn" href="{{ route('man-power.index') }}">Limpiar</a>
            </form>
        </div>
    </section>

    <section class="mp-card">
        <div class="mp-card-head">
            <div>
                <h2 class="mp-card-title">Seleccionar parada</h2>
                <p class="mp-card-subtitle">Cada parada abre su dashboard, selección diaria y grupos ya preparados.</p>
            </div>
            <span class="mp-chip mp-status-neutral">{{ $totalParadas }} parada(s)</span>
        </div>
        <div class="mp-card-body">
            @if($paradas->isEmpty())
                <div class="mp-empty">No hay paradas activas para Man Power con los filtros seleccionados.</div>
            @else
                @foreach($paradas->groupBy(fn (array $item) => $item['unidad_minera'] ?: 'Sin unidad minera') as $unidad => $paradasUnidad)
                    <div class="mp-unit-heading">
                        <div>
                            <span class="mp-stat-label">Unidad minera</span>
                            <strong>{{ $unidad }}</strong>
                        </div>
                        <span class="mp-chip mp-status-neutral">{{ $paradasUnidad->count() }} parada(s)</span>
                    </div>
                    <div class="mp-list">
                    @foreach($paradasUnidad as $parada)
                        @php
                            $titleParts = collect([
                                $parada['destino_nombre'] ?? $parada['mina_nombre'] ?? null,
                                $parada['area'] ?? null,
                            ])->filter()->unique()->values();
                            $title = $titleParts->isNotEmpty() ? $titleParts->implode(' - ') : 'Parada sin nombre';
                            $plan = $parada['plan_vigente'] ?? null;
                            $detailUrl = route('man-power.parada-detalle', $parada['rq_mina_id']).'?'.http_build_query([
                                'fecha' => $fecha,
                                'turno' => request('turno', 'DIA'),
                                'vista' => 'dashboard',
                            ]);
                        @endphp
                        <article class="mp-parada-row">
                            <div class="mp-parada-main">
                                <h3 class="mp-parada-title">{{ $title }}</h3>
                                <p class="mp-parada-meta">
                                    {{ $parada['mina_nombre'] ?? 'Sin mina' }}
                                    @if(!empty($parada['fecha_inicio']) || !empty($parada['fecha_fin']))
                                        - {{ $parada['fecha_inicio'] ? \Illuminate\Support\Carbon::parse($parada['fecha_inicio'])->format('d/m/Y') : 'Sin inicio' }}
                                        al {{ $parada['fecha_fin'] ? \Illuminate\Support\Carbon::parse($parada['fecha_fin'])->format('d/m/Y') : 'Sin fin' }}
                                    @endif
                                </p>
                                <span class="mp-chip {{ $estadoClass($parada['estado'] ?? null) }}">{{ $estadoLabel($parada['estado'] ?? null) }}</span>
                                @if($plan)
                                    <span class="mp-chip mp-status-neutral mp-plan">{{ $plan['codigo'] ?? 'Plan' }} - {{ $plan['estado'] ?? 'Vigente' }}</span>
                                @endif
                            </div>
                            <div>
                                <span class="mp-stat-label">Requerido</span>
                                <span class="mp-stat-value">{{ (int) ($parada['total_requerido'] ?? 0) }}</span>
                            </div>
                            <div>
                                <span class="mp-stat-label">Asignados</span>
                                <span class="mp-stat-value">{{ (int) ($parada['asignados_fecha'] ?? 0) }}</span>
                            </div>
                            <div>
                                <span class="mp-stat-label">Grupos hoy</span>
                                <span class="mp-stat-value">{{ (int) ($parada['grupos_fecha'] ?? 0) }}</span>
                            </div>
                            <div>
                                <span class="mp-stat-label">Planes</span>
                                <span class="mp-stat-value">{{ (int) ($parada['planes_count'] ?? 0) }}</span>
                            </div>
                            <div class="mp-actions">
                                <a class="mp-btn mp-btn-primary" href="{{ $detailUrl }}">Entrar a grupos</a>
                            </div>
                        </article>
                    @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    </section>
</div>
@endsection
