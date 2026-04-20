@extends('layouts.app')

@section('title', 'Usuarios - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Usuarios</h1>
                <p class="page-subtitle">Gestión de usuarios del sistema</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('agregarUsuarioModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Nuevo Usuario
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de Usuarios</span>
            <span class="card-badge">{{ count($data ?? []) }} usuarios</span>
        </div>
        <div class="card-body">
            @if(empty($data))
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">No hay usuarios registrados</h3>
                    <p class="empty-description">Los usuarios se crean buscando personal existente</p>
                    <div class="empty-action">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('agregarUsuarioModal')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Agregar Usuario
                        </button>
                    </div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Roles</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data as $item)
                            <tr>
                                <td>{{ $item['id'] ?? '-' }}</td>
                                <td>{{ $item['name'] ?? '-' }}</td>
                                <td>{{ $item['email'] ?? '-' }}</td>
                                <td>
                                    {{ $item['rol'] ?? '-' }}
                                </td>
                                <td>
                                    <span class="badge badge-{{ ($item['estado'] ?? 'ACTIVO') === 'ACTIVO' ? 'success' : 'danger' }}">
                                        {{ $item['estado'] ?? 'ACTIVO' }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('usuarios.show', $item['id']) }}" class="btn btn-sm btn-outline">
                                        Ver
                                    </a>
                                    <a href="{{ route('usuarios.scope', $item['id']) }}" class="btn btn-sm btn-outline">
                                        Scope Mina
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Modal Agregar Usuario -->
<div class="modal" id="agregarUsuarioModal" style="display: none;">
    <div class="modal-backdrop" onclick="closeModal('agregarUsuarioModal')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Agregar Usuario</h2>
            <button type="button" class="modal-close" onclick="closeModal('agregarUsuarioModal')">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <p class="modal-subtitle" id="searchHelpText">Busca un trabajador del personal para agregarlo como usuario del sistema.</p>
            
            @include('components.ui.global-search', [
                'searchId' => 'buscar-trabajador',
                'placeholder' => 'Buscar por nombre o DNI...',
                'showClear' => true,
                'minChars' => 2
            ])

            <div id="datosUsuarioForm" style="display: none;">
                <div class="form-section">
                    <h3 class="form-section-title">Datos del Usuario</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="formNombre" readonly>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">DNI</label>
                            <input type="text" class="form-control" id="formDni" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="formEmail" placeholder="email@proserge.com">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Rol</label>
                            <select class="form-control" id="formRol" required>
                                <option value="">Seleccionar...</option>
                                <option value="ADMIN">ADMIN</option>
                                <option value="SUPERVISOR">SUPERVISOR</option>
                                <option value="USUARIO">USUARIO</option>
                                <option value="AUDITOR">AUDITOR</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estado</label>
                            <select class="form-control" id="formEstado">
                                <option value="ACTIVO">ACTIVO</option>
                                <option value="INACTIVO">INACTIVO</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('agregarUsuarioModal')">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btnCrearUsuario" disabled>Crear Usuario</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let trabajadorSeleccionado = null;

function generateEmail(nombre) {
    if (!nombre) return '';
    const parts = nombre.toLowerCase().trim().split(' ');
    if (parts.length >= 2) {
        return parts[0][0] + parts[parts.length - 1] + '@proserge.com';
    }
    return nombre.toLowerCase().replace(/\s+/g, '.') + '@proserge.com';
}

document.addEventListener('search:select', function(e) {
    const { item, searchId } = e.detail;
    if (searchId === 'buscar-trabajador' && item && item.nombre) {
        trabajadorSeleccionado = item;
        
        document.getElementById('formNombre').value = item.nombre;
        document.getElementById('formDni').value = item.dni || '';
        document.getElementById('formEmail').value = generateEmail(item.nombre);
        
        document.getElementById('searchHelpText').style.display = 'none';
        document.querySelector('[data-search-id="buscar-trabajador"]').style.display = 'none';
        document.getElementById('datosUsuarioForm').style.display = 'block';
        document.getElementById('btnCrearUsuario').disabled = false;
    }
});

document.getElementById('btnCrearUsuario').addEventListener('click', function() {
    if (!trabajadorSeleccionado) return;
    
    const rol = document.getElementById('formRol').value;
    if (!rol) {
        alert('Por favor selecciona un rol');
        return;
    }
    
    const usuario = {
        id: 'usr-' + Date.now(),
        name: trabajadorSeleccionado.nombre,
        dni: trabajadorSeleccionado.dni,
        email: document.getElementById('formEmail').value,
        rol: rol,
        estado: document.getElementById('formEstado').value
    };
    
    alert('Usuario creado exitosamente:\n\nNombre: ' + usuario.name + '\nDNI: ' + usuario.dni + '\nEmail: ' + usuario.email + '\nRol: ' + usuario.rol + '\nEstado: ' + usuario.estado);
    closeModal('agregarUsuarioModal');
    location.reload();
});
</script>
@endpush