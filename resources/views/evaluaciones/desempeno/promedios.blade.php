@extends('layouts.app')

@section('title', 'Evaluaciones - Promedios')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Promedios de Evaluación</h1>
            <p class="page-subtitle">Ver promedios por trabajador</p>
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
        <h3 class="card-title">Promedios por Trabajador</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay datos de evaluación',
                'description' => 'Los promedios se calculan con las evaluaciones registradas'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Evaluaciones</th>
                            <th>Promedio</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $row)
                        <tr>
                            <td>{{ $row['trabajador']['nombre'] ?? $row['nombre'] ?? '-' }}</td>
                            <td>{{ $row['total_evaluaciones'] ?? $row['count'] ?? 0 }}</td>
                            <td>{{ $row['promedio'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection