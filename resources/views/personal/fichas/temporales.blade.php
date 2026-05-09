@extends('layouts.app')

@section('title', 'Personal temporal y links - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <style>
        .temporal-action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .temporal-action-buttons form {
            margin: 0;
        }

        .temporal-icon-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .temporal-icon-btn svg {
            width: 16px;
            height: 16px;
        }
    </style>
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal temporal y links</h1>
                <p class="page-subtitle">Trabajadores generados desde macro pendientes de completar, validar o activar.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.fichas.import') }}" class="btn btn-primary">Importar macro</a>
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if(session('regularization_link'))
        <div class="ficha-alert">
            Link temporal habilitado:
            <a href="{{ session('regularization_link') }}" target="_blank" rel="noopener">{{ session('regularization_link') }}</a>
        </div>
    @endif

    @if(count(session('warning_lines', [])) > 0)
        <div class="ficha-alert ficha-alert-warning">
            @foreach(session('warning_lines', []) as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">{{ count($rows) }} registros temporales</h2>
                <p class="ficha-card-subtitle">Los links antiguos sin token recuperable aparecen como no disponibles; los nuevos se pueden copiar desde aqui.</p>
            </div>
        </div>
        <div class="ficha-card-body">
            <div class="ficha-batch-table-wrap">
                <table class="ficha-batch-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Documento</th>
                            <th>Estado</th>
                            <th>Vence</th>
                            <th>Link</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $index => $row)
                            @php
                                $ficha = $row['ficha'];
                                $personal = $row['personal'];
                                $link = $row['link'];
                                $correo = $row['correo'] ?? null;
                                $emailSentAt = $row['email_sent_at'] ?? null;
                                $missingFields = $row['missing_fields'] ?? [];
                                $missingDocuments = $row['missing_documents'] ?? [];
                                $statusClass = match($ficha->estado) {
                                    'FICHA_ENVIADA' => 'ficha-status-sent',
                                    'APROBADO' => 'ficha-status-approved',
                                    'LINK_VENCIDO', 'RECHAZADO' => 'ficha-status-expired',
                                    default => 'ficha-status-pending',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}</strong>
                                    <div class="ficha-card-subtitle">{{ $personal?->puesto ?: 'Puesto pendiente' }}</div>
                                    @if($correo)
                                        <div class="ficha-card-subtitle">{{ $correo }}</div>
                                    @endif
                                    @if($emailSentAt)
                                        <div class="ficha-card-subtitle" style="color:#2563eb; margin-top:4px;">
                                            Correo enviado: {{ optional($emailSentAt)->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                    @if(count($missingFields) > 0 || count($missingDocuments) > 0)
                                        <div class="ficha-card-subtitle" style="color:#b45309; margin-top:4px;">
                                            Faltan datos o documentos importantes
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}</td>
                                <td><span class="ficha-status {{ $statusClass }}">{{ $row['estado_label'] }}</span></td>
                                <td>{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '-' }}</td>
                                <td>
                                    @if($row['url'])
                                        <div class="ficha-link-box">
                                            <input id="temporalLink{{ $index }}" class="ficha-input" type="text" value="{{ $row['url'] }}" readonly>
                                            <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="temporalLink{{ $index }}">Copiar</button>
                                        </div>
                                    @else
                                        <span class="ficha-card-subtitle">No recuperable. Genera un link nuevo reimportando la macro.</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="temporal-action-buttons">
                                        <a
                                            href="{{ route('personal.fichas.review', $ficha->id) }}"
                                            class="btn {{ $ficha->estado === 'FICHA_ENVIADA' ? 'btn-primary' : 'btn-outline' }} btn-xs temporal-icon-btn"
                                            title="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}"
                                            aria-label="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <path d="M14 2v6h6"/>
                                                <path d="M16 13H8"/>
                                                <path d="M16 17H8"/>
                                                <path d="M10 9H8"/>
                                            </svg>
                                        </a>
                                        @if($correo && $row['url'])
                                            <button type="button"
                                                class="btn btn-outline btn-xs js-send-email temporal-icon-btn"
                                                data-send-url="{{ route('personal.fichas.send-email', $ficha->id) }}"
                                                data-idle-title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                aria-label="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @else
                                            <button type="button" class="btn btn-outline btn-xs" disabled title="{{ $correo ? 'No se encontró un link recuperable' : 'No se encontró correo' }}" style="opacity:.55; cursor:not-allowed;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @endif
                                        @allowed('personal', 'eliminar')
                                            @if($link && !$ficha->submitted_at)
                                                <form method="POST" action="{{ route('personal.fichas.extend', $ficha->id) }}" onsubmit="return confirm('Se ampliara el link temporal por 1 dia mas.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Ampliar 1 día" aria-label="Ampliar 1 día">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="12" cy="12" r="10"/>
                                                            <path d="M12 6v6l4 2"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            @if(!empty($row['can_regularize']))
                                                <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" onsubmit="return confirm('Se habilitara un link temporal para regularizar la ficha del trabajador.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Habilitar link temporal" aria-label="Habilitar link temporal">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M10 13a5 5 0 0 1 7 0l1 1a5 5 0 0 1-7 7l-1-1"/>
                                                            <path d="M14 11a5 5 0 0 1-7 0l-1-1a5 5 0 0 1 7-7l1 1"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('personal.fichas.destroy', $ficha->id) }}" onsubmit="return confirm('Se eliminara por completo este trabajador temporal y su ficha.');">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-xs temporal-icon-btn" title="Borrar completo" aria-label="Borrar completo">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18"/>
                                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                                        <path d="M10 11v6"/>
                                                        <path d="M14 11v6"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endallowed
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="ficha-alert">No hay trabajadores temporales por ahora.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-copy-ficha-link').forEach(function (button) {
        button.addEventListener('click', async function () {
            const input = document.getElementById(button.dataset.target);
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Copiado';
                setTimeout(() => button.textContent = 'Copiar', 1800);
            } catch (error) {
                document.execCommand('copy');
            }
        });
    });

    document.querySelectorAll('.js-send-email').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) return;

            const originalTitle = button.getAttribute('title') || button.dataset.idleTitle || 'Enviar al correo';
            button.disabled = true;
            button.setAttribute('title', 'Enviando correo...');
            button.setAttribute('aria-label', 'Enviando correo...');

            fetch(button.dataset.sendUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
            }).then(function (response) {
                if (response.ok) {
                    button.dataset.idleTitle = 'Volver a enviar correo';
                    button.setAttribute('title', 'Volver a enviar correo');
                    button.setAttribute('aria-label', 'Volver a enviar correo');
                } else {
                    return response.json().then(function (data) {
                        alert(data.error || 'Error al enviar el correo');
                        button.setAttribute('title', originalTitle);
                        button.setAttribute('aria-label', originalTitle);
                    });
                }
            }).catch(function () {
                alert('Error de conexion al enviar el correo');
                button.setAttribute('title', originalTitle);
                button.setAttribute('aria-label', originalTitle);
            }).finally(function () {
                button.disabled = false;
            });
        });
    });
});
</script>
@endpush
