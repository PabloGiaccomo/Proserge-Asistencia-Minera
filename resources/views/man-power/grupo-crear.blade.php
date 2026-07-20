@extends('layouts.app')

@section('title', 'Crear Grupo - Man Power')

@php
$parada = $parada ?? [
    'id' => 1,
    'nombre' => 'BOROO - Operación Planta',
    'mina' => 'Boroo',
    'area' => 'Operación Planta',
    'fecha_inicio' => '2026-04-15',
    'fecha_fin' => '2026-04-30',
    'estado' => 'enviado',
];

$personalDisponible = [
    ['id' => 1, 'nombre' => 'Carlos Alberto López Mamani', 'dni' => '70125489', 'puesto' => 'Operador PC', 'comentario' => 'Experiencia en maquinaria pesada', 'ultimo_turno' => null, 'libre_ayer' => true],
    ['id' => 2, 'nombre' => 'Pedro Asto Yupanqui', 'dni' => '82365412', 'puesto' => 'Mecánico', 'comentario' => 'Disponible solo turno noche', 'ultimo_turno' => 'Día', 'libre_ayer' => false],
    ['id' => 3, 'nombre' => 'Ana Lucía Quispe Mamani', 'dni' => '61234567', 'puesto' => 'Técnico', 'comentario' => '', 'ultimo_turno' => null, 'libre_ayer' => true],
    ['id' => 4, 'nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '55678901', 'puesto' => 'Soldador', 'comentario' => 'Requiere equipo especial', 'ultimo_turno' => 'Noche', 'libre_ayer' => false],
    ['id' => 5, 'nombre' => 'Jorge Eduardo Tito Flores', 'dni' => '47890123', 'puesto' => 'Operador', 'comentario' => 'Sin comentarios', 'ultimo_turno' => 'Noche', 'libre_ayer' => false],
    ['id' => 6, 'nombre' => 'Carmen Rosa Torres Flores', 'dni' => '89012345', 'puesto' => 'Auxiliar', 'comentario' => 'Primera vez en esta mina', 'ultimo_turno' => null, 'libre_ayer' => true],
    ['id' => 7, 'nombre' => 'Roberto Carlos Huanca Lima', 'dni' => '61237890', 'puesto' => 'Técnico Electricista', 'comentario' => '', 'ultimo_turno' => 'Día', 'libre_ayer' => false],
    ['id' => 8, 'nombre' => 'Sofia Elizabeth Quispe Mamani', 'dni' => '73456789', 'puesto' => 'Administrativo', 'comentario' => 'Solo oficina', 'ultimo_turno' => null, 'libre_ayer' => true],
];

$supervisores = ['Olarte Cespedes Franklin Richard', 'María Elena García López', 'Juan Pérez', 'Luis Cóndor'];
@endphp

@section('content')
<div class="crear-grupo-page">
    <!-- Header -->
    <div class="header-section">
        <div class="header-top">
            <a href="{{ route('man-power.index') }}" class="btn-volver">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Volver a Man Power
            </a>
        </div>
        <div class="header-main">
            <h1 class="page-title">Nuevo grupo</h1>
            <div class="parada-info-header">
                <span class="parada-nombre">{{ $parada['nombre'] }}</span>
                <span class="parada-fechas">{{ $parada['fecha_inicio'] }} al {{ $parada['fecha_fin'] }}</span>
            </div>
        </div>
    </div>

    <div class="pasos-crear">
        <span class="paso-item is-active">1. Configura el grupo</span>
        <span class="paso-item">2. Selecciona personal</span>
        <span class="paso-item">3. Confirma y crea</span>
    </div>

    <div class="crear-grupo-layout">
        <div class="main-column">
            <div class="form-card">
                <h2 class="form-title">Datos del grupo</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fecha del grupo *</label>
                        <input type="date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Turno *</label>
                        <select class="form-select" required>
                            <option value="">Seleccionar turno</option>
                            <option>Día</option>
                            <option>Noche</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supervisor *</label>
                        <select class="form-select" required>
                            <option value="">Seleccionar supervisor</option>
                            @foreach($supervisores as $s)
                            <option>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Área / Servicio</label>
                        <input type="text" class="form-input" placeholder="Ej: Operación Planta">
                    </div>
                </div>

                <div class="form-group no-margin">
                    <label class="form-label">Comentario del grupo</label>
                    <textarea class="form-textarea" rows="2" placeholder="Observaciones adicionales..."></textarea>
                </div>
            </div>

            <div class="personal-card">
                <div class="personal-head">
                    <h2 class="form-title">Personal disponible para esta parada</h2>
                    <button type="button" class="btn-link-clear" id="btnLimpiarSeleccion">Limpiar selección</button>
                </div>

                <div class="search-box">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="searchPersonal" type="text" class="search-input" placeholder="Buscar por nombre, DNI o puesto...">
                </div>

                <div class="personal-grid" id="personalGrid">
                    @foreach($personalDisponible as $persona)
                    <div
                        class="persona-option"
                        onclick="toggleSelectPersona({{ $persona['id'] }})"
                        id="persona-{{ $persona['id'] }}"
                        data-id="{{ $persona['id'] }}"
                        data-nombre="{{ $persona['nombre'] }}"
                        data-dni="{{ $persona['dni'] }}"
                        data-puesto="{{ $persona['puesto'] }}"
                    >
                        <div class="persona-check">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div class="persona-info">
                            <span class="persona-nombre">{{ $persona['nombre'] }}</span>
                            <span class="persona-dni">DNI: {{ $persona['dni'] }}</span>
                            <span class="persona-puesto">{{ $persona['puesto'] }}</span>
                            @if($persona['comentario'])
                            <span class="persona-comentario">{{ $persona['comentario'] }}</span>
                            @endif
                            <div class="persona-badges">
                                @if($persona['ultimo_turno'])
                                <span class="badge-turno">Último: {{ $persona['ultimo_turno'] }}</span>
                                @endif
                                @if($persona['libre_ayer'])
                                <span class="badge-libre">Libre el día anterior</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <aside class="side-column">
            <div class="seleccionados-card">
                <div class="seleccionados-head">
                    <h2 class="form-title">Personal seleccionado</h2>
                    <div class="seleccionados-count" id="seleccionadosCount">0 trabajadores</div>
                </div>

                <div class="seleccionados-list" id="listaSeleccionados">
                    <div class="empty-seleccion" id="emptySeleccion">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="11" x2="23" y2="11"/></svg>
                        <span>Seleccione personal de la lista</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Footer Actions -->
    <div class="form-actions">
        <a href="{{ route('man-power.index') }}" class="btn-cancelar">Cancelar</a>
        <button class="btn-guardar">Crear Grupo</button>
    </div>
</div>

<script>
const selectedIds = new Set();

function toggleSelectPersona(id) {
    const opt = document.getElementById('persona-' + id);
    if (!opt) return;

    if (selectedIds.has(String(id))) {
        selectedIds.delete(String(id));
        opt.classList.remove('selected');
    } else {
        selectedIds.add(String(id));
        opt.classList.add('selected');
    }

    renderSeleccionados();
}

function removeSelected(id) {
    const opt = document.getElementById('persona-' + id);
    selectedIds.delete(String(id));
    if (opt) opt.classList.remove('selected');
    renderSeleccionados();
}

function renderSeleccionados() {
    const list = document.getElementById('listaSeleccionados');
    const count = document.getElementById('seleccionadosCount');
    const total = selectedIds.size;

    count.textContent = total + (total === 1 ? ' trabajador' : ' trabajadores');

    if (total === 0) {
        list.innerHTML = '<div class="empty-seleccion" id="emptySeleccion"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="11" x2="23" y2="11"/></svg><span>Seleccione personal de la lista</span></div>';
        return;
    }

    let html = '';
    selectedIds.forEach(id => {
        const opt = document.getElementById('persona-' + id);
        if (!opt) return;
        const nombre = opt.dataset.nombre || '';
        const dni = opt.dataset.dni || '';
        const puesto = opt.dataset.puesto || '';

        html += '<div class="seleccionado-item"><div class="seleccionado-main"><span class="seleccionado-nombre">' + nombre + '</span><span class="seleccionado-meta">DNI ' + dni + ' • ' + puesto + '</span></div><button type="button" class="btn-remove-selected" onclick="removeSelected(' + id + ')" aria-label="Quitar personal">×</button></div>';
    });

    list.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('searchPersonal');
    const clearBtn = document.getElementById('btnLimpiarSeleccion');
    const cards = Array.from(document.querySelectorAll('.persona-option'));

    if (input) {
        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();
            cards.forEach(card => {
                const text = (card.dataset.nombre + ' ' + card.dataset.dni + ' ' + card.dataset.puesto).toLowerCase();
                card.style.display = text.includes(term) ? 'flex' : 'none';
            });
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            selectedIds.clear();
            cards.forEach(card => card.classList.remove('selected'));
            renderSeleccionados();
        });
    }

    renderSeleccionados();
});
</script>
@endsection