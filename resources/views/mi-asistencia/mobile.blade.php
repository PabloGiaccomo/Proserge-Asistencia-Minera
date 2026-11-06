@extends('layouts.mobile')

@section('title', 'Mi Marca - Proserge')

@section('content')
@php
    $nombre = $user->personal?->nombre_completo ?? $user->email;
    $filtros = ['fecha' => $fecha, 'turno' => $turno];
    $estados = [
        'PENDIENTE' => 'Pendiente',
        'PRESENTE' => 'Presente',
        'TARDANZA' => 'Tardanza',
        'AUSENTE' => 'Ausente',
        'JUSTIFICADO' => 'Justificado',
        'NO_CORRESPONDE' => 'No corresponde',
    ];
    $marca = $grupo['marca_propia'] ?? null;
    $puedeMarcar = ($grupo['puede_marcar'] ?? false) && in_array($marca['estado'] ?? '', ['PENDIENTE', 'AUSENTE'], true);
@endphp

<div class="mob-page">
    <header class="mob-header">
        <div class="mob-header-top">
            <div class="mob-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($nombre, 0, 2)) }}</div>
            <div class="mob-header-info">
                <h1 class="mob-label">Mi Marca</h1>
                <div class="mob-name">{{ $nombre }}</div>
            </div>
        </div>
    </header>

    <main class="mob-body">
        <form class="mob-card mob-filter" method="GET" action="{{ route('mi-asistencia.mobile') }}">
            <div>
                <label for="mobDate">Fecha</label>
                <input id="mobDate" name="fecha" type="date" value="{{ $fecha }}" required>
            </div>
            <div>
                <label for="mobShift">Turno</label>
                <select id="mobShift" name="turno">
                    <option value="" @selected($turno === '')>Todos</option>
                    <option value="DIA" @selected($turno === 'DIA')>Día</option>
                    <option value="NOCHE" @selected($turno === 'NOCHE')>Noche</option>
                </select>
            </div>
            <button type="submit" class="mob-action">Buscar grupos</button>
        </form>

        @if($errors->any())
            <p class="mob-notice warning" role="alert">{{ $errors->first() }}</p>
        @endif

        @if($selectionUnavailable)
            <p class="mob-notice warning" role="alert">El grupo seleccionado ya no está disponible para esta fecha y turno. Revisa los grupos de la jornada.</p>
        @endif

        @if($grupos->count() > 1 || ($grupos->isNotEmpty() && (!$grupo || $grupo['estado'] === 'Cancelado')))
            <form class="mob-card mob-filter" method="GET" action="{{ route('mi-asistencia.mobile') }}">
                <input type="hidden" name="fecha" value="{{ $fecha }}">
                <input type="hidden" name="turno" value="{{ $turno }}">
                <div class="mob-full-width">
                    <label for="mobGroup">Elige un grupo</label>
                    <select id="mobGroup" name="grupo_id" required>
                        <option value="">Selecciona un grupo</option>
                        @foreach($grupos as $opcion)
                            <option value="{{ $opcion['id'] }}" @selected(($grupo['id'] ?? null) === $opcion['id'])>
                                {{ $opcion['servicio'] }} · {{ $opcion['mina'] }} · {{ $opcion['turno'] === 'DIA' ? 'Día' : 'Noche' }}
                                @if($opcion['area']) · {{ $opcion['area'] }} @endif
                                @if($opcion['sait']) · SAIT {{ $opcion['sait'] }} @endif
                                @if($opcion['horario']) · {{ $opcion['horario'] }} @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="mob-action">Abrir grupo</button>
            </form>
        @endif

        @if($grupo)
            <section class="mob-card" aria-labelledby="mobGroupTitle">
                <h2 id="mobGroupTitle" class="mob-card-title">{{ $grupo['servicio'] }}</h2>
                <dl class="mob-group-data">
                    <div><dt>Unidad minera</dt><dd>{{ $grupo['mina'] }}</dd></div>
                    <div><dt>Turno</dt><dd>{{ $grupo['turno'] === 'DIA' ? 'Día' : 'Noche' }}</dd></div>
                    <div><dt>Hora límite</dt><dd>{{ $grupo['horario'] ?: 'Sin horario' }}</dd></div>
                    <div><dt>Integrantes activos</dt><dd>{{ $grupo['total'] }}</dd></div>
                    <div><dt>Estado</dt><dd>{{ $grupo['estado'] }}</dd></div>
                </dl>
            </section>

            @if($grupo['mensaje'])
                <p class="mob-notice info">{{ $grupo['mensaje'] }}</p>
            @endif
            @if($grupo['sin_responsable'] && $grupo['puede_registrar'])
                <p class="mob-notice warning">El grupo no tiene responsable asignado. El registro quedará a nombre de tu cuenta.</p>
            @endif

            @if($marca)
                <section class="mob-card" aria-labelledby="mobRecordTitle">
                    <h2 id="mobRecordTitle" class="mob-card-title">Mi registro del día</h2>
                    <p id="mobRecord" role="status">{{ $estados[$marca['estado']] ?? $marca['estado'] }}{{ $marca['hora'] && $marca['hora'] !== '00:00:00' ? ' · '.substr($marca['hora'], 0, 5) : '' }}</p>
                </section>
            @endif

            @if($puedeMarcar)
                <div class="mob-mark-actions">
                    <p id="mobMarkFeedback" class="mob-notice info" role="status" tabindex="-1" hidden></p>
                    <a id="mobLogin" class="mob-action" href="{{ route('login') }}" target="_blank" rel="noopener" hidden>Iniciar sesión</a>
                    <button id="mobCheckStatus" class="mob-action" type="button" hidden>Consultar estado</button>
                    <button id="mobReload" class="mob-action" type="button" hidden>Recargar esta jornada</button>
                    <button type="button" class="mob-mark-btn primary" id="mobMarkBtn"
                        data-url="{{ route('mi-asistencia.marcar-mobile', $grupo['id']) }}"
                        data-status-url="{{ route('mi-asistencia.estado', ['id' => $grupo['id'], 'propio' => 1]) }}"
                        data-detail-id="{{ $grupo['detalle_propio_id'] }}">
                        Marcar mi asistencia
                    </button>
                </div>
            @endif

            <a class="mob-action" href="{{ route('mi-asistencia.show', ['id' => $grupo['id'], 'volver_movil' => 1, 'turno' => $turno]) }}">
                {{ $grupo['puede_registrar'] ? 'Tomar asistencia del grupo' : 'Consultar detalle del grupo' }}
            </a>
        @elseif($grupos->isEmpty())
            @include('mi-asistencia.mobile-empty')
        @endif

        <p><a class="mob-action" href="{{ route('mi-asistencia.index', $filtros) }}">Ver lista de la jornada</a></p>
    </main>
</div>
@endsection

@push('styles')
<style>
    .mob-filter, .mob-group-data { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .mob-filter label, .mob-group-data dt { display: block; margin-bottom: 4px; font-size: .8rem; font-weight: 700; }
    .mob-filter input, .mob-filter select { width: 100%; min-width: 0; min-height: 44px; border: 1px solid #94a3b8; border-radius: 8px; padding: 8px; font: inherit; }
    .mob-full-width, .mob-filter button { grid-column: 1 / -1; }
    .mob-group-data dd { overflow-wrap: anywhere; }
    .mob-action { display: inline-flex; align-items: center; min-height: 44px; margin: 0 0 8px; border: 1px solid #94a3b8; border-radius: 8px; padding: 8px 12px; background: #fff; color: #163c60; font: inherit; font-weight: 700; }
    .mob-filter button { justify-content: center; margin: 0; }
    .mob-mark-actions { margin-bottom: 16px; }
    .mob-mark-btn.primary { background: #087b72; color: white; }
    .mob-mark-btn:disabled { opacity: .6; cursor: default; }
    .mob-mark-actions [hidden] { display: none; }
</style>
@endpush

@include('mi-asistencia._mobile-mark-script')
