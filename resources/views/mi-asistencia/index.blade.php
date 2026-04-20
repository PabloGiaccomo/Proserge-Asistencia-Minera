@extends('layouts.app')

@section('title', 'Mi Asistencia - Proserge')

@php
$fecha = date('d/m/Y');
$horaActual = date('H:i');
$turnoActual = 'Día';

$today = date('Y-m-d');
$trabajadores = [
    ['id' => 1, 'nombre' => 'Juan Carlos Pérez Huanca', 'dni' => '45678231', 'mina' => 'Mina 1', 'estado' => 'presente'],
    ['id' => 2, 'nombre' => 'María Elena García Lopez', 'dni' => '70125489', 'mina' => 'Mina 1', 'estado' => 'presente'],
    ['id' => 3, 'nombre' => 'Pedro Luis Asto Yupanqui', 'dni' => '82365412', 'mina' => 'Mina 2', 'estado' => 'ausente'],
    ['id' => 4, 'nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '55678901', 'mina' => 'Mina 3', 'estado' => 'presente'],
    ['id' => 5, 'nombre' => 'Carmen Rosa Torres Flores', 'dni' => '47890123', 'mina' => 'Taller', 'estado' => 'presente'],
    ['id' => 6, 'nombre' => 'Jorge Eduardo Mamani Copa', 'dni' => '89012345', 'mina' => 'Mina 1', 'estado' => 'presente'],
    ['id' => 7, 'nombre' => 'Sofia Elizabeth Quispe Mamani', 'dni' => '73456789', 'mina' => 'Oficina', 'estado' => 'ausente'],
];

$stats = [
    'total' => count($trabajadores),
    'presentes' => count(array_filter($trabajadores, fn($t) => $t['estado'] === 'presente')),
    'ausentes' => count(array_filter($trabajadores, fn($t) => $t['estado'] === 'ausente')),
];
@endphp

@section('content')
<div class="mi-asistencia-container">
    <div class="ma-header">
        <div class="ma-title-section">
            <h1 class="ma-title">Control de Asistencia</h1>
            <p class="ma-subtitle">Registre la asistencia de su personal asignado</p>
        </div>
        <div class="ma-info-bar">
            <div class="ma-info-item">
                <label class="ma-info-label">Fecha</label>
                <input type="date" class="ma-date-input" value="{{ $today }}">
            </div>
            <div class="ma-info-item">
                <span class="ma-info-label">Turno</span>
                <span class="ma-info-value">{{ $turnoActual }}</span>
            </div>
            <div class="ma-info-item">
                <span class="ma-info-label">Hora</span>
                <span class="ma-info-value">{{ $horaActual }}</span>
            </div>
        </div>
    </div>

    <div class="ma-stats-row">
        <div class="ma-stat-card present">
            <div class="ma-stat-num">{{ $stats['presentes'] }}</div>
            <div class="ma-stat-lbl">Presentes</div>
        </div>
        <div class="ma-stat-card absent">
            <div class="ma-stat-num">{{ $stats['ausentes'] }}</div>
            <div class="ma-stat-lbl">Ausentes</div>
        </div>
        <div class="ma-stat-card total">
            <div class="ma-stat-num">{{ $stats['total'] }}</div>
            <div class="ma-stat-lbl">Total</div>
        </div>
    </div>

    <div class="ma-worker-list">
        <div class="ma-list-header">
            <h2 class="ma-list-title">Personal Asignado ({{ $stats['total'] }} trabajadores)</h2>
            <div class="ma-filter-btns">
                <button class="ma-filter-btn active" data-filter="all">Todos</button>
                <button class="ma-filter-btn" data-filter="presente">Presentes</button>
                <button class="ma-filter-btn" data-filter="ausente">Ausentes</button>
            </div>
        </div>

        <div class="ma-table-wrapper">
            <table class="ma-table">
                <thead>
                    <tr>
                        <th>Trabajador</th>
                        <th>DNI</th>
                        <th>Mina/Área</th>
                        <th>Estado Actual</th>
                        <th>Marcar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($trabajadores as $trabajador)
                    <tr class="worker-row" data-estado="{{ $trabajador['estado'] }}">
                        <td>
                            <div class="worker-name">{{ $trabajador['nombre'] }}</div>
                        </td>
                        <td>
                            <span class="worker-dni">{{ $trabajador['dni'] }}</span>
                        </td>
                        <td>
                            <span class="worker-mina">{{ $trabajador['mina'] }}</span>
                        </td>
                        <td>
                            @if($trabajador['estado'] === 'presente')
                            <span class="estado-badge presente">Presente</span>
                            @else
                            <span class="estado-badge ausente">Ausente</span>
                            @endif
                        </td>
                        <td>
                            <div class="mark-buttons">
                                <button class="mark-btn presente @if($trabajador['estado'] === 'presente') active @endif" data-id="{{ $trabajador['id'] }}" title="Presente">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                                <button class="mark-btn ausente @if($trabajador['estado'] === 'ausente') active @endif" data-id="{{ $trabajador['id'] }}" title="Ausente">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="ma-actions">
        <button class="ma-btn-save">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Guardar Asistencia
        </button>
        <button class="ma-btn-export">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Exportar
        </button>
    </div>
</div>

<style>
.mi-asistencia-container { max-width: 1400px; margin: 0 auto; padding: 24px; }
.ma-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; background: linear-gradient(135deg, #0A223D 0%, #1a3a5c 100%); border-radius: 20px; padding: 28px; color: white; }
.ma-title { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.ma-subtitle { font-size: 14px; opacity: 0.8; margin: 0; }
.ma-info-bar { display: flex; gap: 24px; }
.ma-info-item { display: flex; flex-direction: column; align-items: center; }
.ma-info-label { font-size: 12px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px; }
.ma-info-value { font-size: 18px; font-weight: 600; }
.ma-stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px; }
.ma-stat-card { background: white; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.04); }
.ma-stat-card.present { border-left: 4px solid #10B981; }
.ma-stat-card.absent { border-left: 4px solid #EF4444; }
.ma-stat-card.total { border-left: 4px solid #19D3C5; }
.ma-stat-num { font-size: 32px; font-weight: 700; line-height: 1; }
.ma-stat-lbl { font-size: 13px; color: #64748B; margin-top: 4px; }
.ma-worker-list { background: white; border-radius: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.04); overflow: hidden; }
.ma-list-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #F1F5F9; }
.ma-list-title { font-size: 16px; font-weight: 600; margin: 0; }
.ma-filter-btns { display: flex; gap: 8px; }
.ma-filter-btn { padding: 8px 16px; border-radius: 8px; border: 1px solid #E2E8F0; background: white; font-size: 13px; cursor: pointer; transition: all 0.2s; }
.ma-filter-btn:hover { border-color: #19D3C5; color: #19D3C5; }
.ma-filter-btn.active { background: #19D3C5; border-color: #19D3C5; color: white; }
.ma-table-wrapper { overflow-x: auto; }
.ma-table { width: 100%; border-collapse: collapse; }
.ma-table th { text-align: left; padding: 14px 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B; background: #F8FAFC; border-bottom: 1px solid #E2E8F0; }
.ma-table td { padding: 16px 20px; border-bottom: 1px solid #F1F5F9; }
.worker-row { transition: background 0.2s; }
.worker-row:hover { background: #F8FAFC; }
.worker-name { font-weight: 600; font-size: 14px; }
.worker-dni, .worker-mina { font-size: 13px; color: #64748B; }
.estado-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.estado-badge.presente { background: rgba(16,185,129,0.1); color: #10B981; }
.estado-badge.ausente { background: rgba(239,68,68,0.1); color: #EF4444; }
.estado-badge.tardanza { background: rgba(245,158,11,0.1); color: #F59E0B; }
.estado-badge.permiso { background: rgba(139,92,246,0.1); color: #8B5CF6; }
.mark-buttons { display: flex; gap: 6px; }
.mark-btn { width: 36px; height: 36px; border-radius: 8px; border: 1px solid #E2E8F0; background: white; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.mark-btn:hover { transform: scale(1.1); }
.mark-btn.presente:hover, .mark-btn.presente.active { border-color: #10B981; color: #10B981; background: rgba(16,185,129,0.1); }
.mark-btn.tardanza:hover, .mark-btn.tardanza.active { border-color: #F59E0B; color: #F59E0B; background: rgba(245,158,11,0.1); }
.mark-btn.ausente:hover, .mark-btn.ausente.active { border-color: #EF4444; color: #EF4444; background: rgba(239,68,68,0.1); }
.mark-btn.permiso:hover, .mark-btn.permiso.active { border-color: #8B5CF6; color: #8B5CF6; background: rgba(139,92,246,0.1); }
.ma-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
.ma-btn-save { display: flex; align-items: center; gap: 8px; padding: 14px 28px; border-radius: 12px; background: #19D3C5; color: white; border: none; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.ma-btn-save:hover { background: #14b5a8; }
.ma-btn-export { display: flex; align-items: center; gap: 8px; padding: 14px 28px; border-radius: 12px; background: white; color: #374151; border: 1px solid #E2E8F0; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.ma-btn-export:hover { border-color: #19D3C5; color: #19D3C5; }

@media (max-width: 1024px) {
    .ma-header { flex-direction: column; gap: 20px; }
    .ma-stats-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
    .ma-stats-row { grid-template-columns: repeat(2, 1fr); }
    .ma-list-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    .ma-filter-btns { flex-wrap: wrap; }
}
</style>
@endsection