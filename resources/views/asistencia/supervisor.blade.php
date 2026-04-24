@extends('layouts.app')

@section('title', 'Asistencia - Por Supervisor')

@php
                <div class="asistencia-table-header">
                    <span>Supervisor</span>
                    <span>Mina</span>
                    <span>Grupos</span>
                    <span>Personal</span>
                    <span>Asistencia</span>
                    <span>Faltas</span>
                    <span>Evaluación</span>
                </div>
                @foreach($supervisores as $sup)
                <div class="asistencia-table-row">
                    <span class="destino-nombre">{{ $sup['nombre'] }}</span>
                    <span>{{ $sup['mina'] }}</span>
                    <span>{{ $sup['grupos'] }}</span>
                    <span>{{ $sup['personal'] }}</span>
                    <span class="porcentaje {{ $sup['porcentaje'] >= 90 ? 'porcentaje-alto' : ($sup['porcentaje'] >= 80 ? 'porcentaje-medio' : 'porcentaje-bajo') }}">{{ $sup['porcentaje'] }}%</span>
                    <span class="texto-ausente">{{ $sup['faltas_acumuladas'] }}</span>
                    <span class="texto-success">{{ number_format($sup['promedio_eval'], 1) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.supervisor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.supervisor-card {
    background: #F8FAFC;
    border-radius: 16px;
    padding: 20px;
    border: 1px solid #E2E8F0;
}

.supervisor-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.supervisor-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--color-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}

.supervisor-info {
    flex: 1;
}

.supervisor-nombre {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text);
}

.supervisor-meta {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.supervisor-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid #E2E8F0;
    margin-bottom: 12px;
}

.supervisor-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--color-text);
}

.stat-label {
    font-size: 11px;
    color: var(--color-text-secondary);
}

.supervisor-eval {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.eval-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary);
}

.eval-stars {
    display: flex;
    align-items: center;
    gap: 2px;
}

.eval-stars svg {
    color: #FBBF24;
}

.star-filled {
    fill: currentColor;
}

.eval-value {
    margin-left: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text);
}
</style>
@endpush