@extends('layouts.app')

@section('title', 'Detalle de contrato - Proserge')

@section('content')
@php
    $formatDate = function ($date): string {
        if (!$date) {
            return 'Sin fecha';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return 'Sin fecha';
        }
    };

    $formatDateTime = function ($date): string {
        if (!$date) {
            return 'Sin fecha';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return 'Sin fecha';
        }
    };

    $trabajadorSnapshot = $snapshot['trabajador'] ?? [];
    $contratoDatosSnapshot = $snapshot['datos_contrato'] ?? [];
    $fichaSnapshot = $snapshot['ficha'] ?? [];
    $fichaDatos = $fichaSnapshot['datos'] ?? [];
    $familiares = collect($fichaSnapshot['familiares'] ?? []);
    $documentos = collect($snapshot['documentos'] ?? []);
    $usuarioProserge = $snapshot['usuario_proserge'] ?? [];
    $minas = collect($snapshot['minas_sedes'] ?? []);
    $bienestar = collect($snapshot['bienestar'] ?? []);
    $grupos = collect(data_get($snapshot, 'paradas_y_asignaciones.grupos_trabajo', []));
    $rqProserge = collect(data_get($snapshot, 'paradas_y_asignaciones.rq_proserge', []));
    $asistencia = collect($snapshot['asistencia'] ?? []);
    $faltas = collect($snapshot['faltas'] ?? []);
    $evaluacionesDesempeno = collect(data_get($snapshot, 'evaluaciones.desempeno', []));
    $evaluacionesSupervisor = collect(data_get($snapshot, 'evaluaciones.supervisor', []));

    $value = fn ($array, string $key, $fallback = '-') => filled(data_get($array, $key)) ? data_get($array, $key) : $fallback;
    $periodoInicio = $contrato->fecha_inicio ?: data_get($snapshot, 'rango.fecha_inicio');
    $periodoFin = $contrato->fecha_fin ?: data_get($snapshot, 'rango.fecha_fin');
@endphp

<style>
.contract-detail-page {
    display: grid;
    gap: 16px;
}
.contract-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
}
.contract-detail-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}
.contract-detail-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
}
.contract-detail-value {
    color: #0f172a;
    font-weight: 700;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.contract-section {
    display: grid;
    gap: 12px;
}
.contract-section-title {
    margin: 0;
    font-size: 17px;
    color: #0f172a;
}
.contract-snapshot-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 720px;
}
.contract-snapshot-table th,
.contract-snapshot-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 10px;
    text-align: left;
    vertical-align: top;
}
.contract-snapshot-table th {
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
}
.contract-table-scroll {
    overflow-x: auto;
}
.contract-empty {
    margin: 0;
    color: #64748b;
}
.contract-json {
    max-height: 420px;
    overflow: auto;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    font-size: 12px;
}
</style>

<div class="module-page contract-detail-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Contrato #{{ $contrato->contrato_numero }}</h1>
                <p class="page-subtitle">{{ $personal->nombre_completo }} - {{ $formatDate($periodoInicio) }} al {{ $periodoFin ? $formatDate($periodoFin) : 'Vigente' }}</p>
            </div>
            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.contratos.index', $personal->id) }}" class="btn btn-outline btn-sm">Contratos</a>
                <a href="{{ route('personal.documentos.index', $personal->id) }}" class="btn btn-outline btn-sm">Documentos</a>
                <a href="{{ route('personal.index') }}" class="btn btn-primary btn-sm">Volver</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Resumen del contrato</span>
        </div>
        <div class="card-body contract-detail-grid">
            <div class="contract-detail-item">
                <div class="contract-detail-label">Estado</div>
                <div class="contract-detail-value">{{ ucfirst(strtolower($contrato->estado)) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Contrato firmado</div>
                <div class="contract-detail-value">
                    @if($contrato->hasSignedFile())
                        {{ $contrato->signed_contract_original_name ?: 'Contrato firmado.pdf' }} - {{ $formatDateTime($contrato->signed_at) }}
                    @else
                        {{ $contrato->archivo_pendiente_regularizacion ? 'Pendiente de regularizacion' : 'No registrado en este contrato' }}
                    @endif
                </div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Inicio</div>
                <div class="contract-detail-value">{{ $formatDate($periodoInicio) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Origen</div>
                <div class="contract-detail-value">{{ $contrato->origen_registro ?: 'NUEVO' }}{{ $contrato->es_historico ? ' / historico' : '' }}</div>
            </div>
            @if($contrato->tipo_movimiento)
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Movimiento</div>
                    <div class="contract-detail-value">{{ ucfirst(strtolower($contrato->tipo_movimiento)) }}</div>
                </div>
            @endif
            @if($contrato->origen_contrato_id)
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Contrato base</div>
                    <div class="contract-detail-value">Creado desde contrato anterior</div>
                </div>
            @endif
            @if($contrato->observacion_renovacion)
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Observacion de movimiento</div>
                    <div class="contract-detail-value">{{ $contrato->observacion_renovacion }}</div>
                </div>
            @endif
            @if($contrato->observacion_historica)
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Observacion historica</div>
                    <div class="contract-detail-value">{{ $contrato->observacion_historica }}</div>
                </div>
            @endif
            <div class="contract-detail-item">
                <div class="contract-detail-label">Fin</div>
                <div class="contract-detail-value">{{ $periodoFin ? $formatDate($periodoFin) : 'Vigente' }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Motivo de cese</div>
                <div class="contract-detail-value">{{ $contrato->motivo_cese ?: data_get($snapshot, 'extra.motivo_cese', '-') }}</div>
            </div>
            @if(strtoupper((string) $contrato->estado) === 'ANULADO')
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Motivo de anulacion</div>
                    <div class="contract-detail-value">{{ $contrato->motivo_anulacion ?: '-' }}</div>
                </div>
            @endif
            <div class="contract-detail-item">
                <div class="contract-detail-label">Activado por</div>
                <div class="contract-detail-value">{{ $contrato->activadoPor?->personal?->nombre_completo ?: $contrato->activadoPor?->email ?: 'No registrado' }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Cerrado por</div>
                <div class="contract-detail-value">{{ $contrato->cerradoPor?->personal?->nombre_completo ?: $contrato->cerradoPor?->email ?: 'No registrado' }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Snapshot guardado</div>
                <div class="contract-detail-value">{{ $formatDateTime(data_get($snapshot, 'capturado_at')) }}</div>
            </div>
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Datos del trabajador</h2></div>
        <div class="card-body contract-detail-grid">
            <div class="contract-detail-item">
                <div class="contract-detail-label">Nombre</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'nombre_completo', $personal->nombre_completo) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Documento</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'tipo_documento', 'DNI') }} {{ $value($trabajadorSnapshot, 'numero_documento', $value($trabajadorSnapshot, 'dni')) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Puesto</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'puesto') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Ocupacion</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'ocupacion') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Telefono</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'telefono_1', $value($trabajadorSnapshot, 'telefono')) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Correo</div>
                <div class="contract-detail-value">{{ $value($trabajadorSnapshot, 'correo') }}</div>
            </div>
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Ficha guardada</h2></div>
        <div class="card-body contract-detail-grid">
            <div class="contract-detail-item">
                <div class="contract-detail-label">Contrato</div>
                <div class="contract-detail-value">{{ $value($fichaDatos, 'contrato', $value($trabajadorSnapshot, 'contrato')) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Fecha ingreso</div>
                <div class="contract-detail-value">{{ $formatDate($value($fichaDatos, 'fecha_ingreso', $value($trabajadorSnapshot, 'fecha_ingreso', null))) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Fecha fin contrato</div>
                <div class="contract-detail-value">{{ $formatDate($value($fichaDatos, 'fecha_fin_contrato', $periodoFin)) }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Direccion</div>
                <div class="contract-detail-value">{{ $value($fichaDatos, 'domicilio_direccion') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Banco</div>
                <div class="contract-detail-value">{{ $value($fichaDatos, 'banco') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Cuenta</div>
                <div class="contract-detail-value">{{ $value($fichaDatos, 'numero_cuenta') }}</div>
            </div>
            @if(!empty($contratoDatosSnapshot))
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Sueldo</div>
                    <div class="contract-detail-value">{{ $value($contratoDatosSnapshot, 'sueldo_num') }} {{ $value($contratoDatosSnapshot, 'sueldo_texto', '') }}</div>
                </div>
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Periodo de prueba</div>
                    <div class="contract-detail-value">{{ $formatDate($value($contratoDatosSnapshot, 'periodo_prueba_inicio', null)) }} al {{ $formatDate($value($contratoDatosSnapshot, 'periodo_prueba_fin', null)) }}</div>
                </div>
                <div class="contract-detail-item contract-data-wide">
                    <div class="contract-detail-label">Funciones</div>
                    <div class="contract-detail-value">{{ $value($contratoDatosSnapshot, 'funciones') }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Usuario Proserge</h2></div>
        <div class="card-body contract-detail-grid">
            <div class="contract-detail-item">
                <div class="contract-detail-label">Tiene usuario</div>
                <div class="contract-detail-value">{{ data_get($usuarioProserge, 'tiene_usuario') ? 'Si' : 'No' }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Correo de acceso</div>
                <div class="contract-detail-value">{{ data_get($usuarioProserge, 'usuario.email', '-') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Estado usuario</div>
                <div class="contract-detail-value">{{ data_get($usuarioProserge, 'usuario.estado', '-') }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Rol principal</div>
                <div class="contract-detail-value">{{ data_get($usuarioProserge, 'usuario.rol.nombre', '-') }}</div>
            </div>
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Minas, sedes y documentos</h2></div>
        <div class="card-body">
            <div class="contract-detail-grid" style="margin-bottom:14px;">
                @forelse($minas as $mina)
                    <div class="contract-detail-item">
                        <div class="contract-detail-label">{{ $mina['nombre'] ?? 'Ubicacion' }}</div>
                        <div class="contract-detail-value">{{ $mina['estado_relacion'] ?? '-' }}</div>
                    </div>
                @empty
                    <p class="contract-empty">No se guardaron ubicaciones en este contrato.</p>
                @endforelse
            </div>

            @if($documentos->isEmpty())
                <p class="contract-empty">No se guardaron documentos en el snapshot.</p>
            @else
                <div class="contract-table-scroll">
                    <table class="contract-snapshot-table">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Archivo</th>
                                <th>Tamano</th>
                                <th>Fecha carga</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($documentos as $documento)
                                <tr>
                                    <td>{{ $documento['tipo'] ?? '-' }}</td>
                                    <td>{{ $documento['nombre_original'] ?? '-' }}</td>
                                    <td>{{ (int) ($documento['size'] ?? 0) }} bytes</td>
                                    <td>{{ $formatDateTime($documento['created_at'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Familiares y bienestar</h2></div>
        <div class="card-body">
            <div class="contract-detail-grid" style="margin-bottom:14px;">
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Familiares</div>
                    <div class="contract-detail-value">{{ $familiares->count() }}</div>
                </div>
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Bloqueos bienestar</div>
                    <div class="contract-detail-value">{{ $bienestar->count() }}</div>
                </div>
            </div>
            @if($familiares->isNotEmpty())
                <div class="contract-table-scroll">
                    <table class="contract-snapshot-table">
                        <thead>
                            <tr>
                                <th>Parentesco</th>
                                <th>Nombres</th>
                                <th>Documento</th>
                                <th>Telefono</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($familiares as $familiar)
                                <tr>
                                    <td>{{ $familiar['parentesco'] ?? '-' }}</td>
                                    <td>{{ $familiar['nombres_apellidos'] ?? '-' }}</td>
                                    <td>{{ trim(($familiar['tipo_documento'] ?? '') . ' ' . ($familiar['numero_documento'] ?? '')) ?: '-' }}</td>
                                    <td>{{ $familiar['telefono'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Paradas y asignaciones</h2></div>
        <div class="card-body">
            <div class="contract-detail-grid" style="margin-bottom:14px;">
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Grupos de trabajo</div>
                    <div class="contract-detail-value">{{ $grupos->count() }}</div>
                </div>
                <div class="contract-detail-item">
                    <div class="contract-detail-label">RQ Proserge</div>
                    <div class="contract-detail-value">{{ $rqProserge->count() }}</div>
                </div>
                <div class="contract-detail-item">
                    <div class="contract-detail-label">Asistencias</div>
                    <div class="contract-detail-value">{{ $asistencia->count() }}</div>
                </div>
            </div>

            @if($grupos->isNotEmpty())
                <div class="contract-table-scroll" style="margin-bottom:16px;">
                    <table class="contract-snapshot-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Mina</th>
                                <th>Servicio</th>
                                <th>Area</th>
                                <th>Turno</th>
                                <th>Estado asistencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($grupos as $grupo)
                                <tr>
                                    <td>{{ $formatDate($grupo['fecha'] ?? null) }}</td>
                                    <td>{{ $grupo['mina'] ?? '-' }}</td>
                                    <td>{{ $grupo['servicio'] ?? '-' }}</td>
                                    <td>{{ $grupo['area'] ?? '-' }}</td>
                                    <td>{{ $grupo['turno'] ?? '-' }}</td>
                                    <td>{{ $grupo['estado_asistencia'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($rqProserge->isNotEmpty())
                <div class="contract-table-scroll">
                    <table class="contract-snapshot-table">
                        <thead>
                            <tr>
                                <th>Inicio</th>
                                <th>Fin</th>
                                <th>Puesto</th>
                                <th>Estado</th>
                                <th>Comentario</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rqProserge as $rq)
                                <tr>
                                    <td>{{ $formatDate($rq['fecha_inicio'] ?? null) }}</td>
                                    <td>{{ $formatDate($rq['fecha_fin'] ?? null) }}</td>
                                    <td>{{ $rq['puesto_asignado'] ?? '-' }}</td>
                                    <td>{{ $rq['estado'] ?? '-' }}</td>
                                    <td>{{ $rq['comentario'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card contract-section">
        <div class="card-header"><h2 class="contract-section-title">Incidencias y evaluaciones</h2></div>
        <div class="card-body contract-detail-grid">
            <div class="contract-detail-item">
                <div class="contract-detail-label">Faltas</div>
                <div class="contract-detail-value">{{ $faltas->count() }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Evaluaciones de desempeno</div>
                <div class="contract-detail-value">{{ $evaluacionesDesempeno->count() }}</div>
            </div>
            <div class="contract-detail-item">
                <div class="contract-detail-label">Evaluaciones de supervisor</div>
                <div class="contract-detail-value">{{ $evaluacionesSupervisor->count() }}</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Snapshot completo</span></div>
        <div class="card-body">
            <details>
                <summary>Ver datos tecnicos guardados</summary>
                <pre class="contract-json">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    </div>
</div>
@endsection
