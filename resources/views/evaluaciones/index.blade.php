@extends('layouts.app')

@section('title', 'Evaluaciones - Proserge')

@section('content')
<div class="module-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Evaluaciones</h1>
                <p class="page-subtitle">Gestión de evaluaciones de desempeño</p>
            </div>
        </div>
    </div>

    <!-- Evaluation Modules Grid -->
    <div class="eval-modules-grid mb-4">
        <a href="{{ route('evaluaciones.desempeno.index') }}" class="eval-module-card">
            <div class="emc-icon" style="background: linear-gradient(135deg, rgba(25, 211, 197, 0.15), rgba(25, 211, 197, 0.08)); color: #19D3C5;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="20" x2="18" y2="10"/>
                    <line x1="12" y1="20" x2="12" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
            </div>
            <div class="emc-content">
                <span class="emc-title">Evaluación de Desempeño</span>
                <span class="emc-desc">Evalúa el desempeño general de los trabajadores</span>
            </div>
        </a>

        <a href="{{ route('evaluaciones.supervisor') }}" class="eval-module-card">
            <div class="emc-icon" style="background: linear-gradient(135deg, rgba(79, 140, 255, 0.15), rgba(79, 140, 255, 0.08)); color: #4F8CFF;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <div class="emc-content">
                <span class="emc-title">Evaluación Supervisor</span>
                <span class="emc-desc">Evaluaciones realizadas por supervisores</span>
            </div>
        </a>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Evaluaciones Recientes</span>
        </div>
        <div class="card-body">
            <x-ui.empty-state
                icon="chart"
                title="Aún no hay evaluaciones registradas"
                description="Las evaluaciones de desempeño aparecerán aquí una vez creadas."
            />
        </div>
    </div>
</div>
@endsection
