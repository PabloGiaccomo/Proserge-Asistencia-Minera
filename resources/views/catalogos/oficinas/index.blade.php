@extends('layouts.app')

@section('title', 'Catálogos - Oficinas')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Oficinas</h1>
            <p class="page-subtitle">Catálogo de oficinas</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Oficinas</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay oficinas registradas',
                'description' => 'Las oficinas se crean desde la configuración del sistema'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Ubicación</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                        <tr>
                            <td>{{ $item['id'] ?? '-' }}</td>
                            <td>{{ $item['nombre'] ?? $item['name'] ?? '-' }}</td>
                            <td>{{ $item['ubicacion'] ?? $item['location'] ?? '-' }}</td>
                            <td>
                                <span class="badge badge-{{ ($item['activo'] ?? $item['active'] ?? true) ? 'success' : 'danger' }}">
                                    {{ ($item['activo'] ?? $item['active'] ?? true) ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('catalogos.oficinas.show', $item['id']) }}" class="btn btn-sm btn-outline">
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