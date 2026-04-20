@extends('layouts.app')

@section('title', 'Man Power - Detalle de Grupo')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Grupo de Trabajo</h1>
            <p class="page-subtitle">{{ $grupo['nombre'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('man-power.grupos') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
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
                    <span class="badge badge-{{ $grupo['estado'] ?? 'active' }}">{{ $grupo['estado'] ?? 'activo' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Personal Asignado</h3>
        </div>
        <div class="card-body">
            <p class="detail-value">{{ count($grupo['personal'] ?? []) }} personas</p>
        </div>
    </div>
</div>

@if(!empty($grupo['personal']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Personal</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Cargo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($grupo['personal'] as $persona)
                    <tr>
                        <td>{{ $persona['id'] ?? '-' }}</td>
                        <td>{{ $persona['nombre'] ?? '-' }}</td>
                        <td>{{ $persona['cargo'] ?? '-' }}</td>
                        <td>
                            <form action="{{ route('man-power.quitar-personal', $grupo['id']) }}" method="POST" style="display:inline;">
                                @csrf
                                <input type="hidden" name="trabajador_id" value="{{ $persona['id'] }}">
                                <button type="submit" class="btn btn-sm btn-outline danger">Quitar</button>
                            </form>
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
            <h3>Grupo no encontrado</h3>
            <p>El grupo que buscas no existe</p>
        </div>
    </div>
</div>
@endif
@endsection