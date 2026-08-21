@extends('layouts.app')

@section('title', 'Tomar asistencia - Proserge')

@section('content')
@php
    $integrantes = collect($grupo['integrantes'] ?? []);
    $isClosed = ($grupo['asistencia']['estado'] ?? 'PENDIENTE') === 'CERRADO';
    $metrics = $grupo['asistencia']['metricas'] ?? [];
    $fecha = \Illuminate\Support\Carbon::parse($grupo['fecha'])->locale('es')->translatedFormat('d \d\e F \d\e Y');
@endphp

<div class="mia-page" id="miaAttendance" data-closed="{{ $isClosed ? '1' : '0' }}">
    <header class="mia-page-head mia-detail-head">
        <div>
            <a href="{{ route('mi-asistencia.index', ['fecha' => $grupo['fecha']]) }}" class="mia-back-link">← Volver a la jornada</a>
            <h1>{{ $grupo['destino']['nombre'] ?: $grupo['mina_nombre'] ?: 'Grupo de trabajo' }}</h1>
            <p>{{ $grupo['turno'] === 'DIA' ? 'Turno día' : 'Turno noche' }} · {{ $fecha }}</p>
        </div>
        <span class="mia-scope-badge {{ $canViewAll ? 'is-global' : 'is-own' }}">
            {{ $canViewAll ? 'Vista general' : 'Grupo a tu cargo' }}
        </span>
    </header>

    <section class="mia-account mia-account-compact">
        <div class="mia-account-avatar">{{ mb_strtoupper(mb_substr((string) ($user->name ?: $user->email), 0, 2)) }}</div>
        <div>
            <span>Registrando como</span>
            <strong>{{ $user->name ?: $user->email }}</strong>
            <small>{{ $user->email }}</small>
        </div>
        <div class="mia-account-check">Cuenta verificada para este grupo</div>
    </section>

    @if($errors->any())
        <section class="mia-notice is-error">{{ $errors->first() }}</section>
    @endif

    @if(!$grupo['supervisor']['id'] && !$grupo['asistencia']['id'] && $canRegister)
        <section class="mia-notice is-warning">
            <strong>Este grupo no tiene responsable asignado.</strong>
            <span>Como tu rol permite gestionar todas las asistencias, el registro quedara a nombre de tu cuenta.</span>
        </section>
    @elseif(!$attendanceReady)
        <section class="mia-notice is-warning">
            <strong>No se puede identificar quien registra la asistencia.</strong>
            <span>Vincula esta cuenta con un trabajador o asigna un responsable al grupo en Man Power.</span>
        </section>
    @endif

    <section class="mia-detail-summary">
        <div><span>Unidad minera</span><strong>{{ $grupo['mina_nombre'] ?: 'Sin definir' }}</strong></div>
        <div><span>Responsable</span><strong>{{ $grupo['supervisor']['nombre_completo'] ?: ($grupo['asistencia']['responsable_registro'] ?: ($canRegister ? ($user->personal?->nombre_completo ?: $user->email) : 'Sin responsable')) }}</strong></div>
        <div><span>Horario</span><strong>{{ substr((string) ($grupo['asistencia']['hora_ingreso'] ?? $grupo['horario_salida'] ?? ''), 0, 5) ?: '--:--' }}</strong></div>
        <div><span>Estado</span><strong>{{ $isClosed ? 'Cerrada' : 'Abierta' }}</strong></div>
    </section>

    <section class="mia-attendance-card">
        <div class="mia-attendance-head">
            <div>
                <h2>Personal del grupo</h2>
                <p>Marca el estado de cada trabajador. Los cambios se guardan al instante.</p>
            </div>
            @if(!$isClosed && $canRegister)
                <button type="button" class="mia-btn mia-btn-secondary" id="miaMarkAll" data-url="{{ route('mi-asistencia.marcar-todos', $grupo['grupo_id']) }}">
                    Todos presentes
                </button>
            @endif
        </div>

        @if(!$canRegister && !$isClosed && $attendanceReady)
            <div class="mia-notice is-warning mia-inline-notice">
                Puedes revisar este grupo, pero tu rol no permite registrar su asistencia.
            </div>
        @endif

        <div class="mia-live-summary" aria-live="polite">
            <span><strong data-count="PRESENTE">{{ $metrics['presentes'] ?? 0 }}</strong> presentes</span>
            <span><strong data-count="AUSENTE">{{ $metrics['ausentes'] ?? 0 }}</strong> ausentes</span>
            <span><strong data-count="TARDANZA">{{ $metrics['tardanzas'] ?? 0 }}</strong> tardanzas</span>
            <span><strong data-count="JUSTIFICADO">{{ $metrics['justificados'] ?? 0 }}</strong> justificados</span>
        </div>

        <div class="mia-worker-list">
            @forelse($integrantes as $persona)
                @php $estado = strtoupper((string) ($persona['estado_asistencia'] ?? 'PENDIENTE')); @endphp
                <article class="mia-worker" data-worker data-current-state="{{ $estado }}">
                    <div class="mia-worker-info">
                        <strong>{{ $persona['nombre_completo'] ?: 'Trabajador sin nombre' }}</strong>
                        <span>{{ $persona['dni'] ?: 'Sin documento' }} · {{ $persona['puesto'] ?: 'Sin puesto' }}</span>
                    </div>
                    <div class="mia-state-actions" role="group" aria-label="Estado de {{ $persona['nombre_completo'] }}">
                        @foreach(['PRESENTE' => 'Presente', 'TARDANZA' => 'Tardanza', 'AUSENTE' => 'Ausente', 'JUSTIFICADO' => 'Justificado'] as $value => $label)
                            <button type="button"
                                class="mia-state-btn is-{{ strtolower($value) }} {{ $estado === $value ? 'is-active' : '' }}"
                                data-state="{{ $value }}"
                                data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                                data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                                @disabled($isClosed || !$canRegister)>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <label class="mia-observation">
                        <span>Observación</span>
                        <input type="text" maxlength="1000" value="{{ $persona['observaciones'] }}" placeholder="Motivo requerido para justificación" @disabled($isClosed || !$canRegister)>
                    </label>
                </article>
            @empty
                <div class="mia-empty">
                    <strong>Este grupo no tiene integrantes activos.</strong>
                    <p>Revisa la selección del grupo en Man Power.</p>
                </div>
            @endforelse
        </div>

        @if(!$isClosed && $canClose)
            <form method="POST" action="{{ route('mi-asistencia.cerrar', $grupo['grupo_id']) }}" class="mia-close-panel">
                @csrf
                <label>
                    <span>Actividad realizada</span>
                    <input type="text" name="actividad_realizada" maxlength="1000" placeholder="Resumen breve de la jornada">
                </label>
                <label>
                    <span>Suceso u observación general</span>
                    <input type="text" name="reporte_suceso" maxlength="1000" placeholder="Opcional">
                </label>
                <button type="submit" class="mia-btn mia-btn-primary">Cerrar asistencia</button>
            </form>
        @endif
    </section>
