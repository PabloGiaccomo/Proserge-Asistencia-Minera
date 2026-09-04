@extends('layouts.app')

@section('title', 'Tomar asistencia - Proserge')

@section('content')
@php
    $integrantes = collect($grupo['integrantes'] ?? []);
    $isClosed = ($grupo['asistencia']['estado'] ?? 'PENDIENTE') === 'CERRADO';
    $metrics = $grupo['asistencia']['metricas'] ?? [];
    $fecha = \Illuminate\Support\Carbon::parse($grupo['fecha'])->locale('es')->translatedFormat('d \d\e F \d\e Y');
@endphp

<div
    class="mia-page"
    id="miaAttendance"
    data-closed="{{ $isClosed ? '1' : '0' }}"
    data-dni-lookup-url="{{ route('mi-asistencia.buscar-dni', $grupo['grupo_id']) }}"
    data-status-url="{{ route('mi-asistencia.estado', $grupo['grupo_id']) }}"
    data-schedule="{{ json_encode($grupo['horario_marcacion']) }}"
>
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
        <div><span>Horario</span><strong>{{ substr((string) ($grupo['horario_salida'] ?? ''), 0, 5) ?: '--:--' }}</strong></div>
        <div><span>Estado</span><strong>{{ $isClosed ? 'Cerrada' : 'Abierta' }}</strong></div>
    </section>

    <section class="mia-attendance-card">
        @if(!$isClosed && $canRegister)
            <div class="mia-scanner-bar">
                <div class="mia-scanner-label">
                    <span class="mia-scanner-label-dot"></span>
                    Lector de DNI
                </div>
                <div class="mia-scanner-actions">
                    <button type="button" class="mia-scan-cam-btn" id="miaOpenCamera">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Abrir camara
                    </button>
                    <div class="mia-scan-or">o</div>
                    <div class="mia-scanner-input-wrap">
                        <input type="text" id="miaDniInput" class="mia-scanner-input" placeholder="Escribir DNI..." autocomplete="off" inputmode="numeric">
                    </div>
                </div>
                <div id="miaScanResult" class="mia-scan-result" style="display:none;"></div>
                <section class="mia-scan-findings" id="miaScanFindings" hidden aria-live="polite">
                    <div class="mia-scan-findings-head">
                        <div>
                            <strong>Lecturas fuera del grupo</strong>
                            <span>Se muestran para identificar a la persona, pero no se agregan a esta asistencia.</span>
                        </div>
                        <button type="button" id="miaClearFindings">Limpiar</button>
                    </div>
                    <div class="mia-scan-findings-list" id="miaScanFindingsList"></div>
                </section>
            </div>

            <div id="miaCameraModal" class="mia-camera-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="miaCameraTitle" aria-hidden="true">
                <div class="mia-camera-box">
                    <div class="mia-camera-header">
                        <span id="miaCameraTitle">Escanear codigo de barras</span>
                        <button type="button" class="mia-camera-close" id="miaCloseCamera" aria-label="Cerrar lector">&#10005;</button>
                    </div>
                    <div id="miaCameraPreview" class="mia-camera-preview"></div>
                    <div class="mia-camera-footer">
                        <p class="mia-camera-hint" id="miaCameraHint">Apunta al codigo de barras del DNI</p>
                        <div id="miaCameraResult" class="mia-scan-result" role="status" aria-live="polite" style="display:none;"></div>
                        <button type="button" class="mia-camera-photo-btn" id="miaBarcodePhotoButton">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            Tomar foto del codigo
                        </button>
                        <input type="file" id="miaBarcodePhotoInput" accept="image/*" capture="environment" hidden>
                    </div>
                </div>
            </div>
        @endif

        <div class="mia-attendance-head">
            <div>
                <h2>Personal del grupo</h2>
                <p>Marca el estado de cada trabajador. Los cambios se guardan al instante.</p>
            </div>
            @if(!$isClosed && $canRegister)
                <button type="button" class="mia-btn mia-btn-secondary" id="miaMarkAll" data-url="{{ route('mi-asistencia.marcar-todos', $grupo['grupo_id']) }}" title="Marcar todos presentes" @disabled(!$grupo['horario_marcacion']['permite_presente'])>
                    &#10003; Todos
                </button>
            @endif
        </div>

        <div class="mia-notice is-warning mia-inline-notice" id="miaScheduleNotice" @if(!$grupo['horario_marcacion']['mensaje']) hidden @endif>{{ $grupo['horario_marcacion']['mensaje'] }}</div>

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
                <article
                    class="mia-worker"
                    data-worker
                    data-dni="{{ preg_replace('/\D+/', '', (string) ($persona['dni'] ?? '')) }}"
                    data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                    data-current-state="{{ $estado }}"
                    data-expanded="false"
                >
                    <button type="button" class="mia-worker-info" data-toggle-worker aria-expanded="false" aria-controls="miaWorkerPanel-{{ $loop->index }}">
                        <span class="mia-worker-identity">
                        <strong>{{ $persona['nombre_completo'] ?: 'Trabajador sin nombre' }}</strong>
                        <span>{{ $persona['dni'] ?: 'Sin documento' }} · {{ $persona['puesto'] ?: 'Sin puesto' }}</span>
                        </span>
                        <span class="mia-worker-summary">
                            <span class="mia-worker-status" data-worker-status>{{ ucfirst(strtolower($estado)) }}</span>
                            <span class="mia-time-badge" @if(empty($persona['hora_marcado'])) hidden @endif>{{ substr((string) ($persona['hora_marcado'] ?? ''), 0, 5) }}</span>
                        </span>
                        <span class="mia-expand-icon" aria-hidden="true">▾</span>
                    </button>
                    <div class="mia-worker-panel" id="miaWorkerPanel-{{ $loop->index }}" data-worker-panel hidden>
                    <div class="mia-state-actions" role="group" aria-label="Estado de {{ $persona['nombre_completo'] }}">
                        @php $horaMarcado = $persona['hora_marcado'] ?? ''; @endphp
                        <button type="button"
                            class="mia-state-btn is-presente {{ $estado === 'PRESENTE' ? 'is-active' : '' }}"
                            data-state="PRESENTE"
                            data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                            data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                            title="Presente"
                            aria-label="Presente"
                            aria-pressed="{{ $estado === 'PRESENTE' ? 'true' : 'false' }}"
                            @disabled($isClosed || !$canRegister || !$grupo['horario_marcacion']['permite_presente'])>
                            <span aria-hidden="true">&#10003;</span> Presente
                        </button>
                        <button type="button"
                            class="mia-state-btn is-tardanza {{ $estado === 'TARDANZA' ? 'is-active' : '' }}"
                            data-state="TARDANZA"
                            data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                            data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                            title="Tardanza"
                            aria-label="Tardanza"
                            aria-pressed="{{ $estado === 'TARDANZA' ? 'true' : 'false' }}"
                            @disabled($isClosed || !$canRegister)>
                            <span aria-hidden="true">&#9201;</span> Tardanza
                        </button>
                        <button type="button"
                            class="mia-state-btn is-ausente {{ $estado === 'AUSENTE' ? 'is-active' : '' }}"
                            data-state="AUSENTE"
                            data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                            data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                            title="Ausente"
                            aria-label="Ausente"
                            aria-pressed="{{ $estado === 'AUSENTE' ? 'true' : 'false' }}"
                            @disabled($isClosed || !$canRegister)>
                            <span aria-hidden="true">&#10007;</span> Ausente
                        </button>
                        <button type="button"
                            class="mia-state-btn is-justificado {{ $estado === 'JUSTIFICADO' ? 'is-active' : '' }}"
                            data-state="JUSTIFICADO"
                            data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                            data-detail-id="{{ $persona['grupo_trabajo_detalle_id'] }}"
                            title="Justificado"
                            aria-label="Justificado"
                            aria-pressed="{{ $estado === 'JUSTIFICADO' ? 'true' : 'false' }}"
                            @disabled($isClosed || !$canRegister)>
                            <span aria-hidden="true">&#9998;</span> Justificado
                        </button>
                    </div>
                    <label class="mia-observation">
                        <span>Observación</span>
                        <input type="text" maxlength="1000" value="{{ $persona['observaciones'] }}" placeholder="Motivo requerido para justificación" @disabled($isClosed || !$canRegister)>
                    </label>
                    </div>
                </article>
            @empty
                <div class="mia-empty">
                    <strong>Este grupo no tiene integrantes activos.</strong>
                    <p>Revisa la selección del grupo en Man Power.</p>
                </div>
            @endforelse
        </div>

        @if(!$isClosed && $canClose)
            <div class="mia-close-panel">
                <form method="POST" action="{{ route('mi-asistencia.cerrar', $grupo['grupo_id']) }}" style="display:contents;">
                    @csrf
                    <label>
                        <span>Actividad realizada</span>
                        <input type="text" name="actividad_realizada" maxlength="1000" placeholder="Resumen breve de la jornada">
                    </label>
                    <label>
                        <span>Suceso u observación general</span>
                        <input type="text" name="reporte_suceso" maxlength="1000" placeholder="Opcional">
                    </label>
                    <button type="submit" class="mia-btn mia-btn-outline" onclick="return confirm('¿Cerrar la asistencia del dia? Ya no podras modificar los registros.');">&#128274; Cerrar</button>
                </form>
            </div>
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

    const setWorkerExpanded = function (worker, expanded) {
        worker.dataset.expanded = String(expanded);
        worker.querySelector('[data-toggle-worker]').setAttribute('aria-expanded', String(expanded));
        worker.querySelector('[data-worker-panel]').hidden = !expanded;
    };

    root.querySelectorAll('[data-toggle-worker]').forEach(function (info) {
        info.addEventListener('click', function () {
            var worker = info.closest('[data-worker]');
            if (!worker) return;
            var isExpanded = worker.getAttribute('data-expanded') === 'true';
            root.querySelectorAll('[data-worker][data-expanded="true"]').forEach(function (w) {
                setWorkerExpanded(w, false);
            });
            setWorkerExpanded(worker, !isExpanded);
        });
    });

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
        root.querySelectorAll('[data-worker]').forEach(function (worker) {
            const state = worker.dataset.currentState;
            const status = worker.querySelector('[data-worker-status]');
            if (status) status.textContent = state.charAt(0) + state.slice(1).toLowerCase();
            worker.querySelectorAll('.mia-state-btn').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button.dataset.state === state));
                button.classList.toggle('is-active', button.dataset.state === state);
            });
            const timeBadge = worker.querySelector('.mia-time-badge');
            if (timeBadge) timeBadge.hidden = !timeBadge.textContent.trim();
        });
        ['PRESENTE', 'AUSENTE', 'TARDANZA', 'JUSTIFICADO'].forEach(function (state) {
            const target = root.querySelector('[data-count="' + state + '"]');
            if (target) target.textContent = String(root.querySelectorAll('[data-worker][data-current-state="' + state + '"]').length);
        });
    };

    var attendanceRevision = 0;
    const send = async function (url, payload) {
        attendanceRevision++;
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
        attendanceRevision++;
        if (!response.ok) throw new Error(data.message || 'No se pudo guardar la asistencia.');
        return data;
    };

    root.querySelectorAll('[data-worker] .mia-state-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            const worker = button.closest('[data-worker]');
            if (worker.classList.contains('is-saving')) return;
            const observation = worker.querySelector('.mia-observation input')?.value.trim() || '';
            const state = button.dataset.state;
            if (state === 'JUSTIFICADO' && !observation) {
                showToast('Escribe el motivo antes de marcar como justificado.', true);
                worker.querySelector('.mia-observation input')?.focus();
                return;
            }

            worker.classList.add('is-saving');
            try {
                const saved = await send(button.dataset.url, {
                    grupo_trabajo_detalle_id: button.dataset.detailId,
                    estado: state,
                    hora_marcado: new Date().toTimeString().slice(0, 5),
                    observaciones: observation,
                });
                worker.dataset.currentState = saved.estado || state;
                worker.querySelectorAll('.mia-state-btn').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
                var timeBadge = worker.querySelector('.mia-time-badge');
                var timeStr = (saved.hora_marcado || '').slice(0, 5);
                if (timeBadge) {
                    timeBadge.textContent = timeStr;
                    timeBadge.hidden = !timeStr;
                } else {
                    var badge = document.createElement('span');
                    badge.className = 'mia-time-badge';
                    badge.textContent = timeStr;
                    worker.querySelector('.mia-state-actions')?.appendChild(badge);
                }
                refreshCounts();
                showToast('Asistencia de ' + worker.querySelector('.mia-worker-info strong').textContent.trim() + ' actualizada.');
            } catch (error) {
                showToast(error.message, true);
            } finally {
                worker.classList.remove('is-saving');
                refreshAttendance();
            }
        });
    });

    const markAll = document.getElementById('miaMarkAll');
    if (markAll) {
        markAll.addEventListener('click', async function () {
            markAll.disabled = true;
            try {
                await send(markAll.dataset.url, {});
                var nowAll = new Date();
                var timeAll = String(nowAll.getHours()).padStart(2, '0') + ':' + String(nowAll.getMinutes()).padStart(2, '0');
                root.querySelectorAll('[data-worker]').forEach(function (worker) {
                    worker.dataset.currentState = 'PRESENTE';
                    worker.querySelectorAll('.mia-state-btn').forEach(function (button) {
                        button.classList.toggle('is-active', button.dataset.state === 'PRESENTE');
                    });
                    var tb = worker.querySelector('.mia-time-badge');
                    if (tb) { tb.textContent = timeAll; } else {
                        var b = document.createElement('span'); b.className = 'mia-time-badge'; b.textContent = timeAll;
                        worker.querySelector('.mia-state-actions')?.appendChild(b);
                    }
                });
                refreshCounts();
                showToast('Todos fueron marcados como presentes.');
            } catch (error) {
                showToast(error.message, true);
            } finally {
                updateScheduleControls();
                refreshAttendance();
            }
        });
    }

    var schedule = JSON.parse(root.dataset.schedule || '{}');
    var scheduleReceivedAt = performance.now();
    var canRegisterAttendance = @json((bool) $canRegister);
    var updateScheduleControls = function () {
        var serverNow = Date.parse(schedule.servidor) + (performance.now() - scheduleReceivedAt);
        var expired = !!schedule.vencido || (schedule.limite && serverNow > Date.parse(schedule.limite));
        var allowed = canRegisterAttendance && root.dataset.closed !== '1' && schedule.permite_presente && !expired;
        root.querySelectorAll('.mia-state-btn[data-state="PRESENTE"]').forEach(function (button) { button.disabled = !allowed; });
        if (markAll) markAll.disabled = !allowed;
        var notice = document.getElementById('miaScheduleNotice');
        if (notice) {
            notice.textContent = schedule.mensaje || (expired ? 'Horario vencido. Los pendientes quedan ausentes; puedes registrar tardanza o justificar con un motivo.' : '');
            notice.hidden = !notice.textContent;
        }
        if (root.dataset.closed === '1') {
            root.querySelectorAll('.mia-state-btn, #miaOpenCamera, #miaDniInput').forEach(function (item) { item.disabled = true; });
        }
    };
    var refreshingAttendance = false;
    var refreshAttendance = async function () {
        if (refreshingAttendance || document.hidden || processingCode || root.querySelector('.is-saving')) return;
        refreshingAttendance = true;
        var revision = attendanceRevision;
        try {
            var response = await fetch(root.dataset.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            var data = await response.json();
            if (revision !== attendanceRevision || processingCode || root.querySelector('.is-saving')) return;
            schedule = data.horario;
            scheduleReceivedAt = performance.now();
            root.dataset.closed = data.cerrado ? '1' : '0';
            data.integrantes.forEach(function (item) {
                var worker = Array.from(root.querySelectorAll('[data-worker]')).find(function (row) { return row.dataset.detailId === item.id; });
                if (!worker) return;
                worker.dataset.currentState = item.estado;
                var badge = worker.querySelector('.mia-time-badge');
                if (badge) { badge.textContent = (item.hora || '').slice(0, 5); badge.hidden = !item.hora; }
            });
            refreshCounts();
            updateScheduleControls();
        } catch (error) { /* Retry on the next refresh without interrupting the camera. */ }
        finally { refreshingAttendance = false; }
    };
    updateScheduleControls();
    var scheduleTimer = window.setInterval(updateScheduleControls, 1000);
    var attendanceTimer = window.setInterval(refreshAttendance, 10000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshAttendance(); });
    window.addEventListener('pagehide', function () { window.clearInterval(scheduleTimer); window.clearInterval(attendanceTimer); });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        scheduleTimer = window.setInterval(updateScheduleControls, 1000);
        attendanceTimer = window.setInterval(refreshAttendance, 10000);
        refreshAttendance();
    });

    /* ---------- Lector de DNI: logica comun ---------- */
    var dniInput = document.getElementById('miaDniInput');
    var scanResult = document.getElementById('miaScanResult');
    var scanFindings = document.getElementById('miaScanFindings');
    var scanFindingsList = document.getElementById('miaScanFindingsList');
    var clearFindings = document.getElementById('miaClearFindings');
    var dniLookupUrl = root.dataset.dniLookupUrl || '';
    var recentScans = {};
    var scanTimer = null;
    var inputTimer = null;
    var processingCode = false;

    var showScanResult = function (type, message) {
        if (cameraResult && cameraModal?.getAttribute('aria-hidden') === 'false') {
            cameraResult.className = 'mia-scan-result is-' + type;
            cameraResult.textContent = message;
            cameraResult.style.display = 'block';
        }
        if (!scanResult) return;
        scanResult.className = 'mia-scan-result is-' + type;
        scanResult.textContent = message;
        scanResult.style.display = 'block';
        window.clearTimeout(scanTimer);
        scanTimer = window.setTimeout(function () { scanResult.style.display = 'none'; }, 4000);
    };

    var markWorkerPresent = async function (worker) {
        var button = worker?.querySelector('.mia-state-btn.is-presente');
        if (!button) throw new Error('No se encontro el registro del trabajador en el grupo.');
        if (['PRESENTE', 'TARDANZA', 'JUSTIFICADO'].includes(worker.dataset.currentState)) {
            var existingName = worker.querySelector('.mia-worker-info strong')?.textContent.trim() || 'El trabajador';
            showScanResult('not-found', existingName + ' ya esta marcado como ' + worker.dataset.currentState.toLowerCase() + '.');
            showToast(existingName + ' ya estaba marcado.', true);
            return;
        }

        worker.classList.add('is-saving');
        try {
            var saved = await send(button.dataset.url, {
                grupo_trabajo_detalle_id: button.dataset.detailId,
                estado: 'PRESENTE',
                registrar_llegada: true,
                hora_marcado: new Date().toTimeString().slice(0, 5),
                observaciones: '',
            });
            worker.dataset.currentState = saved.estado;
            worker.querySelectorAll('.mia-state-btn').forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.state === saved.estado);
            });
            var ts = (saved.hora_marcado || '').slice(0, 5);
            var tb = worker.querySelector('.mia-time-badge');
            if (tb) { tb.textContent = ts; tb.hidden = !ts; } else {
                var badge = document.createElement('span'); badge.className = 'mia-time-badge'; badge.textContent = ts;
                worker.querySelector('.mia-state-actions')?.appendChild(badge);
            }
            refreshCounts();
            var name = worker.querySelector('.mia-worker-info strong')?.textContent.trim() || 'Trabajador';
            showScanResult('success', name + ' registrado como ' + saved.estado.toLowerCase() + ' a las ' + ts + '.');
            showToast(name + ' registrado como ' + saved.estado.toLowerCase() + '.');
        } finally {
            worker.classList.remove('is-saving');
        }
    };

    var renderFinding = function (result) {
        if (!scanFindings || !scanFindingsList) return;

        var dni = String(result.dni || 'Sin documento');
        var existing = Array.from(scanFindingsList.children).find(function (item) {
            return item.dataset.dni === dni;
        });
        if (existing) existing.remove();

        var row = document.createElement('article');
        row.className = 'mia-scan-finding ' + (result.reconocido ? 'is-known' : 'is-unknown');
        row.dataset.dni = dni;

        var identity = document.createElement('div');
        var name = document.createElement('strong');
        var detail = document.createElement('span');
        name.textContent = result.reconocido ? (result.personal?.nombre || 'Trabajador sin nombre') : 'DNI no reconocido';
        detail.textContent = result.reconocido
            ? dni + ' · ' + (result.personal?.puesto || 'Sin puesto')
            : dni;
        identity.append(name, detail);

        var status = document.createElement('span');
        status.className = 'mia-scan-finding-status';
        status.textContent = result.reconocido ? 'Fuera del grupo' : 'No reconocido';
        row.append(identity, status);
        scanFindingsList.prepend(row);

        while (scanFindingsList.children.length > 8) {
            scanFindingsList.lastElementChild?.remove();
        }
        scanFindings.hidden = false;
    };

    var lookupDni = async function (codigo) {
        var response = await fetch(dniLookupUrl + '?codigo=' + encodeURIComponent(codigo), {
            headers: { 'Accept': 'application/json' },
        });
        var data = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(data.message || 'No se pudo consultar el DNI.');
        return data;
    };

    var processDni = async function (codigo) {
        codigo = String(codigo || '').trim();
        var scanKey = codigo.replace(/\s+/g, '');
        if (!scanKey || scanKey.replace(/\D/g, '').length < 6 || processingCode) return;
        if (recentScans[scanKey] && (Date.now() - recentScans[scanKey]) < 2000) return;

        processingCode = true;
        if (dniInput) dniInput.disabled = true;
        try {
            var result = await lookupDni(codigo);
            var dni = String(result.dni || codigo.replace(/\D/g, ''));
            recentScans[scanKey] = Date.now();

            if (!result.reconocido) {
                renderFinding(result);
                showScanResult('not-found', 'DNI ' + dni + ' no reconocido en el sistema.');
                showToast('DNI ' + dni + ' no reconocido.', true);
                return true;
            }

            if (!result.pertenece_grupo) {
                renderFinding(result);
                showScanResult('not-found', result.personal.nombre + ' existe en Personal, pero no pertenece a este grupo.');
                showToast('Trabajador identificado, pero fuera de la lista del grupo.', true);
                return true;
            }

            var worker = Array.from(root.querySelectorAll('[data-worker]')).find(function (item) {
                return item.dataset.detailId === String(result.grupo_trabajo_detalle_id || '');
            });
            if (!worker) throw new Error('El trabajador pertenece al grupo, pero su fila no esta disponible. Recarga la pagina.');

            await markWorkerPresent(worker);
            if (cameraModal?.getAttribute('aria-hidden') !== 'false') {
                worker.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return true;
        } catch (error) {
            showScanResult('error', error.message);
            showToast(error.message, true);
            return false;
        } finally {
            processingCode = false;
            if (dniInput) {
                dniInput.disabled = false;
                dniInput.value = '';
                if (cameraModal?.getAttribute('aria-hidden') !== 'false') {
                    dniInput.focus({ preventScroll: true });
                }
            }
        }
    };

    if (clearFindings) {
        clearFindings.addEventListener('click', function () {
            if (scanFindingsList) scanFindingsList.innerHTML = '';
            if (scanFindings) scanFindings.hidden = true;
        });
    }

    if (dniInput) {
        dniInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                window.clearTimeout(inputTimer);
                processDni(dniInput.value);
            }
        });
        dniInput.addEventListener('input', function () {
            window.clearTimeout(inputTimer);
            if (dniInput.value.replace(/\D/g, '').length < 8) return;
            inputTimer = window.setTimeout(function () { processDni(dniInput.value); }, 350);
        });
    }

    /* ---------- Camara para escanear codigo de barras ---------- */
    var openCamBtn = document.getElementById('miaOpenCamera');
    var closeCamBtn = document.getElementById('miaCloseCamera');
    var cameraModal = document.getElementById('miaCameraModal');
    var cameraPreview = document.getElementById('miaCameraPreview');
    var cameraResult = document.getElementById('miaCameraResult');
    var photoInput = document.getElementById('miaBarcodePhotoInput');
    var photoButton = document.getElementById('miaBarcodePhotoButton');
    var cameraVersion = 0;
    var cameraStream = null;
    var cameraDetector = null;
    var cameraControls = null;
    var cameraScanLoop = null;
    var cameraScannedCodes = new Set();

    var processCameraCode = async function (value, version) {
        var key = String(value || '').replace(/\s+/g, '');
        if (version !== cameraVersion || processingCode || cameraScannedCodes.has(key)) return;
        if (key.replace(/\D/g, '').length < 6) return;
        var handled = await processDni(value);
        if (handled && version === cameraVersion) cameraScannedCodes.add(key);
    };

    var stopCamera = function () {
        cameraVersion++;
        if (cameraScanLoop) { cancelAnimationFrame(cameraScanLoop); cameraScanLoop = null; }
        if (cameraControls) {
            cameraControls.stop();
            cameraControls = null;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (t) { t.stop(); });
            cameraStream = null;
        }
        cameraDetector = null;
        if (cameraPreview) cameraPreview.innerHTML = '';
        if (cameraModal) {
            cameraModal.style.display = 'none';
            cameraModal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('mia-camera-open');
    };

    var createBarcodeReader = function () {
        var zxing = window.ProsergeBarcodeScanner;
        if (!zxing?.BrowserMultiFormatReader) throw new Error('No se pudo cargar el lector.');
        var reader = new zxing.BrowserMultiFormatReader(undefined, {
            delayBetweenScanAttempts: 160,
            delayBetweenScanSuccess: 800,
        });
        reader.possibleFormats = [zxing.BarcodeFormat.PDF_417, zxing.BarcodeFormat.CODE_128, zxing.BarcodeFormat.CODE_39, zxing.BarcodeFormat.EAN_13];
        return reader;
    };

    var scanFromCamera = async function () {
        if (!cameraStream || !cameraDetector) return;
        var version = cameraVersion;
        try {
            var video = cameraPreview.querySelector('video');
            if (!video || video.readyState < 2) {
                cameraScanLoop = requestAnimationFrame(scanFromCamera);
                return;
            }
            var barcodes = await cameraDetector.detect(video);
            if (version !== cameraVersion) return;
            if (barcodes.length > 0) {
                var value = String(barcodes[0].rawValue || '').trim();
                if (value.replace(/\D/g, '').length >= 6) {
                    await processCameraCode(value, version);
                }
            }
        } catch (e) { /* ignore detection errors */ }
        if (version === cameraVersion && cameraStream) {
            cameraScanLoop = requestAnimationFrame(scanFromCamera);
        }
    };

    var startCamera = async function () {
        if (!cameraModal || !cameraPreview) return;

        stopCamera();
        var version = cameraVersion;
        cameraScannedCodes.clear();
        if (cameraResult) {
            cameraResult.textContent = '';
            cameraResult.style.display = 'none';
        }
        cameraModal.style.display = 'flex';
        cameraModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('mia-camera-open');
        cameraPreview.innerHTML = '<div class="mia-camera-message">Solicitando permiso de camara...</div>';

        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                throw new Error('La camara requiere HTTPS o abrir el sistema desde localhost.');
            }

            var videoConstraints = { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: { ideal: 'environment' } };
            try {
                var stream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints, audio: false });
            } catch (e2) {
                if (version !== cameraVersion) return;
                if (e2.name !== 'OverconstrainedError' && e2.name !== 'NotFoundError') throw e2;
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            }
            if (version !== cameraVersion) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                return;
            }
            cameraStream = stream;

            cameraPreview.innerHTML = '';
            var video = document.createElement('video');
            video.playsInline = true;
            video.muted = true;
            video.autoplay = true;
            video.setAttribute('autoplay', '');
            video.setAttribute('playsinline', '');
            video.setAttribute('muted', '');
            video.srcObject = cameraStream;
            cameraPreview.appendChild(video);

            var scanLine = document.createElement('div');
            scanLine.className = 'mia-camera-scan-line';
            cameraPreview.appendChild(scanLine);

            await video.play();
            if (version !== cameraVersion) return;

            if (!window.ProsergeBarcodeScanner && window.loadProsergeBarcodeScanner) {
                try {
                    await window.loadProsergeBarcodeScanner();
                } catch (loadError) {
                    console.error('No se pudo cargar el lector de codigos.', loadError);
                }
            }

            if (window.ProsergeBarcodeScanner?.BrowserMultiFormatReader) {
                if (version !== cameraVersion) return;
                cameraDetector = createBarcodeReader();
                var controls = await cameraDetector.decodeFromStream(cameraStream, video, function (result) {
                    if (!result || version !== cameraVersion) return;

                    var value = String(result.getText() || '').trim();
                    if (value.replace(/\D/g, '').length < 6) return;

                    processCameraCode(value, version);
                });
                if (version !== cameraVersion) { controls.stop(); return; }
                cameraControls = controls;
                var zxingHint = cameraModal.querySelector('.mia-camera-hint');
                if (zxingHint) {
                    zxingHint.textContent = 'Alinea el codigo de barras del DNI dentro de la guia. La lectura se registrara automaticamente.';
                }
            } else if ('BarcodeDetector' in window) {
                try {
                    var formats = await BarcodeDetector.getSupportedFormats();
                    if (version !== cameraVersion) return;
                    var dniFormats = ['pdf417', 'code_128', 'code_39', 'ean_13'].filter(function (format) {
                        return formats.includes(format);
                    });
                    cameraDetector = new BarcodeDetector(dniFormats.length ? { formats: dniFormats } : undefined);
                } catch (e) {
                    if (version !== cameraVersion) return;
                    cameraDetector = new BarcodeDetector();
                }
                scanFromCamera();
            } else {
                var hint = cameraModal.querySelector('.mia-camera-hint');
                if (hint) {
                    hint.textContent = 'El lector no termino de cargar. Recarga la pagina antes de volver a intentarlo o escribe el DNI.';
                }
            }
        } catch (err) {
            if (version !== cameraVersion) return;
            stopCamera();
            cameraModal.style.display = 'flex';
            cameraModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('mia-camera-open');
            var message = document.createElement('div');
            message.className = 'mia-camera-message is-error';
            var heading = document.createElement('strong');
            var detail = document.createElement('span');
            var fallback = document.createElement('span');
            heading.textContent = 'No se pudo acceder a la camara';
            detail.textContent = err.message || 'Revisa el permiso de camara del navegador.';
            fallback.textContent = 'Puedes tomar una foto del codigo o escribir el DNI manualmente.';
            message.append(heading, detail, fallback);
            cameraPreview.appendChild(message);
            showToast('No se pudo abrir la camara. Revisa los permisos del navegador.', true);
        }
    };

    if (openCamBtn) openCamBtn.addEventListener('click', startCamera);
    if (closeCamBtn) closeCamBtn.addEventListener('click', stopCamera);
    if (photoButton && photoInput) {
        photoButton.addEventListener('click', function () {
            stopCamera();
            cameraModal.style.display = 'flex';
            cameraModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('mia-camera-open');
            cameraPreview.innerHTML = '<div class="mia-camera-message">Selecciona una foto del codigo de barras.</div>';
            photoInput.value = '';
            photoInput.click();
        });
        photoInput.addEventListener('change', async function () {
            var file = photoInput.files?.[0];
            if (!file) return;
            var version = cameraVersion;
            var url = URL.createObjectURL(file);
            photoButton.disabled = true;
            try {
                var photo = new Image();
                photo.alt = 'Codigo de barras seleccionado';
                await new Promise(function (resolve, reject) {
                    photo.onload = resolve;
                    photo.onerror = reject;
                    photo.src = url;
                });
                if (!window.ProsergeBarcodeScanner) await window.loadProsergeBarcodeScanner();
                if (version !== cameraVersion) return;
                cameraPreview.replaceChildren(photo);
                var result = await createBarcodeReader().decodeFromImageElement(photo);
                if (version !== cameraVersion) return;
                var value = String(result.getText() || '').trim();
                if (value.replace(/\D/g, '').length < 6) throw new Error('Codigo no reconocido.');
                await processCameraCode(value, version);
            } catch (error) {
                if (version === cameraVersion) showToast('No se pudo leer el codigo. Toma otra foto con buena luz y sin reflejos.', true);
            } finally {
                URL.revokeObjectURL(url);
                photoButton.disabled = false;
                photoInput.value = '';
            }
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && cameraModal?.getAttribute('aria-hidden') === 'false') stopCamera();
    });
    window.addEventListener('pagehide', stopCamera);
})();
</script>
@endpush
