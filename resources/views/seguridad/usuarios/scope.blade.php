@extends('layouts.app')

@section('title', 'Scope Mina - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Editar Scope por Mina</h1>
                <p class="page-subtitle">{{ $usuario['name'] ?? '' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.show', $usuario['id']) }}" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Volver
                </a>
            </div>
        </div>
    </div>

    <form action="{{ route('usuarios.scope-update', $usuario['id']) }}" method="POST">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="card-header">
                <span class="card-title">Seleccionar Acceso por Mina</span>
                <span class="card-badge">{{ count($scopes) ?? 0 }} minas asignadas</span>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 20px; color: var(--color-text-secondary);">Seleccione las minas y ubicaciones a las que este usuario tendrá acceso. Si no tiene acceso a una mina, no podrá ver información de esa mina en RQ Mina, RQ Proserge, Man Power o Mi Asistencia.</p>
                
                <div class="mines-grid">
                    @foreach($todasLasMinas as $mina)
                    @php
                    $isSelected = in_array($mina['nombre'], $scopes ?? []);
                    @endphp
                    <div class="mine-selection-item">
                        <div class="mine-checkbox">
                            <input type="checkbox" name="mina_ids[]" value="{{ $mina['nombre'] }}" id="mina_{{ str_replace(' ', '_', $mina['nombre']) }}"
                                {{ $isSelected ? 'checked' : '' }}>
                            <label for="mina_{{ str_replace(' ', '_', $mina['nombre']) }}" class="mine-checkbox-label">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-text">{{ $mina['nombre'] }}</span>
                            </label>
                        </div>
                        <div class="mine-status-select">
                            <select name="mina_estado[{{ $mina['nombre'] }}]" class="form-control form-control-sm" {{ !$isSelected ? 'disabled' : '' }}>
                                <option value="habilitado" {{ $isSelected ? 'selected' : '' }}>Habilitado</option>
                            </select>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="{{ route('usuarios.show', $usuario['id']) }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Scope</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('input[name="mina_ids[]"]').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        const statusSelect = this.closest('.mine-selection-item').querySelector('select');
        statusSelect.disabled = !this.checked;
    });
});
</script>
@endpush