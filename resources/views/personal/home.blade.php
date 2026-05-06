@extends('layouts.app')

@section('title', 'Inicio - Proserge')

@section('content')
<div class="home-page">
    <div class="welcome-section">
        <div class="welcome-content">
            <h1 class="welcome-title">
                Bienvenido, {{ explode(' ', session('user.name') ?? session('user.email') ?? 'Usuario')[0] }}
            </h1>
            <p class="welcome-subtitle">Inicio operativo con dashboards visibles segun tu rol.</p>
        </div>
        <div class="welcome-date">
            <span class="date-label">Hoy es</span>
            <span class="date-value">{{ now()->format('d \d\e F \d\e Y') }}</span>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
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
                        <div class="home-dashboard-card">
                            <div class="home-dashboard-accent" style="background: {{ $dashboard['tone'] }};"></div>
                            <div class="home-dashboard-body">
                                <div class="home-dashboard-header">
                                    <span class="home-dashboard-title">{{ $dashboard['title'] }}</span>
                                    <span class="home-dashboard-tag">Dashboard</span>
                                </div>
                                <p class="home-dashboard-text">{{ $dashboard['description'] }}</p>
                                <div class="home-dashboard-foot">
                                    <span class="home-dashboard-note">Visible en Inicio por permiso de dashboard</span>
                                </div>
                            </div>
                        </div>
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
                <p class="info-text">Los accesos rapidos del inicio fueron retirados. Ahora el movimiento entre modulos se hace desde el sidebar, segun los permisos activos del rol.</p>
            </div>
        </div>

        <div class="info-card">
            <div class="info-card-header">
                <span class="info-card-title">Tu Rol</span>
            </div>
            <div class="info-card-body">
                <div class="user-role-display">
                    <div class="role-badge">{{ session('user.rol') ?? 'Usuario' }}</div>
                    <p class="role-desc">Los dashboards de Inicio y los modulos del sidebar cambian segun la matriz de permisos.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.home-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
}

.home-dashboard-card {
    display: grid;
    grid-template-columns: 6px minmax(0, 1fr);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
    min-height: 146px;
}

.home-dashboard-accent {
    width: 6px;
}

.home-dashboard-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.home-dashboard-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.home-dashboard-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
}

.home-dashboard-tag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    border-radius: 999px;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.home-dashboard-text {
    margin: 0;
    color: #475569;
    line-height: 1.5;
    font-size: 14px;
}

.home-dashboard-foot {
    margin-top: auto;
}

.home-dashboard-note {
    font-size: 12px;
    color: #64748b;
}
</style>
@endsection
