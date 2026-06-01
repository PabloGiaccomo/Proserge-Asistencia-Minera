@extends('layouts.app')

@section('title', 'Contratos del trabajador - Proserge')

@section('content')
@php
    $formatDate = function ($date): string {
        if (!$date) {
            return 'Sin fecha';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return 'Sin fecha';
        }
    };

    $estadoClass = fn (string $estado): string => strtoupper($estado) === 'ACTIVO' ? 'contract-status-active' : 'contract-status-closed';
@endphp

<style>
.contract-page {
    display: grid;
    gap: 16px;
}
.contract-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
}
.contract-summary-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}
.contract-summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.contract-summary-value {
    color: #0f172a;
    font-size: 18px;
    font-weight: 800;
    margin-top: 4px;
}
.contract-table-wrap {
    overflow-x: auto;
}
.contract-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 860px;
}
.contract-table th,
.contract-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 12px;
    text-align: left;
    vertical-align: top;
}
.contract-table th {
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.contract-status {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 800;
}
.contract-status-active {
    background: #dcfce7;
    color: #166534;
}
.contract-status-closed {
    background: #fee2e2;
    color: #991b1b;
}
.contract-reason {
    max-width: 320px;
    color: #475569;
    line-height: 1.4;
}
</style>

<div class="module-page contract-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Contratos laborales</h1>
                <p class="page-subtitle">{{ $personal->nombre_completo }} - {{ $personal->tipo_documento ?: 'DNI' }} {{ $personal->numero_documento ?: $personal->dni }}</p>
            </div>
            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.documentos.index', $personal->id) }}" class="btn btn-outline btn-sm">Documentos</a>
                <a href="{{ route('personal.edit', $personal->id) }}" class="btn btn-outline btn-sm">Editar trabajador</a>
                <a href="{{ route('personal.index') }}" class="btn btn-primary btn-sm">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="contract-summary-grid">
        <div class="contract-summary-item">
            <div class="contract-summary-label">Contratos registrados</div>
            <div class="contract-summary-value">{{ $contratos->count() }}</div>
        </div>
        <div class="contract-summary-item">
            <div class="contract-summary-label">Contratos cerrados</div>
            <div class="contract-summary-value">{{ $contratos->where('estado', 'CERRADO')->count() }}</div>
        </div>
        <div class="contract-summary-item">
            <div class="contract-summary-label">Estado actual</div>
            <div class="contract-summary-value">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Historial de contratos</span>
        </div>
        <div class="card-body">
            @if($contratos->isEmpty())
                <p class="text-muted" style="margin:0;">Aun no hay contratos guardados para este trabajador. El primer cierre o activacion creara el historial.</p>
            @else
                <div class="contract-table-wrap">
                    <table class="contract-table">
                        <thead>
                            <tr>
                                <th>Contrato</th>
                                <th>Periodo</th>
                                <th>Estado</th>
                                <th>Motivo de cese</th>
                                <th>Activado por</th>
                                <th>Cerrado por</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contratos as $contrato)
                                @php
                                    $inicio = $formatDate($contrato->fecha_inicio);
                                    $fin = $contrato->fecha_fin ? $formatDate($contrato->fecha_fin) : 'Vigente';
                                    $activadoPor = $contrato->activadoPor?->personal?->nombre_completo ?: $contrato->activadoPor?->email ?: 'No registrado';
                                    $cerradoPor = $contrato->cerradoPor?->personal?->nombre_completo ?: $contrato->cerradoPor?->email ?: 'No registrado';
                                @endphp
                                <tr>
                                    <td>
                                        <strong>Contrato #{{ $contrato->contrato_numero }}</strong>
                                        @if($contrato->origen_contrato_id)
                                            <div class="text-muted">Activado desde contrato anterior</div>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('personal.contratos.show', [$personal->id, $contrato->id]) }}">{{ $inicio }} al {{ $fin }}</a>
                                    </td>
                                    <td>
                                        <span class="contract-status {{ $estadoClass($contrato->estado) }}">{{ ucfirst(strtolower($contrato->estado)) }}</span>
                                    </td>
                                    <td><div class="contract-reason">{{ $contrato->motivo_cese ?: '-' }}</div></td>
                                    <td>{{ $activadoPor }}</td>
                                    <td>{{ $cerradoPor }}</td>
                                    <td style="text-align:right;">
                                        <a href="{{ route('personal.contratos.show', [$personal->id, $contrato->id]) }}" class="btn btn-outline btn-xs">Ver detalle</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
