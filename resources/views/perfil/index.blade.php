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
                    <span class="card-title">Alcance por mina</span>
                </div>
                <div class="card-body">
                    @if($accesoGeneral)
                        <p>Acceso a todas las minas en los módulos autorizados.</p>
                    @elseif(empty($minasDeAcceso))
                        <p>Sin minas asignadas a esta cuenta.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach($minasDeAcceso as $mina)
                                <span class="px-3 py-1 bg-cyan-50 text-cyan-700 rounded-full text-sm font-medium">
                                    {{ $mina }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="card mt-6">
                <div class="card-header">
                    <span class="card-title">Habilitación del trabajador</span>
                </div>
                <div class="card-body">
                    @forelse($habilitaciones as $habilitacion)
                        <p class="mb-2"><strong>{{ $habilitacion['mina'] }}</strong><br>{{ $habilitacion['estado'] }}</p>
                    @empty
                        <p>Sin habilitaciones registradas.</p>
                    @endforelse
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
                            <span class="info-label">Rol principal</span>
                            <span class="info-value">{{ $perfil['rol'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Summary -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Evaluaciones diarias</span>
                </div>
                <div class="card-body">
                    @if($evaluacionesResumen === null)
                        <p>Evaluaciones no disponibles para esta cuenta.</p>
                    @elseif($evaluacionesResumen['total'] === 0)
                        <p>Sin evaluaciones disponibles para tu trabajador.</p>
                    @else
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
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
