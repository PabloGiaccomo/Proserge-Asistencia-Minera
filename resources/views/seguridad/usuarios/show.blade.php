@extends('layouts.app')

@section('title', 'Usuario - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Detalle de Usuario</h1>
                <p class="page-subtitle">{{ $item['name'] ?? '' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.index') }}" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Volver
                </a>
                <a href="{{ route('usuarios.scope', $item['id']) }}" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Scope Mina
                </a>
                <button type="button" class="btn btn-primary" id="btnEditar" onclick="toggleEdicion()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Editar
                </button>
            </div>
        </div>
    </div>

@if($item)
    <form method="POST" action="{{ route('usuarios.update', $item['id']) }}" id="usuarioForm">
        @csrf
        @method('PUT')
        <div class="grid grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Información General</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">ID</label>
                        <input type="text" class="form-control" value="{{ $item['id'] }}" readonly>
                        <input type="hidden" name="id" value="{{ $item['id'] }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="name" class="form-control" value="{{ $item['name'] ?? '' }}" readonly id="inputNombre">
                    </div>
                    <div class="form-group">
                        <label class="form-label">DNI</label>
                        <input type="text" name="dni" class="form-control" value="{{ $item['dni'] ?? '' }}" readonly id="inputDni">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ $item['email'] ?? '' }}" id="inputEmail">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Rol</label>
                            <select name="rol" class="form-control" id="inputRol">
                                <option value="ADMIN" {{ ($item['rol'] ?? '') === 'ADMIN' ? 'selected' : '' }}>ADMIN</option>
                                <option value="SUPERVISOR" {{ ($item['rol'] ?? '') === 'SUPERVISOR' ? 'selected' : '' }}>SUPERVISOR</option>
                                <option value="USUARIO" {{ ($item['rol'] ?? '') === 'USUARIO' ? 'selected' : '' }}>USUARIO</option>
                                <option value="AUDITOR" {{ ($item['rol'] ?? '') === 'AUDITOR' ? 'selected' : '' }}>AUDITOR</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estado</label>
                            <select name="estado" class="form-control" id="inputEstado">
                                <option value="ACTIVO" {{ ($item['estado'] ?? 'ACTIVO') === 'ACTIVO' ? 'selected' : '' }}>ACTIVO</option>
                                <option value="INACTIVO" {{ ($item['estado'] ?? 'ACTIVO') === 'INACTIVO' ? 'selected' : '' }}>INACTIVO</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-actions" id="formActions" style="display: none;">
            <a href="{{ route('usuarios.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
    </form>
@else
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h3 class="empty-title">Usuario no encontrado</h3>
                <p class="empty-description">El usuario que buscas no existe</p>
            </div>
        </div>
    </div>
@endif
</div>
@endsection

@push('scripts')
<script>
let editando = false;

function toggleEdicion() {
    const campos = ['inputNombre', 'inputDni', 'inputEmail', 'inputRol', 'inputEstado'];
    const btn = document.getElementById('btnEditar');
    const actions = document.getElementById('formActions');
    
    editando = !editando;
    
    if (editando) {
        document.getElementById('inputNombre').removeAttribute('readonly');
        document.getElementById('inputDni').removeAttribute('readonly');
        document.getElementById('inputEmail').removeAttribute('readonly');
        document.getElementById('inputRol').removeAttribute('disabled');
        document.getElementById('inputEstado').removeAttribute('disabled');
        
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>Cancelar';
        actions.style.display = 'flex';
    } else {
        document.getElementById('inputNombre').setAttribute('readonly', 'true');
        document.getElementById('inputDni').setAttribute('readonly', 'true');
        document.getElementById('inputEmail').setAttribute('readonly', 'true');
        document.getElementById('inputRol').setAttribute('disabled', 'true');
        document.getElementById('inputEstado').setAttribute('disabled', 'true');
        
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Editar';
        actions.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('inputRol').setAttribute('disabled', 'true');
    document.getElementById('inputEstado').setAttribute('disabled', 'true');
});
</script>
@endpush