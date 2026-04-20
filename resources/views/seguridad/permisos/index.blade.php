@extends('layouts.app')

@section('title', 'Seguridad - Permisos')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Permisos</h1>
            <p class="page-subtitle">Listado de permisos del sistema</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Permisos</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay permisos registrados',
                'description' => 'Los permisos se crean desde la configuración del sistema'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Módulo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                        <tr>
                            <td>{{ $item['id'] ?? '-' }}</td>
                            <td>{{ $item['nombre'] ?? $item['name'] ?? '-' }}</td>
                            <td>{{ $item['descripcion'] ?? $item['description'] ?? '-' }}</td>
                            <td>{{ $item['modulo'] ?? $item['module'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection