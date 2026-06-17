@extends('layouts.app')

@section('title', 'RQ Proserge - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Solicitud</h1>
            <p class="page-subtitle">RQ Proserge #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('rq-proserge.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if($item)
    @if(!empty($item['cambios']))
        <div class="card" style="border:1px solid #fed7aa;background:#fff7ed;">
            <div class="card-body">
                <strong style="color:#9a3412;">Cambios desde RQ Mina</strong>
                <ul style="margin:8px 0 0;color:#9a3412;">
                    @foreach($item['cambios'] as $cambio)
                        <li>{{ $cambio['mensaje'] ?? 'Cambio pendiente' }} @if(!empty($cambio['fecha']))({{ $cambio['fecha'] }})@endif</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Informacion general</h3>
            </div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">RQ Mina relacionado</span>
                        <span class="detail-value">{{ $item['rq_mina_id'] ?? '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Mina / lugar</span>
                        <span class="detail-value">{{ $item['destino_nombre'] ?? $item['mina'] ?? '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Area / parada</span>
                        <span class="detail-value">{{ $item['area'] ?? '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Estado</span>
                        <span class="badge badge-{{ strtolower($item['estado'] ?? 'secondary') }}">{{ $item['estado'] ?? 'PENDIENTE' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Cobertura</h3>
            </div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Solicitado</span>
                        <span class="detail-value">{{ $item['solicitado'] ?? 0 }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Atendido</span>
                        <span class="detail-value">{{ $item['atendido'] ?? 0 }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Periodo</span>
                        <span class="detail-value">{{ $item['fecha_inicio'] ?? '-' }} a {{ $item['fecha_fin'] ?? '-' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Puestos solicitados</h3>
        </div>
        <div class="card-body">
            @if(!empty($item['puestos']))
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Puesto</th>
                                <th>Requeridos</th>
                                <th>Asignados</th>
                                <th>Cambios</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($item['puestos'] as $puesto)
                                <tr>
                                    <td>{{ $puesto['nombre'] ?? '-' }}</td>
                                    <td>{{ $puesto['requeridos'] ?? 0 }}</td>
                                    <td>{{ $puesto['asignados'] ?? 0 }}</td>
                                    <td>
                                        @forelse($puesto['cambios'] ?? [] as $cambio)
                                            <div>{{ $cambio['mensaje'] ?? 'Cambio pendiente' }}</div>
                                        @empty
                                            -
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted">No hay puestos registrados.</p>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Personal seleccionado para la parada</h3>
        </div>
        <div class="card-body">
            @php
                $personal = collect($item['puestos'] ?? [])->flatMap(fn ($puesto) => $puesto['personal_asignado'] ?? [])->values();
            @endphp
            @if($personal->isNotEmpty())
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Cargo en la parada</th>
                                <th>Inicio</th>
                                <th>Fin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($personal as $persona)
                                <tr>
                                    <td>{{ $persona['nombre'] ?? '-' }}</td>
                                    <td>{{ $persona['comentario'] ?? '-' }}</td>
                                    <td>{{ $persona['fecha_inicio'] ?? '-' }}</td>
                                    <td>{{ $persona['fecha_fin'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted">No hay personal seleccionado para esta parada todavia.</p>
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
