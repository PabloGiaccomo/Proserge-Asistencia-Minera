@extends('layouts.app')

@section('title', 'Evaluaciones - Comparación')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Comparación de Evaluaciones</h1>
            <p class="page-subtitle">Comparar evaluaciones por período</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('evaluaciones.desempeno.index') }}" class="btn btn-outline">
                Volver
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Resultados de Comparación</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay datos para comparar',
                'description' => 'Los datos de comparación se generan con las evaluaciones registradas'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Período Anterior</th>
                            <th>Período Actual</th>
                            <th>Variación</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $row)
                        <tr>
                            <td>{{ $row['trabajador']['nombre'] ?? $row['nombre'] ?? '-' }}</td>
                            <td>{{ $row['anterior'] ?? '-' }}</td>
                            <td>{{ $row['actual'] ?? '-' }}</td>
                            <td>
                                @if(isset($row['variacion']))
                                    <span class="badge badge-{{ $row['variacion'] > 0 ? 'success' : ($row['variacion'] < 0 ? 'danger' : 'secondary') }}">
                                        {{ $row['variacion'] > 0 ? '+' : '' }}{{ $row['variacion'] }}
                                    </span>
                                @else
                                    -
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