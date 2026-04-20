@extends('layouts.app')

@section('title', 'Asistencia - Personal')

@php
$personal = [
    ['nombre' => 'Carlos Alberto Mendoza Sánchez', 'dni' => '74856231', 'puesto' => 'Operador de Equipos Pesados', 'mina' => 'Mina 1', 'asistencia' => 98, 'faltas' => 1, 'eval' => 4.8, 'estado' => 'verde'],
    ['nombre' => 'María Elena Quispe Flores', 'dni' => '61245874', 'puesto' => 'Supervisor de Turno', 'mina' => 'Mina 1', 'asistencia' => 100, 'faltas' => 0, 'eval' => 4.5, 'estado' => 'verde'],
    ['nombre' => 'Juan Pedro Huamán Torres', 'dni' => '89562341', 'puesto' => 'Técnico de Mantenimiento', 'mina' => 'Taller', 'asistencia' => 92, 'faltas' => 2, 'eval' => 4.2, 'estado' => 'amarillo'],
    ['nombre' => 'Rosa Luz García Rivera', 'dni' => '74589123', 'puesto' => 'Asistente Administrativa', 'mina' => 'Oficina', 'asistencia' => 95, 'faltas' => 1, 'eval' => 4.0, 'estado' => 'verde'],
    ['nombre' => 'Pedro Miguel Asto Yupanqui', 'dni' => '70125489', 'puesto' => 'Conductor de Camión', 'mina' => 'Mina 3', 'asistencia' => 75, 'faltas' => 6, 'eval' => 3.5, 'estado' => 'rojo'],
    ['nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '45678231', 'puesto' => 'Jefe de Seguridad', 'mina' => 'Mina 1', 'asistencia' => 100, 'faltas' => 0, 'eval' => 4.9, 'estado' => 'verde'],
    ['nombre' => 'Ana María Lucero Pérez', 'dni' => '82365412', 'puesto' => 'Enfermera Industrial', 'mina' => 'Mina 1', 'asistencia' => 78, 'faltas' => 5, 'eval' => 3.8, 'estado' => 'rojo'],
    ['nombre' => 'Roberto Carlos Mendoza', 'dni' => '98745123', 'puesto' => 'Mecánico', 'mina' => 'Taller', 'asistencia' => 88, 'faltas' => 3, 'eval' => 4.1, 'estado' => 'amarillo'],
    ['nombre' => 'Sánchez Pablo Paredes', 'dni' => '12345678', 'puesto' => 'Geólogo', 'mina' => 'Mina 2', 'asistencia' => 91, 'faltas' => 2, 'eval' => 4.3, 'estado' => 'verde'],
    ['nombre' => 'Diana Lucía Flores Mamani', 'dni' => '56782345', 'puesto' => 'Contadora', 'mina' => 'Oficina', 'asistencia' => 97, 'faltas' => 1, 'eval' => 4.6, 'estado' => 'verde'],
];
@endphp

@section('content')
<div class="asistencia-dashboard">
    <!-- Filter Tabs -->
    <div class="mina-tabs" style="margin-bottom: 20px;">
        <button class="mina-tab active">Todos (10)</button>
        <button class="mina-tab" style="color: var(--color-success);">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            Buenos (6)
        </button>
        <button class="mina-tab" style="color: var(--color-warning);">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            Atención (2)
        </button>
        <button class="mina-tab" style="color: var(--color-danger);">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Riesgo (2)
        </button>
    </div>

    <!-- Personal Table -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Comportamiento del Personal</span>
            <div class="card-actions">
                <input type="text" class="form-control form-control-sm" placeholder="Buscar trabajador..." style="width: 200px;">
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>DNI</th>
                            <th>Puesto</th>
                            <th>Mina</th>
                            <th>% Asistencia</th>
                            <th>Faltas</th>
                            <th>Evaluación</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($personal as $p)
                        <tr>
                            <td>
                                <div class="trabajador-cell">
                                    <div class="trabajador-avatar-mini">{{ strtoupper(substr($p['nombre'], 0, 2)) }}</div>
                                    <span>{{ $p['nombre'] }}</span>
                                </div>
                            </td>
                            <td>{{ $p['dni'] }}</td>
                            <td>{{ $p['puesto'] }}</td>
                            <td><span class="person-badge mine">{{ $p['mina'] }}</span></td>
                            <td>
                                <div class="asistencia-bar-container">
                                    <div class="asistencia-bar" style="width: {{ $p['asistencia'] }}%; background: {{ $p['asistencia'] >= 90 ? 'var(--color-success)' : ($p['asistencia'] >= 80 ? 'var(--color-warning)' : 'var(--color-danger)') }};"></div>
                                    <span>{{ $p['asistencia'] }}%</span>
                                </div>
                            </td>
                            <td>
                                <span class="faltas-count {{ $p['faltas'] > 3 ? 'faltas-alto' : '' }}">
                                    {{ $p['faltas'] }}
                                </span>
                            </td>
                            <td>
                                <div class="eval-mini">
                                    @for($i = 1; $i <= 5; $i++)
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="{{ $i <= round($p['eval']) ? 'var(--color-warning)' : 'none' }}" stroke="{{ $p['eval'] >= 4 ? 'var(--color-warning)' : 'var(--color-text-muted)' }}" stroke-width="2">
                                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                    </svg>
                                    @endfor
                                </div>
                            </td>
                            <td>
                                @if($p['estado'] === 'verde')
                                <span class="badge badge-success">Bueno</span>
                                @elseif($p['estado'] === 'amarillo')
                                <span class="badge badge-warning">Atención</span>
                                @else
                                <span class="badge badge-danger">Riesgo</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.trabajador-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.trabajador-avatar-mini {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--color-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
}

.asistencia-bar-container {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100px;
}

.asistencia-bar {
    height: 6px;
    border-radius: 3px;
    flex: 1;
    max-width: 60px;
}

.faltas-count {
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    background: #F1F5F9;
}

.faltas-count.faltas-alto {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger);
}

.eval-mini {
    display: flex;
    gap: 1px;
}
</style>
@endpush