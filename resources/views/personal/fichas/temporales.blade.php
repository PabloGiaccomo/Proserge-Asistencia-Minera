@extends('layouts.app')

@section('title', 'Personal temporal y links - Proserge')

@section('content')
<div class="module-page ficha-workspace">
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
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <a href="{{ route('personal.fichas.review', $ficha->id) }}" class="btn {{ $ficha->estado === 'FICHA_ENVIADA' ? 'btn-primary' : 'btn-outline' }} btn-xs">
                                            {{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar' : 'Ver ficha' }}
                                        </a>
                                        @allowed('personal', 'eliminar')
                                            @if($link && !$ficha->submitted_at)
                                                <form method="POST" action="{{ route('personal.fichas.extend', $ficha->id) }}" onsubmit="return confirm('Se ampliara el link temporal por 1 dia mas.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs">Ampliar 1 dia</button>
                                                </form>
                                            @endif
                                            @if(!empty($row['can_regularize']))
                                                <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" onsubmit="return confirm('Se habilitara un link temporal para regularizar la ficha del trabajador.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs">Habilitar link temporal</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('personal.fichas.destroy', $ficha->id) }}" onsubmit="return confirm('Se eliminara por completo este trabajador temporal y su ficha.');">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-xs">Borrar completo</button>
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
});
</script>
@endpush
