@extends('layouts.mobile')

@section('title', 'Mi Marca - Proserge')

@section('content')
@php
    $personal = $user->personal;
    $horaActual = now()->format('H:i');
    $horaEntrada = $grupo['horario_entrada'] ?? '07:00';
    $horaLimite = $grupo['horario_limite_tardanza'] ?? '07:30';
    $isClosed = ($grupo['asistencia']['estado'] ?? '') === 'CERRADO';
    $miEstado = $miAsistencia['estado'] ?? null;
    $miHora = $miAsistencia['hora_marcado'] ?? null;
    $puedeMarcar = $canRegister && !$isClosed;
    $yaMarco = filled($miEstado);

    if ($yaMarco) {
        $esTardanza = $miEstado === 'TARDANZA' || ($miHora && $horaEntrada && $miHora > $horaLimite);
        $claseBtn = $esTardanza ? 'late' : 'marked';
        $icono = $esTardanza ? '&#9201;' : '&#10003;';
        $texto = $esTardanza ? 'Tardanza registrada' : 'Presente registrado';
    } elseif ($horaActual > $horaLimite && $puedeMarcar) {
        $claseBtn = 'late';
        $icono = '&#9201;';
        $texto = 'Marcar (llegaste tarde)';
    } elseif ($puedeMarcar) {
        $claseBtn = 'primary';
        $icono = '&#10003;';
        $texto = 'Marcar asistencia';
    } else {
        $claseBtn = 'disabled';
        $icono = '&#128274;';
        $texto = $isClosed ? 'Asistencia cerrada' : 'Sin permiso para registrar';
    }
@endphp

<div class="mob-page">
    <header class="mob-header">
        <div class="mob-header-top">
            <div class="mob-avatar">{{ mb_strtoupper(mb_substr((string) ($personal?->nombre_completo ?? $user->name ?? $user->email ?? '??'), 0, 2)) }}</div>
            <div class="mob-header-info">
                <div class="mob-label">Registrando como</div>
                <div class="mob-name">{{ $personal?->nombre_completo ?? $user->name ?? $user->email }}</div>
            </div>
        </div>
        <div class="mob-header-meta">
            <span>&#128205; {{ $grupo['mina_nombre'] ?: 'Sin mina' }}</span>
            <span>&#128197; {{ now()->locale('es')->translatedFormat('d \\d\\e F') }}</span>
        </div>
    </header>

    <div class="mob-body">
        {{-- Boton principal de marcacion --}}
        @if($puedeMarcar && !$yaMarco)
            <button type="button"
                class="mob-mark-btn {{ $claseBtn }} mob-animate"
                id="mobMarkBtn"
                data-url="{{ route('mi-asistencia.marcar', $grupo['grupo_id']) }}"
                data-detail-id="{{ $grupo['personal_detalle_id'] ?? '' }}">
                <span style="font-size:22px;">{!! $icono !!}</span>
                {{ $texto }}
            </button>
        @elseif($yaMarco)
            <div class="mob-card">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:28px;" class="mob-animate">{!! $icono !!}</span>
                    <div>
                        <div style="font-size:15px;font-weight:800;color:{{ $esTardanza ? '#956000' : '#087443' }};">{{ $texto }}</div>
                        <div style="font-size:12px;color:#7b8da6;">Marcado a las {{ substr($miHora, 0, 5) }}</div>
                    </div>
                </div>
            </div>
        @else
            <div class="mob-notice info">{{ $texto }}</div>
        @endif

        {{-- Resumen del dia --}}
        <div class="mob-card">
            <div class="mob-card-title">Mi registro del dia</div>

            @if($yaMarco)
                <div class="mob-record">
                    <div class="mob-record-dot {{ $esTardanza ? 'tardanza' : 'presente' }}"></div>
                    <div class="mob-record-info">
                        <div class="mob-rec-label">{{ $esTardanza ? 'Tardanza' : 'Presente' }}</div>
                        <div class="mob-rec-sub">Horario: {{ $horaEntrada }}</div>
                    </div>
                    <div class="mob-record-time">{{ substr($miHora, 0, 5) }}</div>
                </div>
            @else
                <div class="mob-empty">
                    <div style="font-size:28px;margin-bottom:6px;">&#9200;</div>
                    Sin registro hoy.<br>
                    @if($puedeMarcar)
                        Presiona el boton de arriba para marcar.
                    @endif
                </div>
            @endif

            @if($yaMarco && $horaEntrada)
                @php
                    $diffMin = 0;
                    if ($miHora && $horaEntrada) {
                        $marcado = \Illuminate\Support\Carbon::parse($miHora);
                        $entrada = \Illuminate\Support\Carbon::parse($horaEntrada);
                        $diffMin = max(0, $marcado->diffInMinutes($entrada));
                    }
                @endphp
                @if($diffMin > 0)
                    <div style="margin-top:10px;padding:10px;background:{{ $esTardanza ? '#fff4ce' : '#def8e8' }};border-radius:8px;font-size:12px;font-weight:600;color:{{ $esTardanza ? '#956000' : '#087443' }};">
                        {{ $esTardanza ? '&#9888; Llegaste ' . $diffMin . ' minutos tarde' : '&#10003; Puntual' }}
                    </div>
                @endif
            @endif
        </div>

        {{-- Info del grupo --}}
        <div class="mob-card">
            <div class="mob-card-title">Datos del grupo</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;">
                <div>
                    <div style="color:#7b8da6;font-weight:700;text-transform:uppercase;font-size:10px;">Horario</div>
                    <div style="font-weight:700;margin-top:2px;">{{ $horaEntrada }} - {{ $grupo['horario_salida'] ?? '--:--' }}</div>
                </div>
                <div>
                    <div style="color:#7b8da6;font-weight:700;text-transform:uppercase;font-size:10px;">Estado</div>
                    <div style="font-weight:700;margin-top:2px;color:{{ $isClosed ? '#aa2020' : '#087443' }};">{{ $isClosed ? 'Cerrada' : 'Abierta' }}</div>
                </div>
                <div>
                    <div style="color:#7b8da6;font-weight:700;text-transform:uppercase;font-size:10px;">Turno</div>
                    <div style="font-weight:700;margin-top:2px;">{{ $grupo['turno'] === 'DIA' ? 'Dia' : 'Noche' }}</div>
                </div>
                <div>
                    <div style="color:#7b8da6;font-weight:700;text-transform:uppercase;font-size:10px;">Integrantes</div>
                    <div style="font-weight:700;margin-top:2px;">{{ $grupo['total_integrantes'] ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="mob-footer">Proserge &copy; {{ date('Y') }}</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var btn = document.getElementById('mobMarkBtn');
    if (!btn) return;

    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    btn.addEventListener('click', async function () {
        if (btn.classList.contains('marked') || btn.classList.contains('disabled')) return;

        btn.disabled = true;
        btn.style.opacity = '.6';

        try {
            var now = new Date();
            var timeStr = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

            var response = await fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    grupo_trabajo_detalle_id: btn.dataset.detailId,
                    estado: 'PRESENTE',
                    hora_marcado: timeStr,
                    observaciones: '',
                }),
            });

            var data = await response.json().catch(function () { return {}; });

            if (response.ok) {
                window.location.reload();
            } else {
                btn.disabled = false;
                btn.style.opacity = '1';
                alert(data.message || 'Error al marcar asistencia.');
            }
        } catch (err) {
            btn.disabled = false;
            btn.style.opacity = '1';
            alert('Error de conexion. Intenta de nuevo.');
        }
    });
})();
</script>
@endpush
