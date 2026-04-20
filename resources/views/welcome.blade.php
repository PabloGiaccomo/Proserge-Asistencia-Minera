@extends('layouts.app')

@section('title', 'Proserge - Sistema de Gestión Operativa')

@section('header_title')
    Bienvenido
@endsection

@section('header_breadcrumb')
    <span class="header-breadcrumb-sep">/</span>
    <span class="text-primary">Inicio</span>
@endsection

@section('content')
<div class="mb-4">
    <div class="card">
        <div class="card-body text-center" style="padding: 48px 24px;">
            <div style="margin-bottom: 24px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
            </div>
            <h2 style="margin-bottom: 12px;">Proserge - Sistema Operativo</h2>
            <p class="text-secondary mb-4" style="max-width: 500px; margin: 0 auto 24px;">
                Sistema de gestión operativa para la administración de recursos humanos, requerimientos, 
                asistencia, evaluaciones y más.
            </p>
            
            <div class="flex justify-center gap-3">
                <a href="{{ route('dashboard.principal') }}" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9"></rect>
                        <rect x="14" y="3" width="7" height="5"></rect>
                        <rect x="14" y="12" width="7" height="9"></rect>
                        <rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    Ir al Dashboard
                </a>
                <a href="{{ route('evaluaciones.supervisor') }}" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    Evaluación Supervisor
                </a>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="card">
        <div class="card-body text-center">
            <div class="kpi-value text-primary" style="font-size: 28px;">RQ Mina</div>
            <p class="text-muted mt-1" style="font-size: 12px;">Requerimientos de Mina</p>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center">
            <div class="kpi-value text-primary" style="font-size: 28px;">Man Power</div>
            <p class="text-muted mt-1" style="font-size: 12px;">Gestión de Personal</p>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center">
            <div class="kpi-value text-primary" style="font-size: 28px;">Asistencia</div>
            <p class="text-muted mt-1" style="font-size: 12px;">Control de Asistencia</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Información del Sistema</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Módulos Disponibles</h4>
                <ul style="font-size: 13px; color: var(--text-secondary); padding-left: 20px;">
                    <li>Dashboard Operativo</li>
                    <li>RQ Mina - Requerimientos de Mina</li>
                    <li>RQ Proserge - Gestión de RRHH</li>
                    <li>Man Power - Grupos de Trabajo</li>
                    <li>Asistencia - Control de Presencia</li>
                    <li>Faltas - Gestión de Inasistencias</li>
                    <li>Evaluaciones - Evaluación de Desempeño</li>
                    <li>Evaluación Supervisor</li>
                </ul>
            </div>
            <div>
                <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Características</h4>
                <ul style="font-size: 13px; color: var(--text-secondary); padding-left: 20px;">
                    <li>Filtros por fecha y destino</li>
                    <li>Scope de seguridad por mina</li>
                    <li>API RESTful con token</li>
                    <li>Diseño responsive</li>
                    <li>Estado vacío y loading</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection