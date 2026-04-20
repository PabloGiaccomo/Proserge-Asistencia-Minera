@extends('layouts.app')

@section('title', 'Faltas - Listado')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Faltas</h1>
            <p class="page-subtitle">Control de faltas e inasistencias</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Faltas</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay faltas registradas',
                'description' => 'Las faltas se generan automáticamente desde Asistencia'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Trabajador</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                        <tr>
                            <td>{{ $item['id'] ?? '-' }}</td>
                            <td>{{ $item['fecha'] ?? '-' }}</td>
                            <td>{{ $item['trabajador']['nombre'] ?? $item['trabajador_nombre'] ?? '-' }}</td>
                            <td>{{ $item['tipo'] ?? '-' }}</td>
                            <td>
                                <span class="badge badge-{{ $item['estado'] ?? 'secondary' }}">
                                    {{ $item['estado'] ?? 'pendiente' }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('faltas.show', $item['id']) }}" class="btn btn-sm btn-outline">
                                    Ver
                                </a>
                                @if(($item['estado'] ?? '') == 'pendiente')
                                <a href="{{ route('faltas.corregir', $item['id']) }}" class="btn btn-sm btn-outline">
                                    Corregir
                                </a>
                                @endif
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