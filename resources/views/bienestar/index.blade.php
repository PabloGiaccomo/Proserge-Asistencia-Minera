@extends('layouts.app')

@section('title', 'Bienestar - Proserge')

@section('content')
<div class="module-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Bienestar</h1>
                <p class="page-subtitle">Programas y beneficios para trabajadores</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group" style="flex: 2;">
                <label class="filter-label">Buscar Programa</label>
                <input type="text" class="form-control" placeholder="Nombre del programa...">
            </div>
            <div class="filter-group">
                <label class="filter-label">Tipo</label>
                <select class="form-control">
                    <option value="">Todos</option>
                    <option value="salud">Salud</option>
                    <option value="capacitacion">Capacitación</option>
                    <option value="recreacion">Recreación</option>
                    <option value="beneficio">Beneficios</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary btn-sm">Buscar</button>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Programas de Bienestar</span>
        </div>
        <div class="card-body">
            <x-ui.empty-state
                icon="heart"
                title="Módulo en preparación"
                description="El módulo de bienestar está en proceso de implementación."
            />
        </div>
    </div>
</div>
@endsection
