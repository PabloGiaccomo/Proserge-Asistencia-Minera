<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard KPI de Parada</title>
    <style>
        body{font-family:Arial,sans-serif;color:#111827;margin:24px}h1{margin:0 0 4px;font-size:24px}p{margin:0 0 8px;color:#4b5563}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:18px 0}.box{border:1px solid #d1d5db;border-radius:8px;padding:10px}.box span{display:block;color:#6b7280;font-size:11px;text-transform:uppercase;font-weight:700}.box strong{display:block;margin-top:6px;font-size:18px}.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:18px 0}.alert{border:1px solid #f59e0b;background:#fffbeb;border-radius:8px;padding:8px;margin:6px 0;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px}th,td{border:1px solid #d1d5db;padding:7px;text-align:left}th{background:#f3f4f6}.print-actions{margin-bottom:16px}@media print{.print-actions{display:none}body{margin:12mm}}
    </style>
</head>
<body>
@php
    $rq = $dashboard['rq_mina'] ?? [];
    $plan = $dashboard['plan'] ?? [];
@endphp
<div class="print-actions">
    <button onclick="window.print()">Imprimir</button>
</div>
<h1>Dashboard KPI de Parada</h1>
<p>{{ $rq['destino_nombre'] ?? 'Parada' }} · {{ $rq['area'] ?? '-' }}</p>
<p>Plan: {{ $plan['codigo'] ?? '-' }} {{ $plan['nombre'] ?? '' }} · Filtro: {{ $dashboard['filters']['fecha'] ?? '-' }} / {{ $dashboard['filters']['turno'] ?? '-' }}</p>

<section class="kpis">
    @foreach(($dashboard['kpis'] ?? []) as $kpi)
        <div class="box">
            <span>{{ $kpi['label'] }}</span>
            <strong>{{ $kpi['value'] }}</strong>
            <p>{{ $kpi['hint'] }}</p>
        </div>
    @endforeach
</section>

@foreach(($dashboard['alerts'] ?? []) as $alert)
    <div class="alert">{{ $alert['message'] }}</div>
@endforeach

<table>
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
                <td>{{ $row['asistencia_cerrada'] ? 'Cerrada' : 'Abierta' }}</td>
            </tr>
        @empty
            <tr><td colspan="10">Sin resumen de ejecucion para los filtros seleccionados.</td></tr>
        @endforelse
    </tbody>
</table>
</body>
</html>
