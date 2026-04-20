@extends('layouts.app')

@section('title', 'Roles - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Roles</h1>
                <p class="page-subtitle">Gestión de roles del sistema</p>
            </div>
            <div class="page-actions">
                <a href="#" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Nuevo Rol
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de Roles</span>
            <span class="card-badge">{{ count($data ?? []) }} roles</span>
        </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay roles registrados',
                'description' => 'Los roles se crean desde la configuración del sistema'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Permisos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                        <tr>
                            <td>{{ $item['id'] ?? '-' }}</td>
                            <td>{{ $item['nombre'] ?? $item['name'] ?? '-' }}</td>
                            <td>{{ $item['descripcion'] ?? $item['description'] ?? '-' }}</td>
                            <td>{{ count($item['permisos'] ?? $item['permissions'] ?? []) }}</td>
                            <td>
                                <a href="{{ route('seguridad.roles.show', $item['id']) }}" class="btn btn-sm btn-outline">
                                    Ver
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection