@extends('layouts.app')

@section('title', 'Dashboard KPI de Parada')

@section('content')
@php
    $rq = $dashboard['rq_mina'] ?? [];
    $plan = $dashboard['plan'] ?? null;
    $filters = $dashboard['filters'] ?? [];
    $execution = $dashboard['execution']['summary'] ?? [];
    $manPower = $dashboard['man_power']['resumen'] ?? [];
    $transport = $dashboard['transport']['resumen'] ?? [];
    $coverage = $dashboard['coverage']['global'] ?? [];
    $query = request()->query();
@endphp

<div class="parada-dashboard">
    <header class="pd-header">
        <div>
            <p class="pd-eyebrow">RQ Mina / Dashboard KPI</p>
            <h1>{{ $rq['destino_nombre'] ?? 'Parada' }}</h1>
            <p>{{ $rq['area'] ?? '-' }} · {{ $rq['fecha_inicio'] ?? '-' }} al {{ $rq['fecha_fin'] ?? '-' }}</p>
        </div>
        <div class="pd-actions">
            <a href="{{ route('rq-mina.show', $rq['id'] ?? '') }}" class="btn btn-outline">Volver</a>
            <a href="{{ route('rq-mina.dashboard.print', array_merge(['rqMina' => $rq['id'] ?? ''], $query)) }}" class="btn btn-outline" target="_blank">Imprimir</a>
            <a href="{{ route('rq-mina.dashboard.export', array_merge(['rqMina' => $rq['id'] ?? ''], $query)) }}" class="btn btn-primary">Exportar Excel</a>
        </div>
    </header>

    @if(session('success'))
        <div class="pd-alert pd-alert-success">{{ session('success') }}</div>
    @endif

    <section class="pd-card pd-filter-card">
        <form method="GET" action="{{ route('rq-mina.dashboard', $rq['id'] ?? '') }}" class="pd-filters">
            <label>
                <span>Plan</span>
                <select name="plan_id">
                    @foreach(($dashboard['plans'] ?? []) as $option)
                        <option value="{{ $option['id'] }}" @selected(($filters['plan_id'] ?? '') === $option['id'])>
                            {{ $option['codigo'] }} · {{ $option['nombre'] }} ({{ $option['estado'] }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Fecha</span>
                <input type="date" name="fecha" value="{{ $filters['fecha'] ?? '' }}">
            </label>
            <label>
                <span>Turno</span>
                <select name="turno">
                    <option value="DIA" @selected(($filters['turno'] ?? '') === 'DIA')>Dia</option>
                    <option value="NOCHE" @selected(($filters['turno'] ?? '') === 'NOCHE')>Noche</option>
                </select>
            </label>
            <div class="pd-filter-actions">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <a href="{{ route('rq-mina.dashboard', $rq['id'] ?? '') }}" class="btn btn-outline">Limpiar</a>
            </div>
        </form>
        <form method="POST" action="{{ route('rq-mina.dashboard.recalculate', $rq['id'] ?? '') }}" class="pd-recalc-form">
            @csrf
            <input type="hidden" name="plan_id" value="{{ $filters['plan_id'] ?? '' }}">
            <input type="hidden" name="fecha" value="{{ $filters['fecha'] ?? '' }}">
            <input type="hidden" name="turno" value="{{ $filters['turno'] ?? '' }}">
            <button class="btn btn-outline" type="submit">Recalcular ejecucion</button>
        </form>
    </section>

    <section class="pd-kpi-grid">
        @foreach(($dashboard['kpis'] ?? []) as $kpi)
            <article class="pd-kpi pd-kpi-{{ $kpi['tone'] ?? 'neutral' }}">
                <span>{{ $kpi['label'] }}</span>
                <strong>{{ $kpi['value'] }}</strong>
                <small>{{ $kpi['hint'] }}</small>
            </article>
        @endforeach
    </section>

    @if(!empty($dashboard['alerts']))
        <section class="pd-alert-list">
            @foreach($dashboard['alerts'] as $alert)
                <div class="pd-alert pd-alert-{{ $alert['tone'] ?? 'warning' }}">{{ $alert['message'] }}</div>
            @endforeach
        </section>
    @endif

    <section class="pd-grid pd-grid-3">
        <article class="pd-card">
            <h2>RQ Proserge</h2>
            <dl class="pd-metrics">
                <div><dt>Titulares</dt><dd>{{ $coverage['titular_efectivo'] ?? 0 }} / {{ $coverage['titular_objetivo'] ?? 0 }}</dd></div>
                <div><dt>Respaldo</dt><dd>{{ $coverage['respaldo_efectivo'] ?? 0 }} / {{ $coverage['respaldo_objetivo'] ?? 0 }}</dd></div>
                <div><dt>Sin clasificar</dt><dd>{{ $coverage['sin_clasificar'] ?? 0 }}</dd></div>
            </dl>
        </article>
        <article class="pd-card">
            <h2>Man Power</h2>
            <dl class="pd-metrics">
                <div><dt>Requeridos</dt><dd>{{ $manPower['requeridos_por_plan'] ?? 0 }}</dd></div>
                <div><dt>Distribuidos</dt><dd>{{ $manPower['total_distribuido'] ?? 0 }}</dd></div>
                <div><dt>Grupos con brecha</dt><dd>{{ $manPower['grupos_con_brecha'] ?? 0 }}</dd></div>
            </dl>
        </article>
        <article class="pd-card">
            <h2>Transporte</h2>
            <dl class="pd-metrics">
                <div><dt>Servicios</dt><dd>{{ $transport['servicios_creados'] ?? 0 }}</dd></div>
                <div><dt>Con transporte</dt><dd>{{ $transport['personas_con_transporte'] ?? 0 }}</dd></div>
                <div><dt>Sin transporte</dt><dd>{{ $transport['personas_sin_transporte'] ?? 0 }}</dd></div>
            </dl>
        </article>
    </section>

    <section class="pd-card">
        <div class="pd-section-header">
            <div>
                <h2>Ejecucion por grupo y actividad</h2>
                <p>Resumen filtrado por fecha y turno. Si la asistencia no esta cerrada, el valor real es preliminar.</p>
            </div>
            <span>{{ count($dashboard['execution']['rows'] ?? []) }} filas</span>
        </div>
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Turno</th>
                        <th>Grupo</th>
                        <th>Actividad</th>
                        <th>Plan</th>
                        <th>Prog.</th>
                        <th>Real</th>
                        <th>Brecha</th>
                        <th>% real</th>
                        <th>Cierre</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(($dashboard['execution']['rows'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['fecha'] }}</td>
                            <td>{{ $row['turno'] }}</td>
                            <td>{{ $row['grupo'] }}</td>
                            <td>{{ $row['actividad'] }}</td>
                            <td>{{ $row['planificado'] }}</td>
                            <td>{{ $row['programado'] }}</td>
                            <td>{{ $row['presentes'] }}</td>
                            <td>{{ $row['brecha_plan_real'] }}</td>
                            <td>{{ $row['porcentaje_cumplimiento_real'] }}%</td>
                            <td>
                                <span class="pd-pill {{ $row['asistencia_cerrada'] ? 'is-ok' : 'is-open' }}">
                                    {{ $row['asistencia_cerrada'] ? 'Cerrada' : 'Abierta' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="pd-empty">No hay resumen de ejecucion para los filtros seleccionados. Usa Recalcular ejecucion si ya existe Man Power y asistencia.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="pd-card">
        <h2>Definicion de indicadores</h2>
        <div class="pd-formulas">
            @foreach(($dashboard['formulas'] ?? []) as $label => $formula)
                <div>
                    <strong>{{ $label }}</strong>
                    <p>{{ $formula }}</p>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection

@push('styles')
<style>
.parada-dashboard{display:flex;flex-direction:column;gap:18px;color:#0f172a}.pd-header,.pd-card{background:#fff;border:1px solid #dbe6f3;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.04)}.pd-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:22px}.pd-eyebrow{margin:0 0 6px;color:#0f766e;font-size:12px;font-weight:800;text-transform:uppercase}.pd-header h1{margin:0;font-size:28px}.pd-header p{margin:6px 0 0;color:#64748b}.pd-actions{display:flex;gap:10px;flex-wrap:wrap}.pd-filter-card{display:flex;justify-content:space-between;gap:14px;padding:18px}.pd-filters{display:grid;grid-template-columns:minmax(240px,1.4fr) minmax(160px,.7fr) minmax(140px,.5fr) auto;gap:12px;align-items:end;flex:1}.pd-filters label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:800;color:#475569;text-transform:uppercase}.pd-filters input,.pd-filters select{height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:0 12px;background:#fff;font-weight:700;color:#0f172a}.pd-filter-actions,.pd-recalc-form{display:flex;gap:8px}.pd-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.pd-kpi{background:#fff;border:1px solid #dbe6f3;border-radius:14px;padding:16px;min-height:122px}.pd-kpi span{display:block;font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase}.pd-kpi strong{display:block;margin-top:10px;font-size:26px}.pd-kpi small{display:block;margin-top:8px;color:#64748b}.pd-kpi-success{border-color:#86efac;background:#f0fdf4}.pd-kpi-warning{border-color:#fde68a;background:#fffbeb}.pd-kpi-danger{border-color:#fecaca;background:#fef2f2}.pd-alert-list{display:flex;flex-direction:column;gap:8px}.pd-alert{border-radius:12px;padding:12px 14px;font-weight:700}.pd-alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534}.pd-alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.pd-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.pd-grid{display:grid;gap:14px}.pd-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.pd-card{padding:18px}.pd-card h2{margin:0 0 10px;font-size:18px}.pd-metrics{display:grid;gap:10px;margin:0}.pd-metrics div{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px}.pd-metrics dt{font-weight:700;color:#64748b}.pd-metrics dd{margin:0;font-weight:900}.pd-section-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px}.pd-section-header p{margin:4px 0 0;color:#64748b}.pd-section-header span{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:8px 12px;font-weight:800}.pd-table-wrap{overflow:auto}.pd-table{width:100%;border-collapse:collapse;min-width:980px}.pd-table th,.pd-table td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.pd-table th{background:#f8fafc;color:#475569;font-size:12px;text-transform:uppercase}.pd-pill{display:inline-flex;border-radius:999px;padding:6px 10px;font-weight:800;font-size:12px}.pd-pill.is-ok{background:#dcfce7;color:#166534}.pd-pill.is-open{background:#fee2e2;color:#991b1b}.pd-empty{text-align:center;color:#64748b;padding:24px!important}.pd-formulas{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pd-formulas div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.pd-formulas p{margin:6px 0 0;color:#64748b}@media(max-width:1100px){.pd-kpi-grid,.pd-grid-3,.pd-formulas{grid-template-columns:repeat(2,minmax(0,1fr))}.pd-filters{grid-template-columns:1fr 1fr}.pd-filter-card,.pd-header{flex-direction:column}}@media(max-width:720px){.pd-kpi-grid,.pd-grid-3,.pd-formulas,.pd-filters{grid-template-columns:1fr}.pd-header h1{font-size:22px}}
</style>
@endpush
