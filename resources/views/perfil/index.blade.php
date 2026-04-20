@extends('layouts.app')

@section('title', 'Mi Perfil - Proserge')

@section('content')
<div class="module-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Mi Perfil</h1>
                <p class="page-subtitle">Información de tu cuenta y accesos</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Card - Left Column -->
        <div class="lg:col-span-1">
            <div class="card">
                <div class="card-body text-center">
                    <div class="profile-avatar-large mx-auto mb-4">
                        {{ strtoupper(substr($perfil['nombre'] ?? 'U', 0, 2)) }}
                    </div>
                    <h2 class="text-xl font-semibold text-gray-900">{{ $perfil['nombre'] }}</h2>
                    <p class="text-sm text-gray-500 mb-4">{{ $perfil['rol'] }}</p>
                    
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-green-100 text-green-800 text-sm font-medium">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        {{ $perfil['estado'] }}
                    </div>
                </div>
            </div>

            <!-- Mining Access Card -->
            <div class="card mt-6">
                <div class="card-header">
                    <span class="card-title">Minas Habilitadas</span>
                </div>
                <div class="card-body">
                    @if(empty($minasHabilitadas))
                        <p class="text-sm text-gray-400">Sin minas asignadas</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach($minasHabilitadas as $mina)
                                <span class="px-3 py-1 bg-cyan-50 text-cyan-700 rounded-full text-sm font-medium">
                                    {{ $mina }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Profile Info - Right Columns -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Information -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Información Personal</span>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="info-item">
                            <span class="info-label">Nombre Completo</span>
                            <span class="info-value">{{ $perfil['nombre'] }}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Correo Electrónico</span>
                            <span class="info-value">{{ $perfil['email'] }}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Rol</span>
                            <span class="info-value">{{ $perfil['rol'] }}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Estado</span>
                            <span class="info-value">{{ $perfil['estado'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Access Information -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Accesos y Permisos</span>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="info-item">
                            <span class="info-label">ID de Usuario</span>
                            <span class="info-value text-sm">{{ $perfil['id'] }}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tipo de Acceso</span>
                            <span class="info-value">
                                @if(($perfil['rol'] ?? '') === 'ADMIN')
                                    Administrador
                                @elseif(($perfil['rol'] ?? '') === 'SUPERVISOR')
                                    Supervisor
                                @else
                                    Usuario Regular
                                @endif
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Scopes de Mina</span>
                            <span class="info-value">
                                @if(empty($minasHabilitadas))
                                    Global (todos)
                                @else
                                    {{ count($minasHabilitadas) }} mina(s)
                                @endif
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Permisos</span>
                            <span class="info-value">
                                @switch($perfil['rol'] ?? '')
                                    @case('ADMIN')
                                        Acceso total
                                        @break
                                    @case('SUPERVISOR')
                                        Supervisión y gestión
                                        @break
                                    @default
                                        Usuario básico
                                @endswitch
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Summary -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Resumen Operativo</span>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center p-4 bg-gray-50 rounded-xl">
                            <div class="text-2xl font-bold text-gray-900">{{ $evaluacionesResumen['total'] }}</div>
                            <div class="text-sm text-gray-500">Evaluaciones</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-xl">
                            <div class="text-2xl font-bold text-gray-900">{{ $evaluacionesResumen['promedio'] }}</div>
                            <div class="text-sm text-gray-500">Promedio</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-xl">
                            <div class="text-2xl font-bold text-gray-900">{{ $evaluacionesResumen['ultima'] }}</div>
                            <div class="text-sm text-gray-500">Última Evaluación</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.profile-avatar-large {
    width: 100px;
    height: 100px;
    border-radius: 24px;
    background: linear-gradient(135deg, #19D3C5 0%, #14b5a8 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    color: white;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 15px;
    font-weight: 500;
    color: #1E293B;
}
</style>
@endsection