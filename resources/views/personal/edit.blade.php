@extends('layouts.app')

@section('title', 'Editar Trabajador - Proserge')

@section('content')
@php
    $selectedLocations = collect(old('minas', $trabajador['minas'] ?? []))
        ->map(fn ($value) => trim((string) $value))
        ->filter(fn (string $value) => $value !== '')
        ->unique()
        ->values()
        ->all();

    $stateByLocation = old('mina_estado', $trabajador['minas_estado'] ?? []);
    $selectedContract = \App\Modules\Personal\Support\PersonalNormalizer::contract(old('tipo_contrato', $trabajador['contrato'] ?? null));
    $selectedSupervisor = (string) old('supervisor', !empty($trabajador['supervisor']) ? '1' : '0');
    $selectedActive = (string) old('activo', ($trabajador['activo'] ?? true) ? '1' : '0');
@endphp
<div class="module-page personal-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Editar Trabajador</h1>
                <p class="page-subtitle">Modificar los datos del trabajador</p>
                <div style="margin-top:10px;">
                    <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        Volver
                    </a>
                </div>
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
                            <input type="text" name="nombre" class="form-control" value="{{ old('nombre', $trabajador['nombre'] ?? '') }}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">DNI</label>
                            <input type="text" name="dni" class="form-control" value="{{ old('dni', $trabajador['dni'] ?? '') }}" required maxlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" value="{{ old('telefono', $trabajador['telefono'] ?? '') }}" placeholder="Ingrese teléfono (opcional)">
                        </div>
                    </div>
                </div>

                <!-- Datos Laborales -->
                <div class="form-section">
                    <h3 class="form-section-title">Datos Laborales</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Puesto / Cargo</label>
                            <input type="text" name="puesto" class="form-control" value="{{ old('puesto', $trabajador['puesto'] ?? '') }}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Tipo de Contrato</label>
                            <select name="tipo_contrato" class="form-control" required>
                                <option value="REG" {{ $selectedContract === 'REG' ? 'selected' : '' }}>Régimen</option>
                                <option value="FIJO" {{ $selectedContract === 'FIJO' ? 'selected' : '' }}>Fijo</option>
                                <option value="INTER" {{ $selectedContract === 'INTER' ? 'selected' : '' }}>Intermitente</option>
                                <option value="INDET" {{ $selectedContract === 'INDET' ? 'selected' : '' }}>Indeterminado</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">¿Es Supervisor?</label>
                            <select name="supervisor" class="form-control" required>
                                <option value="0" {{ $selectedSupervisor === '0' ? 'selected' : '' }}>No</option>
                                <option value="1" {{ $selectedSupervisor === '1' ? 'selected' : '' }}>Sí</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Estado</label>
                            <select name="activo" class="form-control" required>
                                <option value="1" {{ $selectedActive === '1' ? 'selected' : '' }}>Activo</option>
                                <option value="0" {{ $selectedActive === '0' ? 'selected' : '' }}>Inactivo</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Ubicación en Minas -->
                <div class="form-section">
                    <h3 class="form-section-title">Ubicación en Minas</h3>
                    <p class="form-section-desc">Selecciona las minas donde trabajará el trabajador y su estado en cada una.</p>

                    <div class="mines-grid">
                        @forelse($catalogMinas as $mina)
                        @php
                        $isSelected = in_array($mina, $selectedLocations, true);
                        $estado = (string) ($stateByLocation[$mina] ?? 'habilitado');
                        @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $mina }}" id="mina_{{ md5($mina) }}" {{ $isSelected ? 'checked' : '' }}>
                                <label for="mina_{{ md5($mina) }}" class="mine-checkbox-label">
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
                        @empty
                        <p class="form-section-desc">No hay minas activas registradas.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Oficinas y Talleres -->
                <div class="form-section">
                    <h3 class="form-section-title">Oficinas y Talleres</h3>
                    <p class="form-section-desc">Indica si el trabajador también puede laborar en oficina y/o taller.</p>

                    <div class="mines-grid">
                        @if(empty($catalogOficinas) && empty($catalogTalleres))
                        <p class="form-section-desc">No hay oficinas ni talleres registrados.</p>
                        @endif

                        @foreach($catalogOficinas as $oficina)
                        @php $isOficinaSelected = in_array($oficina, $selectedLocations, true); @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $oficina }}" id="oficina_{{ md5($oficina) }}" {{ $isOficinaSelected ? 'checked' : '' }}>
                                <label for="oficina_{{ md5($oficina) }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $oficina }}</span>
                                </label>
                            </div>
                        </div>
                        @endforeach

                        @foreach($catalogTalleres as $taller)
                        @php $isTallerSelected = in_array($taller, $selectedLocations, true); @endphp
                        <div class="mine-selection-item">
                            <div class="mine-checkbox">
                                <input type="checkbox" name="minas[]" value="{{ $taller }}" id="taller_{{ md5($taller) }}" {{ $isTallerSelected ? 'checked' : '' }}>
                                <label for="taller_{{ md5($taller) }}" class="mine-checkbox-label">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-text">{{ $taller }}</span>
                                </label>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    @foreach($catalogOficinas as $oficina)
                    <input type="hidden" name="mina_estado[{{ $oficina }}]" value="{{ $stateByLocation[$oficina] ?? 'habilitado' }}">
                    @endforeach
                    @foreach($catalogTalleres as $taller)
                    <input type="hidden" name="mina_estado[{{ $taller }}]" value="{{ $stateByLocation[$taller] ?? 'habilitado' }}">
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
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="minas[]"]').forEach(function(checkbox) {
        const statusSelect = checkbox.closest('.mine-selection-item').querySelector('select');

        if (!statusSelect) {
            return;
        }

        const syncStatusControl = function() {
            statusSelect.disabled = !checkbox.checked;

            if (!checkbox.checked) {
                statusSelect.value = 'habilitado';
            }
        };

        checkbox.addEventListener('change', function() {
            syncStatusControl();
        });

        syncStatusControl();
    });
});
</script>
@endpush
