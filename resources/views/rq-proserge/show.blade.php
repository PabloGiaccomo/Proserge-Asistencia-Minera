@extends('layouts.app')

@section('title', 'RQ Proserge - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Solicitud</h1>
            <p class="page-subtitle">RQ Proserge #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('rq-proserge.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
            <a href="{{ route('rq-proserge.edit', $item['id']) }}" class="btn btn-primary">
                Editar
            </a>
        </div>
    </div>
</div>

@if($item)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información General</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">ID</span>
                    <span class="detail-value">{{ $item['id'] }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">RQ Mina relacionado</span>
                    <span class="detail-value">{{ $item['rq_mina_id'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha</span>
                    <span class="detail-value">{{ $item['fecha_creacion'] ?? $item['created_at'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ $item['estado'] ?? 'secondary' }}">{{ $item['estado'] ?? 'pendiente' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Ubicación</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Origen</span>
                    <span class="detail-value">{{ $item['origen_nombre'] ?? $item['origen']['nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Destino</span>
                    <span class="detail-value">{{ $item['destino_nombre'] ?? $item['destino']['nombre'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Trabajos</h3>
    </div>
    <div class="card-body">
        @if(!empty($item['trabajos']))
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
                    @foreach($item['trabajos'] as $trabajo)
                    <tr>
                        <td>{{ $trabajo['descripcion'] ?? '-' }}</td>
                        <td>{{ $trabajo['cantidad'] ?? '-' }}</td>
                        <td>{{ $trabajo['unidad'] ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted">No hay trabajos registrados</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Personal Seleccionado para la Parada</h3>
    </div>
    <div class="card-body">
        @if(!empty($item['personal_parada']))
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Puesto</th>
                            <th>Cargo en la Parada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($item['personal_parada'] as $persona)
                        <tr>
                            <td>{{ $persona['nombre'] ?? '-' }}</td>
                            <td>{{ $persona['puesto'] ?? '-' }}</td>
                            <td>{{ $persona['cargo_parada'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">No hay personal seleccionado para esta parada todavia.</p>
        @endif
    </div>
</div>
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Solicitud no encontrada',
            'description' => 'La solicitud que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection