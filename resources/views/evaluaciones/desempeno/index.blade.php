@extends('layouts.app')

@section('title', 'Evaluaciones de Desempeño')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Evaluaciones de Desempeño</h1>
            <p class="page-subtitle">Gestión de evaluaciones de desempeño</p>
        </div>
        <div>
            <a href="{{ route('evaluaciones.desempeno.promedios') }}" class="btn btn-outline">
                Ver Promedios
            </a>
            <a href="{{ route('evaluaciones.desempeno.create') }}" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Nueva Evaluación
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Evaluaciones</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay evaluaciones registradas',
                'description' => 'Crea una nueva evaluación para comenzar'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Evaluado</th>
                            <th>Tipo</th>
                            <th>Score</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                        <tr>
                            <td>{{ $item['id'] ?? '-' }}</td>
                            <td>{{ $item['fecha_evaluacion'] ?? $item['created_at'] ?? '-' }}</td>
                            <td>{{ $item['evaluado']['nombre'] ?? $item['evaluado_nombre'] ?? '-' }}</td>
                            <td>{{ $item['tipo'] ?? '-' }}</td>
                            <td>{{ $item['score'] ?? '-' }}</td>
                            <td>
                                <span class="badge badge-{{ $item['estado'] ?? 'secondary' }}">
                                    {{ $item['estado'] ?? 'pendiente' }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('evaluaciones.desempeno.show', $item['id']) }}" class="btn btn-sm btn-outline">
                                    Ver
                                </a>
                                <a href="{{ route('evaluaciones.desempeno.edit', $item['id']) }}" class="btn btn-sm btn-outline">
                                    Editar
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