</div>

<div class="mia-toast" id="miaToast" role="status" aria-live="polite"></div>
@endsection

@push('scripts')
<script>
(function () {
    const root = document.getElementById('miaAttendance');
    const toast = document.getElementById('miaToast');
    if (!root || !toast) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let toastTimer = null;

    const showToast = function (message, error) {
        toast.textContent = message;
        toast.classList.toggle('is-error', Boolean(error));
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () { toast.classList.remove('is-visible'); }, 3200);
    };

    const refreshCounts = function () {
        ['PRESENTE', 'AUSENTE', 'TARDANZA', 'JUSTIFICADO'].forEach(function (state) {
            const target = root.querySelector('[data-count="' + state + '"]');
            if (target) target.textContent = String(root.querySelectorAll('[data-worker][data-current-state="' + state + '"]').length);
        });
    };

    const send = async function (url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(data.message || 'No se pudo guardar la asistencia.');
        return data;
    };

    root.querySelectorAll('[data-worker] .mia-state-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            const worker = button.closest('[data-worker]');
            const observation = worker.querySelector('.mia-observation input')?.value.trim() || '';
            const state = button.dataset.state;
            if (state === 'JUSTIFICADO' && !observation) {
                showToast('Escribe el motivo antes de marcar como justificado.', true);
                worker.querySelector('.mia-observation input')?.focus();
                return;
            }

            worker.classList.add('is-saving');
            try {
                await send(button.dataset.url, {
                    grupo_trabajo_detalle_id: button.dataset.detailId,
                    estado: state,
                    hora_marcado: new Date().toTimeString().slice(0, 5),
                    observaciones: observation,
                });
                worker.dataset.currentState = state;
                worker.querySelectorAll('.mia-state-btn').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
                refreshCounts();
                showToast('Asistencia de ' + worker.querySelector('.mia-worker-info strong').textContent.trim() + ' actualizada.');
            } catch (error) {
                showToast(error.message, true);
            } finally {
                worker.classList.remove('is-saving');
            }
        });
    });

    const markAll = document.getElementById('miaMarkAll');
    if (markAll) {
        markAll.addEventListener('click', async function () {
            markAll.disabled = true;
            try {
                await send(markAll.dataset.url, {});
                root.querySelectorAll('[data-worker]').forEach(function (worker) {
                    worker.dataset.currentState = 'PRESENTE';
                    worker.querySelectorAll('.mia-state-btn').forEach(function (button) {
                        button.classList.toggle('is-active', button.dataset.state === 'PRESENTE');
                    });
                });
                refreshCounts();
                showToast('Todos fueron marcados como presentes.');
            } catch (error) {
                showToast(error.message, true);
            } finally {
                markAll.disabled = false;
            }
        });
    }
})();
</script>
@endpush
