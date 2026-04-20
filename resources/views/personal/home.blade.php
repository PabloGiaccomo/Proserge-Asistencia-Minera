@extends('layouts.app')

@section('title', 'Inicio - Proserge')

@section('content')
<div class="home-page">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-content">
            <h1 class="welcome-title">
                ¡Bienvenido, {{ explode(' ', session('user.name') ?? session('user.email') ?? 'Usuario')[0] }}!
            </h1>
            <p class="welcome-subtitle">Sistema de Gestión Operativa - Proserge</p>
        </div>
        <div class="welcome-date">
            <span class="date-label">Hoy es</span>
            <span class="date-value">{{ now()->format('d \d\e F \d\e Y') }}</span>
        </div>
    </div>

    <!-- Quick Access Cards -->
    <div class="quick-access-grid">
        <a href="{{ route('personal.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(25, 211, 197, 0.15), rgba(25, 211, 197, 0.08)); color: #19D3C5;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Personal</span>
                <span class="qac-desc">Gestión de trabajadores</span>
            </div>
        </a>

        <a href="{{ route('mi-asistencia.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(79, 140, 255, 0.15), rgba(79, 140, 255, 0.08)); color: #4F8CFF;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Mi Asistencia</span>
                <span class="qac-desc">Control de asistencia</span>
            </div>
        </a>

        <a href="{{ route('man-power.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.08)); color: #10B981;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Man Power</span>
                <span class="qac-desc">Grupos de trabajo</span>
            </div>
        </a>

        <a href="{{ route('rq-mina.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.08)); color: #F59E0B;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">RQ Mina</span>
                <span class="qac-desc">Requerimientos Mina</span>
            </div>
        </a>

        <a href="{{ route('rq-proserge.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(139, 92, 246, 0.08)); color: #8B5CF6;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="12" y1="18" x2="12" y2="12"/>
                    <line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">RQ Proserge</span>
                <span class="qac-desc">Requerimientos Proserge</span>
            </div>
        </a>

        <a href="{{ route('bienestar.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(236, 72, 153, 0.08)); color: #EC4899;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Bienestar</span>
                <span class="qac-desc">Programas de bienestar</span>
            </div>
        </a>

        <a href="{{ route('evaluaciones.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.15), rgba(6, 182, 212, 0.08)); color: #06B6D4;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="20" x2="18" y2="10"/>
                    <line x1="12" y1="20" x2="12" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Evaluaciones</span>
                <span class="qac-desc">Desempeño y supervisor</span>
            </div>
        </a>

        <a href="{{ route('catalogos.index') }}" class="quick-access-card">
            <div class="qac-icon" style="background: linear-gradient(135deg, rgba(100, 116, 139, 0.15), rgba(100, 116, 139, 0.08)); color: #64748B;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                    <line x1="8" y1="2" x2="8" y2="18"/>
                    <line x1="16" y1="6" x2="16" y2="22"/>
                </svg>
            </div>
            <div class="qac-content">
                <span class="qac-title">Catálogos</span>
                <span class="qac-desc">Minas, talleres, oficinas</span>
            </div>
        </a>
    </div>

    <!-- Info Section -->
    <div class="home-info-grid">
        <div class="info-card">
            <div class="info-card-header">
                <span class="info-card-title">Información del Sistema</span>
            </div>
            <div class="info-card-body">
                <p class="info-text">Proserge es el sistema integral de gestión operativa para la minería. Aquí podrás gestionar:</p>
                <ul class="info-list">
                    <li>Control de asistencia y grupos de trabajo</li>
                    <li>Requerimientos de mina y proserges</li>
                    <li>Evaluaciones de desempeño</li>
                    <li>Catálogos de ubicaciones</li>
                </ul>
            </div>
        </div>

        <div class="info-card">
            <div class="info-card-header">
                <span class="info-card-title">Tu Rol</span>
            </div>
            <div class="info-card-body">
                <div class="user-role-display">
                    <div class="role-badge">{{ session('user.rol') ?? 'Usuario' }}</div>
                    <p class="role-desc">Tienes acceso a las secciones permitidas según tu perfil.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
