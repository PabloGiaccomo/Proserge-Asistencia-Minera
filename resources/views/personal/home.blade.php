@extends('layouts.app')

@section('title', 'Inicio - Proserge')

@section('content')
<div class="home-page">
    <div class="welcome-section">
        <div class="welcome-content">
            <h1 class="welcome-title">
                Bienvenido, <span>{{ explode(' ', session('user.name') ?? session('user.email') ?? 'Usuario')[0] }}</span>
            </h1>
            <p class="welcome-subtitle">Inicio operativo con dashboards visibles segun tu rol.</p>
        </div>
        <div class="welcome-date">
            <span class="date-label">Hoy es</span>
            <span class="date-value">{{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</span>
        </div>
    </div>

    <div class="card home-dashboard-panel">
        <div class="card-header">
            <span class="card-title">Dashboards disponibles</span>
        </div>
        <div class="card-body">
            @if(count($dashboards) === 0)
                <div style="padding:18px; border:1px dashed #cbd5e1; border-radius:12px; color:#475569; background:#f8fafc;">
                    Este rol no tiene dashboards habilitados en Inicio. La navegacion del sistema queda disponible solo desde el sidebar segun tus permisos.
                </div>
            @else
                <div class="home-dashboard-grid">
                    @foreach($dashboards as $dashboard)
                        <a class="home-dashboard-card" href="{{ $dashboard['url'] }}" aria-label="Abrir dashboard de {{ $dashboard['title'] }}">
                            <div class="home-dashboard-accent" style="background: {{ $dashboard['tone'] }};"></div>
                            <div class="home-dashboard-body">
                                <div class="home-dashboard-header">
                                    <span class="home-dashboard-title">{{ $dashboard['title'] }}</span>
                                    <span class="home-dashboard-tag">Dashboard</span>
                                </div>
                                <p class="home-dashboard-text">{{ $dashboard['description'] }}</p>
                                <div class="home-dashboard-foot">
                                    <span class="home-dashboard-note">Abrir dashboard &rarr;</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="home-info-grid">
        <div class="info-card">
            <div class="info-card-header">
                <span class="info-card-title">Navegacion</span>
            </div>
            <div class="info-card-body">
                <p class="info-text">Selecciona una tarjeta para consultar su dashboard. Usa Volver a Inicio para regresar o el menu lateral para abrir los modulos que tienes habilitados.</p>
            </div>
        </div>

        <div class="info-card">
            <div class="info-card-header">
                <span class="info-card-title">Tu Rol</span>
            </div>
            <div class="info-card-body">
                <div class="user-role-display">
                    <div class="role-badge">{{ session('user.rol') ?? 'Usuario' }}</div>
                    <p class="role-desc">Los dashboards de Inicio y los modulos del sidebar cambian segun los permisos por pantalla.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
