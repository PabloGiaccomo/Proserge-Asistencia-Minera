@extends('layouts.app')

@section('title', 'Nuevo Trabajador - Proserge')

@section('content')
<div class="module-page personal-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Nuevo Trabajador</h1>
                <p class="page-subtitle">Registrar un nuevo trabajador en el sistema</p>
            </div>
        </div>
    </div>

    <!-- Form -->
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('personal.store') }}">
                @csrf
                
                <!-- Datos Personales -->
                <div class="form-section">
                    <h3 class="form-section-title">Datos Personales</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Nombres completos</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">DNI</label>
                            <input type="text" name="dni" class="form-control" required maxlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Datos Laborales -->
                <div class="form-section">
                    <h3 class="form-section-title">Datos Laborales</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Puesto / Cargo</label>
                            <input type="text" name="puesto" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Tipo de Contrato</label>
                            <select name="tipo_contrato" class="form-control" required>
                                <option value="">Seleccionar...</option>
                                <option value="Indeterminado">Indeterminado</option>
                                <option value="Fijo">Fijo</option>
                                <option value="Intermitente">Intermitente</option>
                                <option value="Régimen">Régimen</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">¿Es Supervisor?</label>
                            <select name="supervisor" class="form-control" required>
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Estado</label>
                            <select name="activo" class="form-control" required>
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
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
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $mina }}" id="mina_{{ str_replace(' ', '_', $mina) }}">
                                <label for="mina_{{ str_replace(' ', '_', $mina) }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $mina }}</span>
                                </label>
                            </div>
                            <div class="mine-status-select">
                                <select name="mina_estado[{{ $mina }}]" class="form-control form-control-sm">
                                    <option value="habilitado">Habilitado</option>
                                    <option value="proceso">En proceso</option>
                                    <option value="no_habilitado">No habilitado</option>
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
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $oficina }}" id="oficina_{{ $loop->index }}">
                                <label for="oficina_{{ $loop->index }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $oficina }}</span>
                                </label>
                            </div>
                        </div>
                        @endforeach

                        @foreach($catalogTalleres as $taller)
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $taller }}" id="taller_{{ $loop->index }}">
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
                    <button type="submit" class="btn btn-primary">Guardar Trabajador</button>
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