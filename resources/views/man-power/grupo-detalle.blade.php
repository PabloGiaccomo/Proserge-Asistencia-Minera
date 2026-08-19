@extends('layouts.app')

@section('title', 'Man Power - Detalle de Grupo')

@php
    use App\Support\Rbac\PermissionMatrix;

    $permissions = session('user.permissions', []);
    $canAssignManPower = PermissionMatrix::allowsDirect($permissions, 'man_power', 'asignar');
    $canRegisterAttendance = PermissionMatrix::allowsDirect($permissions, 'asistencias', 'registrar');
    $detalles = collect($grupo['detalle'] ?? $grupo['personal'] ?? []);
    $supervisor = $grupo['supervisor']['nombre_completo'] ?? $grupo['supervisor']['nombre'] ?? '-';
    $title = $grupo['nombre_snapshot'] ?? $grupo['servicio'] ?? 'Grupo de trabajo';
@endphp

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Grupo de Trabajo</h1>
            <p class="page-subtitle">{{ $title }}</p>
        </div>
        <div class="page-actions">
            @if($canRegisterAttendance && !empty($grupo['id']))
                <a href="{{ route('asistencia.marcar', $grupo['id']) }}" class="btn btn-primary">Tomar lista</a>
            @endif
            <a href="{{ route('man-power.grupos', [
                'rq_mina_id' => $grupo['rq_mina_id'] ?? null,
                'plan_id' => $grupo['rq_mina_plan_id'] ?? null,
                'fecha' => $grupo['fecha'] ?? null,
                'turno' => $grupo['turno'] ?? null,
            ]) }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if($grupo)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Informacion del grupo</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Fecha / turno</span>
                    <span class="detail-value">{{ $grupo['fecha'] ?? '-' }} - {{ $grupo['turno'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Responsable</span>
                    <span class="detail-value">{{ $supervisor }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Area / paradero</span>
                    <span class="detail-value">{{ $grupo['area'] ?? '-' }} - {{ $grupo['paradero'] ?? 'Sin paradero' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge">{{ $grupo['estado'] ?? 'BORRADOR' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Plan</span>
                    <span class="detail-value">{{ $grupo['plan']['codigo'] ?? $grupo['rq_mina_plan_id'] ?? 'Legacy' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Grupo operativo</span>
                    <span class="detail-value">{{ $grupo['grupo_operativo']['nombre'] ?? $grupo['nombre_snapshot'] ?? 'Sin trazabilidad' }}</span>
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
                    <span class="detail-label">Requerido</span>
                    <span class="detail-value">{{ $grupo['cantidad_planificada_snapshot'] ?? 0 }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Integrantes</span>
                    <span class="detail-value">{{ $detalles->where('estado_distribucion', 'ASIGNADO')->count() ?: $detalles->count() }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Supervisor operativo</span>
                    <span class="detail-value">{{ $grupo['supervisor_operativo_snapshot'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Supervisor seguridad</span>
                    <span class="detail-value">{{ $grupo['supervisor_seguridad_snapshot'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Integrantes</h3>
    </div>
    <div class="card-body">
        @if($detalles->isEmpty())
            <div class="empty-state">
                <h3>Grupo sin integrantes</h3>
                <p>Agrega personal desde la vista principal de Man Power.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>DNI</th>
                            <th>Cargo</th>
                            <th>Posicion</th>
                            <th>Tipo</th>
                            <th>Distribucion</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detalles as $detalle)
                            @php
                                $persona = $detalle['personal'] ?? [];
                                $personalId = $detalle['personal_id'] ?? $persona['id'] ?? '';
                                $estadoDistribucion = $detalle['estado_distribucion'] ?? 'ASIGNADO';
                            @endphp
                            <tr>
                                <td>{{ $persona['nombre_completo'] ?? $detalle['nombre_completo'] ?? '-' }}</td>
                                <td>{{ $persona['dni'] ?? $persona['numero_documento'] ?? '-' }}</td>
                                <td>{{ $detalle['puesto_asignado_snapshot'] ?? $detalle['cargo'] ?? $persona['puesto'] ?? '-' }}</td>
                                <td>{{ $detalle['posicion_asignacion_snapshot'] ?? $detalle['posicion'] ?? 'SIN CLASIFICAR' }}</td>
                                <td>{{ $detalle['tipo_asignacion_snapshot'] ?? $detalle['tipo'] ?? 'SIN TIPO' }}</td>
                                <td>{{ $estadoDistribucion }}</td>
                                <td>
                                    @if($canAssignManPower && $estadoDistribucion === 'ASIGNADO')
                                        @if(!empty($detalle['id']))
                                            <form action="{{ route('man-power.retirar-personal', [$grupo['id'], $detalle['id']]) }}" method="POST" style="display:inline;">
                                                @csrf
                                                <input type="hidden" name="motivo" value="Retiro operativo desde detalle de Man Power">
                                                <button type="submit" class="btn btn-sm btn-outline danger">Retirar</button>
                                            </form>
                                        @else
                                            <form action="{{ route('man-power.quitar-personal', $grupo['id']) }}" method="POST" style="display:inline;">
                                                @csrf
                                                <input type="hidden" name="personal_id" value="{{ $personalId }}">
                                                <button type="submit" class="btn btn-sm btn-outline danger">Quitar</button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
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
@else
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <h3>Grupo no encontrado</h3>
            <p>El grupo que buscas no existe.</p>
        </div>
    </div>
</div>
@endif
@endsection
