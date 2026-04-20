@extends('layouts.app')

@section('title', 'Man Power - Detalle de Parada')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Parada</h1>
            <p class="page-subtitle">RQ Mina #{{ $parada['rq_mina_id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('man-power.paradas') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
        </div>
    </div>
</div>

@if($parada)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información de la Parada</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">RQ Mina</span>
                    <span class="detail-value">#{{ $parada['rq_mina_id'] ?? '' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha Inicio</span>
                    <span class="detail-value">{{ $parada['fecha_inicio'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Mina</span>
                    <span class="detail-value">{{ $parada['mina']['nombre'] ?? $parada['mina_nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tipo</span>
                    <span class="detail-value">{{ $parada['tipo'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Estado</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ $parada['estado'] ?? 'secondary' }}">{{ $parada['estado'] ?? 'activa' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Personal Asignado</span>
                    <span class="detail-value">{{ $parada['personal_asignado'] ?? 0 }} personas</span>
                </div>
            </div>
        </div>
    </div>
</div>

@if(!empty($parada['trabajos']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Trabajos Solicitados</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th>Cantidad</th>
                        <th>Unidad</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($parada['trabajos'] as $trabajo)
                    <tr>
                        <td>{{ $trabajo['descripcion'] ?? '-' }}</td>
                        <td>{{ $trabajo['cantidad'] ?? '-' }}</td>
                        <td>{{ $trabajo['unidad'] ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@if(!empty($parada['grupos']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Grupos Asignados</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Grupo</th>
                        <th>Personal</th>
                        <th>Líder</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($parada['grupos'] as $grupo)
                    <tr>
                        <td>{{ $grupo['nombre'] ?? '-' }}</td>
                        <td>{{ $grupo['personal_count'] ?? count($grupo['personal'] ?? []) }}</td>
                        <td>{{ $grupo['lider']['nombre'] ?? $grupo['lider_nombre'] ?? '-' }}</td>
                        <td>
                            <a href="{{ route('man-power.grupo-detalle', $grupo['id']) }}" class="btn btn-sm btn-outline">
                                Ver Grupo
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@else
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <h3>Parada no encontrada</h3>
            <p>La parada que buscas no existe</p>
        </div>
    </div>
</div>
@endif
@endsection