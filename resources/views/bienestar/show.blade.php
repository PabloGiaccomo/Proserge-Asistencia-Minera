@extends('layouts.app')

@section('title', 'Cartilla de Ocupación - Bienestar')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Cartilla de Ocupación</h1>
                <p class="page-subtitle">{{ $trabajador->nombre_completo }} - DNI {{ $trabajador->dni }}</p>
            </div>
            <a href="{{ $soloCalendario ? route('personal.index') : route('bienestar.index') }}" class="btn btn-outline btn-sm">Volver</a>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-body" style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:center;">
            <div>
                <div><strong>Puesto:</strong> {{ $trabajador->puesto ?: '-' }}</div>
                <div><strong>Estado:</strong> {{ $trabajador->estado }}</div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a class="btn btn-outline btn-sm" href="{{ route('bienestar.show', array_merge(['id' => $trabajador->id, 'mes' => $prevMonth], $soloCalendario ? ['solo_calendario' => 1] : [])) }}">Mes anterior</a>
                <span class="text-muted" style="min-width:150px; text-align:center;">{{ $monthLabel }}</span>
                <a class="btn btn-outline btn-sm" href="{{ route('bienestar.show', array_merge(['id' => $trabajador->id, 'mes' => $nextMonth], $soloCalendario ? ['solo_calendario' => 1] : [])) }}">Mes siguiente</a>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Calendario de ocupación</span></div>
        <div class="card-body">
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                <span class="badge" style="background:#fde68a; color:#92400e;">Vacaciones</span>
                <span class="badge" style="background:#fecaca; color:#991b1b;">Descanso médico</span>
                <span class="badge" style="background:#c7d2fe; color:#3730a3;">Inhabilitado</span>
                <span class="badge" style="background:#bfdbfe; color:#1e3a8a;">Restricción temporal</span>
                <span class="badge" style="background:#e5e7eb; color:#374151;">Otro</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:6px; margin-bottom:6px; font-weight:600; font-size:12px; color:#64748b;">
                <div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
            </div>
            @foreach($calendar as $week)
                <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:6px; margin-bottom:6px;">
                    @foreach($week as $day)
                        @if($day === null)
                            <div style="min-height:72px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc;"></div>
                        @else
                            @php $first = $day['bloqueos']->first(); @endphp
                            @php
                                $styleMap = [
                                    'vacaciones' => ['border' => '#f59e0b', 'bg' => '#fffbeb', 'text' => '#92400e'],
                                    'descanso_medico' => ['border' => '#f87171', 'bg' => '#fff1f2', 'text' => '#991b1b'],
                                    'inhabilitado' => ['border' => '#818cf8', 'bg' => '#eef2ff', 'text' => '#3730a3'],
                                    'restriccion_temporal' => ['border' => '#60a5fa', 'bg' => '#eff6ff', 'text' => '#1e3a8a'],
                                    'default' => ['border' => '#94a3b8', 'bg' => '#f8fafc', 'text' => '#334155'],
                                ];
                                $visual = $day['has_bloqueo']
                                    ? ($styleMap[$day['tipo_key'] ?? 'default'] ?? $styleMap['default'])
                                    : ['border' => '#e2e8f0', 'bg' => '#ffffff', 'text' => '#0f172a'];
                            @endphp
                            <div style="min-height:72px; border:1px solid {{ $visual['border'] }}; border-radius:8px; padding:6px; background:{{ $visual['bg'] }};">
                                <div style="font-weight:600; font-size:12px; margin-bottom:4px;">{{ $day['day'] }}</div>
                                @if($day['has_bloqueo'])
                                    <div style="font-size:11px; color:{{ $visual['text'] }}; line-height:1.25;">{{ $first?->tipoLabel() }}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    @if(!$soloCalendario)
    <div class="card">
        <div class="card-header"><span class="card-title">Detalle de bloqueos del mes</span></div>
        <div class="card-body">
            @if($bloqueos->isEmpty())
                <p class="text-muted" style="margin:0;">No hay bloqueos para este trabajador en el mes seleccionado.</p>
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Motivo</th>
                                <th>Desde</th>
                                <th>Hasta</th>
                                <th>Detalle</th>
                                <th>Registrado por</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bloqueos as $bloqueo)
                                <tr>
                                    <td>{{ $bloqueo->tipoLabel() }}</td>
                                    <td>{{ $bloqueo->motivo }}</td>
                                    <td>{{ optional($bloqueo->fecha_inicio)->format('d/m/Y') }}</td>
                                    <td>{{ optional($bloqueo->fecha_fin)->format('d/m/Y') }}</td>
                                    <td>{{ $bloqueo->detalle ?: '-' }}</td>
                                    <td>{{ $bloqueo->bloqueadoPor?->personal?->nombre_completo ?? $bloqueo->bloqueadoPor?->email ?? '-' }}</td>
                                    <td>
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <a href="{{ route('bienestar.bloqueos.edit', $bloqueo->id) }}" class="btn btn-outline btn-xs">Editar</a>
                                            <form method="POST" action="{{ route('bienestar.bloqueos.anular', $bloqueo->id) }}" onsubmit="return confirm('¿Anular este bloqueo?');" style="display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-xs">Anular</button>
                                            </form>
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
    @endif
</div>
@endsection
