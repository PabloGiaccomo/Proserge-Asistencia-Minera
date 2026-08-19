@extends('layouts.app')

@section('title', 'Mi Asistencia - Proserge')

@section('content')
@php
    $totalPersonal = $grupos->sum('total');
    $totalPresentes = $grupos->sum('presentes');
    $totalPendientes = $grupos->sum('pendientes');
@endphp

<div class="mia-page">
    <header class="mia-page-head">
        <div>
            <p class="mia-eyebrow">Control operativo</p>
            <h1>Mi Asistencia</h1>
            <p>{{ $canViewAll ? 'Consulta y gestiona las asistencias dentro de tu alcance.' : 'Registra la asistencia de los grupos donde eres responsable.' }}</p>
        </div>
        <span class="mia-scope-badge {{ $canViewAll ? 'is-global' : 'is-own' }}">
            {{ $canViewAll ? 'Todas las asistencias' : 'Solo mis grupos' }}
        </span>
    </header>

    <section class="mia-account" aria-label="Cuenta activa">
        <div class="mia-account-avatar">{{ mb_strtoupper(mb_substr((string) ($user->name ?: $user->email), 0, 2)) }}</div>
        <div>
            <span>Cuenta activa</span>
            <strong>{{ $user->name ?: $user->email }}</strong>
            <small>{{ $user->email }}</small>
        </div>
        @if(!$canViewAll)
            <p>La cuenta está vinculada al responsable seleccionado en Man Power.</p>
        @endif
    </section>

    @if(!$hasLinkedPersonal && !$canViewAll)
        <section class="mia-notice is-warning">
            <strong>Esta cuenta no está vinculada a un trabajador.</strong>
            <span>Solicita al administrador que seleccione el trabajador correspondiente en Usuarios para poder identificar tus grupos.</span>
        </section>
    @endif

    <form method="GET" action="{{ route('mi-asistencia.index') }}" class="mia-filter-bar">
        <label>
            <span>Fecha de trabajo</span>
            <input type="date" name="fecha" value="{{ $fecha }}">
        </label>
        <label>
            <span>Turno</span>
            <select name="turno">
                <option value="">Día y noche</option>
                <option value="DIA" @selected($turno === 'DIA')>Día</option>
                <option value="NOCHE" @selected($turno === 'NOCHE')>Noche</option>
            </select>
        </label>
        <button type="submit" class="mia-btn mia-btn-primary">Ver jornada</button>
        @if($fecha !== now()->toDateString() || $turno !== '')
            <a href="{{ route('mi-asistencia.index') }}" class="mia-btn mia-btn-secondary">Hoy</a>
        @endif
    </form>

    <section class="mia-summary" aria-label="Resumen de jornada">
        <div><span>Grupos</span><strong>{{ $grupos->count() }}</strong></div>
        <div><span>Personal</span><strong>{{ $totalPersonal }}</strong></div>
        <div><span>Presentes</span><strong>{{ $totalPresentes }}</strong></div>
        <div class="{{ $totalPendientes > 0 ? 'has-pending' : '' }}"><span>Pendientes</span><strong>{{ $totalPendientes }}</strong></div>
    </section>

    <section class="mia-groups-section">
        <div class="mia-section-head">
            <div>
                <h2>Jornada del {{ \Illuminate\Support\Carbon::parse($fecha)->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</h2>
                <p>{{ $grupos->count() }} grupo(s) encontrado(s).</p>
            </div>
        </div>

        @forelse($grupos as $grupo)
            <article class="mia-group-card">
                <div class="mia-group-main">
                    <div class="mia-turn-block">
                        <span>{{ $grupo['turno'] === 'DIA' ? 'Turno día' : 'Turno noche' }}</span>
                        <strong>{{ $grupo['horario'] ?: '--:--' }}</strong>
                    </div>
                    <div class="mia-group-title">
                        <span>{{ $grupo['mina'] }}</span>
                        <h3>{{ $grupo['sait'] ?: $grupo['servicio'] }}</h3>
                        <p>{{ collect([$grupo['area'], $grupo['sector']])->filter()->join(' · ') ?: 'Sin área especificada' }}</p>
                    </div>
                    <div class="mia-responsible">
                        <span>Responsable</span>
                        <strong>{{ $grupo['responsable'] ?: 'Sin responsable' }}</strong>
                    </div>
                </div>
                <div class="mia-group-progress">
                    <div>
                        <span>{{ $grupo['presentes'] }} presentes</span>
                        <span>{{ $grupo['pendientes'] }} pendientes</span>
                        <span>{{ $grupo['total'] }} integrantes</span>
                    </div>
                    <progress max="{{ max(1, $grupo['total']) }}" value="{{ $grupo['presentes'] }}"></progress>
                </div>
                <div class="mia-group-actions">
                    <span class="mia-status is-{{ strtolower($grupo['estado_asistencia']) }}">
                        {{ $grupo['estado_asistencia'] === 'CERRADO' ? 'Asistencia cerrada' : ($grupo['pendientes'] > 0 ? 'Pendiente de completar' : 'Registro completo') }}
                    </span>
                    <a class="mia-btn mia-btn-primary" href="{{ route('mi-asistencia.show', $grupo['id']) }}">
                        {{ $grupo['estado_asistencia'] === 'CERRADO' ? 'Ver asistencia' : 'Tomar asistencia' }}
                    </a>
                </div>
            </article>
        @empty
            <div class="mia-empty">
                <strong>No hay grupos para esta jornada.</strong>
                <p>{{ $canViewAll ? 'No se encontraron grupos dentro de tu alcance para la fecha y turno seleccionados.' : 'No apareces como responsable de un grupo en esta fecha.' }}</p>
            </div>
        @endforelse
    </section>
</div>
@endsection
