@extends('layouts.app')

@section('title', 'Scope Mina - Proserge')

@section('content')
@php
    $canScopeUser = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'usuarios', 'scope');
    $isPrivilegedUser = in_array(strtoupper((string) optional($usuario->rol)->nombre), ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    $selectedCount = count($scopeIds);
    $totalCount = $minas->count();
@endphp
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Scope Mina</h1>
                <p class="page-subtitle">{{ $usuario->personal?->nombre_completo ?? $usuario->email }} &middot; Rol: <strong>{{ $usuario->rol?->nombre ?? '-' }}</strong></p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.show', $usuario->id) }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ $errors->first() }}</div>
    @endif

    @if($isPrivilegedUser)
        <div class="alert alert-info" style="margin-bottom: 16px; border-left: 4px solid #2563eb;">
            <strong>Nota sobre Rol Corporativo ({{ $usuario->rol?->nombre }}):</strong>
            Los roles de nivel directivo o administración acceden a todas las unidades mineras de forma global por diseño del sistema. Las minas marcadas en esta pantalla actúan como alcance preferencial o complementario.
        </div>
    @endif

    <div id="emptyScopeNotice" class="alert alert-warning" style="margin-bottom: 16px; border-left: 4px solid #f59e0b; {{ $selectedCount === 0 ? '' : 'display:none;' }}">
        <strong>Aviso de Scope Vacío:</strong>
        Este usuario no tiene ninguna mina marcada en su alcance multi-mina.
        Esta configuración es <strong>funcional</strong>: limita la visibilidad del usuario estrictamente a la mina asignada en su perfil laboral o contrato. Si necesita acceso explícito a minas específicas, marque las casillas correspondientes abajo.
    </div>

    <div id="configuredScopeNotice" class="alert alert-success" style="margin-bottom: 16px; border-left: 4px solid #10b981; {{ $selectedCount > 0 ? '' : 'display:none;' }}">
        <strong>Scope Multi-Mina Activo:</strong>
        El usuario tendrá acceso habilitado a las <span id="configuredCountText">{{ $selectedCount }}</span> mina(s) seleccionadas a continuación.
    </div>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <span class="card-title">Minas asignadas al usuario</span>
                <span class="card-badge" id="scopeCountBadge" style="margin-left: 8px;">{{ $selectedCount }} de {{ $totalCount }} seleccionadas</span>
            </div>
            @if($canScopeUser && $totalCount > 0)
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="selectAllMinas()">Marcar todas</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="deselectAllMinas()">Desmarcar todas</button>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if(!$canScopeUser)
                <div class="alert alert-error">
                    No tienes permiso para configurar el alcance de mina de usuarios.
                </div>
            @else
            <form action="{{ route('usuarios.scope-update', $usuario->id) }}" method="POST" id="scopeForm">
                @csrf
                @method('PUT')

                <p style="margin-bottom: 20px; color: var(--color-text-secondary);">
                    Selecciona las unidades mineras a las que este usuario podrá ingresar para gestionar requerimientos, asistencia y logística:
                </p>

                <div style="display: grid; gap: 12px; margin-bottom: 24px;" id="minasGrid">
                    @forelse($minas as $mina)
                        @php
                            $isChecked = in_array($mina->id, $scopeIds, true);
                        @endphp
                        <label class="mina-scope-card {{ $isChecked ? 'is-selected' : '' }}" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid {{ $isChecked ? '#3b82f6' : '#e5e7eb' }}; background: {{ $isChecked ? '#eff6ff' : '#ffffff' }}; border-radius: 12px; padding: 14px 16px; cursor: pointer; transition: all 0.15s ease;">
                            <span>
                                <strong>{{ $mina->nombre }}</strong><br>
                                <span style="color: var(--color-text-secondary); font-size: 13px;">
                                    Estado catálogo:
                                    <span class="badge badge-{{ strtoupper((string) $mina->estado) === 'ACTIVO' ? 'success' : 'secondary' }}" style="font-size: 11px;">
                                        {{ strtoupper((string) $mina->estado) }}
                                    </span>
                                </span>
                            </span>
                            <input type="checkbox" name="mina_ids[]" value="{{ $mina->id }}" {{ $isChecked ? 'checked' : '' }} class="mina-scope-checkbox" style="width: 18px; height: 18px; cursor: pointer;">
                        </label>
                    @empty
                        <div class="empty-state" style="padding: 24px 0;">
                            <h3 class="empty-title">No hay minas registradas</h3>
                            <p class="empty-description">Primero debes registrar minas en el catálogo.</p>
                        </div>
                    @endforelse
                </div>

                <div class="form-actions" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <span id="scopeFooterStatus" style="font-size: 13px; color: var(--color-text-secondary);">
                            {{ $selectedCount === 0 ? 'Sin minas seleccionadas (alcance local individual).' : $selectedCount . ' mina(s) seleccionada(s).' }}
                        </span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <a href="{{ route('usuarios.show', $usuario->id) }}" class="btn btn-outline">Cancelar</a>
                        <button type="submit" class="btn btn-primary">Guardar Scope</button>
                    </div>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>

<script>
function updateScopeUI() {
    const checkboxes = document.querySelectorAll('.mina-scope-checkbox');
    const checked = document.querySelectorAll('.mina-scope-checkbox:checked');
    const total = checkboxes.length;
    const count = checked.length;

    const countBadge = document.getElementById('scopeCountBadge');
    if (countBadge) {
        countBadge.textContent = count + ' de ' + total + ' seleccionadas';
    }

    const emptyNotice = document.getElementById('emptyScopeNotice');
    const configuredNotice = document.getElementById('configuredScopeNotice');
    const configuredText = document.getElementById('configuredCountText');
    const footerStatus = document.getElementById('scopeFooterStatus');

    if (count === 0) {
        if (emptyNotice) emptyNotice.style.display = '';
        if (configuredNotice) configuredNotice.style.display = 'none';
        if (footerStatus) footerStatus.textContent = 'Sin minas seleccionadas (alcance local individual).';
    } else {
        if (emptyNotice) emptyNotice.style.display = 'none';
        if (configuredNotice) configuredNotice.style.display = '';
        if (configuredText) configuredText.textContent = count;
        if (footerStatus) footerStatus.textContent = count + ' mina(s) seleccionada(s).';
    }

    checkboxes.forEach(function (cb) {
        const card = cb.closest('.mina-scope-card');
        if (card) {
            if (cb.checked) {
                card.classList.add('is-selected');
                card.style.borderColor = '#3b82f6';
                card.style.background = '#eff6ff';
            } else {
                card.classList.remove('is-selected');
                card.style.borderColor = '#e5e7eb';
                card.style.background = '#ffffff';
            }
        }
    });
}

function selectAllMinas() {
    document.querySelectorAll('.mina-scope-checkbox').forEach(function (cb) {
        cb.checked = true;
    });
    updateScopeUI();
}

function deselectAllMinas() {
    document.querySelectorAll('.mina-scope-checkbox').forEach(function (cb) {
        cb.checked = false;
    });
    updateScopeUI();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mina-scope-checkbox').forEach(function (cb) {
        cb.addEventListener('change', updateScopeUI);
    });
});
</script>
@endsection
