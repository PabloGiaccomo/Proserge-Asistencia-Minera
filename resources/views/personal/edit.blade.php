@extends('layouts.app')

@section('title', 'Editar Trabajador - Proserge')

@section('content')
@php
    $catalogMinas = ['Mina 1', 'Mina 2', 'Mina 3'];
    $catalogOficinas = ['Oficina Central Lima', 'Oficina Cerro de Pasco', 'Oficina Ancash'];
    $catalogTalleres = ['Taller Mecánico Central', 'Taller de Soldadura', 'Taller Eléctrico'];
@endphp
<div class="module-page personal-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Editar Trabajador</h1>
                <p class="page-subtitle">Modificar los datos del trabajador</p>
            </div>
        </div>
    </div>

    <!-- Form -->
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('personal.update', $trabajador['id'] ?? request('id')) }}">
                @csrf
                @method('PUT')
                
                <!-- Datos Personales -->
                <div class="form-section">
                    <h3 class="form-section-title">Datos Personales</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Nombres completos</label>
                            <input type="text" name="nombre" class="form-control" value="{{ $trabajador['nombre'] ?? '' }}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">DNI</label>
                            <input type="text" name="dni" class="form-control" value="{{ $trabajador['dni'] ?? '' }}" required maxlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" value="{{ $trabajador['telefono'] ?? '' }}">
                        </div>
                    </div>
                </div>

                <!-- Datos Laborales -->
                <div class="form-section">
                    <h3 class="form-section-title">Datos Laborales</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Puesto / Cargo</label>
                            <input type="text" name="puesto" class="form-control" value="{{ $trabajador['puesto'] ?? '' }}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Tipo de Contrato</label>
                            <select name="tipo_contrato" class="form-control" required>
                                <option value="Indeterminado" {{ ($trabajador['tipo_contrato'] ?? '') == 'Indeterminado' ? 'selected' : '' }}>Indeterminado</option>
                                <option value="Fijo" {{ ($trabajador['tipo_contrato'] ?? '') == 'Fijo' ? 'selected' : '' }}>Fijo</option>
                                <option value="Intermitente" {{ ($trabajador['tipo_contrato'] ?? '') == 'Intermitente' ? 'selected' : '' }}>Intermitente</option>
                                <option value="Régimen" {{ ($trabajador['tipo_contrato'] ?? '') == 'Régimen' ? 'selected' : '' }}>Régimen</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">¿Es Supervisor?</label>
                            <select name="supervisor" class="form-control" required>
                                <option value="0" {{ ($trabajador['supervisor'] ?? false) == false ? 'selected' : '' }}>No</option>
                                <option value="1" {{ ($trabajador['supervisor'] ?? false) == true ? 'selected' : '' }}>Sí</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Estado</label>
                            <select name="activo" class="form-control" required>
                                <option value="1" {{ ($trabajador['activo'] ?? true) == true ? 'selected' : '' }}>Activo</option>
                                <option value="0" {{ ($trabajador['activo'] ?? true) == false ? 'selected' : '' }}>Inactivo</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Ubicación en Minas -->
                <div class="form-section">
                    <h3 class="form-section-title">Ubicación en Minas</h3>
                    <p class="form-section-desc">Selecciona las minas donde trabajará el trabajador y su estado en cada una.</p>
                    
                    <div class="mines-grid">
                        @foreach($catalogMinas as $mina)
                        @php
                        $isSelected = in_array($mina, $trabajador['minas'] ?? []);
                        $estado = ($trabajador['minas_estado'] ?? [])[$mina] ?? '';
                        @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $mina }}" id="mina_{{ str_replace(' ', '_', $mina) }}" {{ $isSelected ? 'checked' : '' }}>
                                <label for="mina_{{ str_replace(' ', '_', $mina) }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $mina }}</span>
                                </label>
                            </div>
                            <div class="mine-status-select">
                                <select name="mina_estado[{{ $mina }}]" class="form-control form-control-sm">
                                    <option value="habilitado" {{ $estado === 'habilitado' ? 'selected' : '' }}>Habilitado</option>
                                    <option value="proceso" {{ $estado === 'proceso' ? 'selected' : '' }}>En proceso</option>
                                    <option value="no_habilitado" {{ $estado === 'no_habilitado' ? 'selected' : '' }}>No habilitado</option>
                                </select>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                <!-- Oficinas y Talleres -->
                <div class="form-section">
                    <h3 class="form-section-title">Oficinas y Talleres</h3>
                    <p class="form-section-desc">Indica si el trabajador también puede laborar en oficina y/o taller.</p>

                    <div class="mines-grid">
                        @foreach($catalogOficinas as $oficina)
                        @php $isOficinaSelected = in_array($oficina, $trabajador['minas'] ?? []); @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $oficina }}" id="oficina_{{ $loop->index }}" {{ $isOficinaSelected ? 'checked' : '' }}>
                                <label for="oficina_{{ $loop->index }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $oficina }}</span>
                                </label>
                            </div>
                        </div>
                        @endforeach

                        @foreach($catalogTalleres as $taller)
                        @php $isTallerSelected = in_array($taller, $trabajador['minas'] ?? []); @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $taller }}" id="taller_{{ $loop->index }}" {{ $isTallerSelected ? 'checked' : '' }}>
                                <label for="taller_{{ $loop->index }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $taller }}</span>
                                </label>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    @foreach($catalogOficinas as $oficina)
                    <input type="hidden" name="mina_estado[{{ $oficina }}]" value="habilitado">
                    @endforeach
                    @foreach($catalogTalleres as $taller)
                    <input type="hidden" name="mina_estado[{{ $taller }}]" value="habilitado">
                    @endforeach
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('input[name="minas[]"]').forEach(function(checkbox) {
    const statusSelect = checkbox.closest('.mine-selection-item').querySelector('select');

    if (statusSelect) {
        checkbox.addEventListener('change', function() {
            if (!this.checked) {
                statusSelect.value = 'habilitado';
            }
        });
    }
});
</script>
@endpush