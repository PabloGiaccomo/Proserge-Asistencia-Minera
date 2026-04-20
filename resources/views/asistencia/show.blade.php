@extends('layouts.app')

@section('title', 'Asistencia - Detalle de Grupo')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Asistencia</h1>
            <p class="page-subtitle">{{ $grupo['nombre'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('asistencia.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
            @if(!($grupo['cerrado'] ?? false))
            <a href="{{ route('asistencia.marcar', $grupo['id']) }}" class="btn btn-primary">
                Marcar Asistencia
            </a>
            @endif
        </div>
    </div>
</div>

@if($grupo)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información del Grupo</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">ID</span>
                    <span class="detail-value">{{ $grupo['id'] }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nombre</span>
                    <span class="detail-value">{{ $grupo['nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Líder</span>
                    <span class="detail-value">{{ $grupo['lider']['nombre'] ?? $grupo['lider_nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    @if($grupo['cerrado'] ?? false)
                        <span class="badge badge-success">Cerrado</span>
                    @else
                        <span class="badge badge-warning">Abierto</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Acciones</h3>
        </div>
        <div class="card-body">
            @if($grupo['cerrado'] ?? false)
                <form action="{{ route('asistencia.reabrir', $grupo['id']) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-primary">Reabrir</button>
                </form>
            @else
                <form action="{{ route('asistencia.cerrar', $grupo['id']) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-outline">Cerrar Asistencia</button>
                </form>
            @endif
        </div>
    </div>
</div>

@if(!empty($grupo['personal']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Personal del Grupo</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Cargo</th>
                        <th>Asistencia</th>
                        <th>Hora</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($grupo['personal'] as $persona)
                    <tr>
                        <td>{{ $persona['nombre'] ?? '-' }}</td>
                        <td>{{ $persona['cargo'] ?? '-' }}</td>
                        <td>
                            @if(isset($persona['asistencia']) && $persona['asistencia'])
                                <span class="badge badge-success">Presente</span>
                            @else
                                <span class="badge badge-danger">Ausente</span>
                            @endif
                        </td>
                        <td>{{ $persona['hora_asistencia'] ?? '-' }}</td>
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
        @include('components.empty-state', [
            'message' => 'Grupo no encontrado',
            'description' => 'El grupo que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection