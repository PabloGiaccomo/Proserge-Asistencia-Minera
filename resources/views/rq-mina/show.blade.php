@extends('layouts.app')

@section('title', 'RQ Mina - Detalle')

@section('content')
@php
    $detalle = $item['detalle'] ?? [];
    $personalParada = $item['personal_parada'] ?? [];
    $totalSolicitado = array_sum(array_map(static fn ($d) => (int) ($d['cantidad'] ?? 0), $detalle));
@endphp

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Solicitud</h1>
            <p class="page-subtitle">RQ Mina #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('rq-mina.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
            <a href="{{ route('rq-mina.edit', $item['id']) }}" class="btn btn-primary">
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
                    <span class="detail-label">Mina</span>
                    <span class="detail-value">{{ $item['mina'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Area / Parada</span>
                    <span class="detail-value">{{ $item['area'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha Inicio</span>
                    <span class="detail-value">{{ $item['fecha_inicio'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha Fin</span>
                    <span class="detail-value">{{ $item['fecha_fin'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Creador</span>
                    <span class="detail-value">{{ $item['creador'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ $item['estado'] ?? 'secondary' }}">{{ ucfirst($item['estado'] ?? 'borrador') }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total solicitado</span>
                    <span class="detail-value">{{ $totalSolicitado }} personas</span>
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
                    <span class="detail-label">Puestos requeridos</span>
                    <span class="detail-value">{{ count($detalle) }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Observaciones</span>
                    <span class="detail-value">{{ $item['observaciones'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detalle del RQ Mina (Puestos Solicitados)</h3>
    </div>
    <div class="card-body">
        @if(!empty($detalle))
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Puesto</th>
                            <th>Cantidad Solicitada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detalle as $linea)
                        <tr>
                            <td>{{ $linea['puesto'] ?? '-' }}</td>
                            <td>{{ $linea['cantidad'] ?? 0 }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">No hay puestos registrados para este RQ Mina.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Personal Seleccionado para la Parada</h3>
    </div>
    <div class="card-body">
        @if(($item['estado'] ?? '') !== 'enviado')
            <p class="text-muted">El RQ Mina aun no fue enviado. Cuando se envie y exista seleccion desde RQ Proserge, el personal aparecera aqui.</p>
        @elseif(!empty($personalParada))
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
                        @foreach($personalParada as $persona)
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
            <p class="text-muted">El RQ Mina fue enviado, pero aun no hay personal seleccionado en RQ Proserge para esta parada.</p>
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