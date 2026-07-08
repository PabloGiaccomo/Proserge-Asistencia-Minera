@extends('layouts.app')

@section('title', 'Herramientas y consumibles por parada')

@php
    $estadoClass = static function (string $estado): string {
        return match (strtoupper($estado)) {
            'ENVIADO' => 'sent',
            'BORRADOR' => 'draft',
            default => 'pending',
        };
    };
    $deadlineAlerts = $deadlineAlerts ?? [];
@endphp

@section('content')
<div class="tools-page">
    @if(!empty($deadlineAlerts))
        <div class="tools-deadline-toast" id="toolsDeadlineToast" role="status" aria-live="polite">
            <div class="tools-deadline-toast-head">
                <div>
                    <strong>Listas por vencer</strong>
                    <span>Vencen dentro de los proximos 7 dias</span>
                </div>
                <button type="button" onclick="document.getElementById('toolsDeadlineToast')?.remove()" aria-label="Cerrar aviso">&times;</button>
            </div>
            <div class="tools-deadline-toast-body">
                @foreach($deadlineAlerts as $alert)
                    @php $days = (int) ($alert['dias_para_limite'] ?? 0); @endphp
                    <a href="{{ route('herramientas-parada.show', $alert['rq_mina_id']) }}" class="tools-deadline-toast-item">
                        <span>{{ $alert['lugar'] ?? '-' }}</span>
                        <strong>
                            @if($days === 0)
                                Vence hoy
                            @elseif($days === 1)
                                Vence en 1 dia
                            @else
                                Vence en {{ $days }} dias
                            @endif
                        </strong>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="page-header-custom">
        <div>
            <h1 class="page-title">Herramientas y consumibles por parada</h1>
            <p class="page-subtitle">Listas semanales de equipos, herramientas, utillaje y consumibles por grupo</p>
        </div>
        @allowed('herramientas', 'actualizar')
            <button type="button" class="btn-filter tools-catalog-button" onclick="openToolsCatalogImport()">
                Subir catalogo
            </button>
        @endallowed
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="filters-bar">
        <form method="GET" action="{{ route('herramientas-parada.index') }}" class="tools-filters">
            <div class="filter-group">
                <label class="filter-label">Buscar</label>
                <input type="text" name="q" class="filter-input" value="{{ $filters['q'] ?? '' }}" placeholder="Lugar, mina o area">
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado lista</label>
                <select name="estado_lista" class="filter-select">
                    <option value="">Todos</option>
                    @foreach(['PENDIENTE' => 'Pendiente', 'BORRADOR' => 'Borrador', 'ENVIADO' => 'Enviado'] as $value => $label)
                        <option value="{{ $value }}" {{ strtoupper((string) ($filters['estado_lista'] ?? '')) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="tools-filter-actions">
                <button type="submit" class="btn-filter">Filtrar</button>
                <a href="{{ route('herramientas-parada.index') }}" class="btn-filter-outline">Limpiar</a>
            </div>
        </form>
    </div>

    <div class="tools-card">
        <div class="tools-card-header">
            <div>
                <h2>Paradas</h2>
                <span>{{ count($items) }} registros</span>
            </div>
        </div>

        @if(empty($items))
            <div class="empty-state">
                <h3>Sin paradas para mostrar</h3>
                <p>No hay resultados con los filtros actuales.</p>
            </div>
        @else
            <div class="tools-table-wrap">
                <table class="tools-table">
                    <thead>
                        <tr>
                            <th>Parada</th>
                            <th>Semana</th>
                            <th>Fechas</th>
                            <th>Limite envio</th>
                            <th>Grupos</th>
                            <th>Estado lista</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            @php
                                $dias = (int) ($item['dias_para_limite'] ?? 0);
                                $deadlineClass = $dias < 0 ? 'expired' : ($dias <= 2 ? 'urgent' : 'ok');
                                $paradaIniciada = (bool) ($item['parada_iniciada'] ?? false);
                                $paradaFinalizada = (bool) ($item['parada_finalizada'] ?? false);
                                $puedeCompletarRequerimiento = (bool) ($item['puede_completar_requerimiento'] ?? false);
                            @endphp
                            <tr>
                                <td>
                                    <div class="tools-main-cell">
                                        <strong>{{ $item['lugar'] ?? '-' }}</strong>
                                        <span>{{ $item['area'] ?? '-' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="week-pill">Sem. {{ $item['semana'] ?? '-' }}</span>
                                    <div class="week-year">{{ $item['anio_semana'] ?? '' }}</div>
                                </td>
                                <td>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</td>
                                <td>
                                    <div class="deadline {{ $deadlineClass }}">
                                        <strong>{{ $item['fecha_limite_envio'] ?? '-' }}</strong>
                                        <span>
                                            @if($dias < 0)
                                                Vencido hace {{ abs($dias) }} dia(s)
                                            @elseif($dias === 0)
                                                Vence hoy
                                            @else
                                                Faltan {{ $dias }} dia(s)
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td>{{ (int) ($item['grupos_count'] ?? 0) }}</td>
                                <td><span class="tools-status {{ $estadoClass($item['estado_lista'] ?? 'PENDIENTE') }}">{{ ucfirst(strtolower($item['estado_lista'] ?? 'Pendiente')) }}</span></td>
                                <td>
                                    <div class="tools-row-actions">
                                        @if($puedeCompletarRequerimiento)
                                            <a href="{{ route('herramientas-parada.show', $item['rq_mina_id']) }}" class="btn-row btn-row-outline tools-action-link">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M12 5v14"></path>
                                                    <path d="M5 12h14"></path>
                                                </svg>
                                                <span>Completar requerimiento</span>
                                            </a>
                                        @else
                                            <span class="btn-row btn-row-outline tools-action-link is-disabled" title="{{ $dias < 0 ? 'El limite de envio vencio; el requerimiento quedo cerrado.' : 'El requerimiento ya fue enviado.' }}" aria-disabled="true">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M7 11V8a5 5 0 0 1 10 0v3"></path>
                                                    <path d="M6 11h12v9H6z"></path>
                                                </svg>
                                                <span>{{ $dias < 0 ? 'Limite vencido' : 'Requerimiento cerrado' }}</span>
                                            </span>
                                        @endif
                                        @if($paradaIniciada)
                                            <a href="{{ route('herramientas-parada.confirmar-pedido', [$item['rq_mina_id'], 'modo' => 'entrega']) }}" class="btn-row tools-action-link">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M4 7h11v10H4z"></path>
                                                    <path d="M15 10h3l2 3v4h-5z"></path>
                                                    <path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
                                                    <path d="M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
                                                </svg>
                                                <span>Ver entregas</span>
                                            </a>
                                        @else
                                            <span class="btn-row btn-row-outline tools-action-link is-disabled" title="Disponible cuando inicie la parada" aria-disabled="true">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M4 7h11v10H4z"></path>
                                                    <path d="M15 10h3l2 3v4h-5z"></path>
                                                    <path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
                                                    <path d="M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
                                                </svg>
                                                <span>Ver entregas</span>
                                            </span>
                                        @endif
                                        @if($paradaFinalizada)
                                            <a href="{{ route('herramientas-parada.confirmar-pedido', [$item['rq_mina_id'], 'modo' => 'recepcion']) }}" class="btn-row tools-action-link tools-action-final">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M20 7L10 17l-5-5"></path>
                                                </svg>
                                                <span>Registrar recepcion</span>
                                            </a>
                                        @else
                                            <span class="btn-row btn-row-outline tools-action-link is-disabled" title="Disponible al finalizar la parada" aria-disabled="true">
                                                <svg class="tools-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M20 7L10 17l-5-5"></path>
                                                </svg>
                                                <span>Registrar recepcion</span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @allowed('herramientas', 'actualizar')
        <dialog class="tools-import-dialog" id="toolsCatalogImportDialog">
            <form method="POST" action="{{ route('herramientas-parada.catalogo.importar') }}" enctype="multipart/form-data">
                @csrf
                <div class="tools-dialog-head">
                    <div>
                        <span>Catalogo global</span>
                        <h2>Subir herramientas y consumibles</h2>
                        <p>Registra solo las descripciones del formato para usarlas como autocompletado. No modifica pedidos existentes.</p>
                    </div>
                    <button type="button" class="tools-dialog-close" onclick="closeToolsCatalogImport()" aria-label="Cerrar">X</button>
                </div>
                <div class="tools-dialog-body">
                    <label class="form-label" for="toolsCatalogImportFile">Archivo Excel</label>
                    <input id="toolsCatalogImportFile" type="file" name="archivo" class="form-control" accept=".xlsx,.xls,.xlsm" required>
                    <p class="tools-catalog-help">
                        Tambien se aprenderan observaciones asociadas a cada descripcion cuando el archivo las incluya.
                    </p>
                </div>
                <div class="tools-dialog-actions">
                    <button type="button" class="btn-row btn-row-outline" onclick="closeToolsCatalogImport()">Cancelar</button>
                    <button type="submit" class="btn-row">Actualizar catalogo</button>
                </div>
            </form>
        </dialog>
    @endallowed
</div>

@push('scripts')
<script>
function openToolsCatalogImport() {
    const dialog = document.getElementById('toolsCatalogImportDialog');
    const file = document.getElementById('toolsCatalogImportFile');

    if (file) {
        file.value = '';
    }

    dialog?.showModal();
}

function closeToolsCatalogImport() {
    const dialog = document.getElementById('toolsCatalogImportDialog');
    if (dialog?.open) {
        dialog.close();
    }
}

document.getElementById('toolsCatalogImportDialog')?.addEventListener('click', function (event) {
    if (event.target === this) {
        closeToolsCatalogImport();
    }
});
</script>
@endpush
@endsection
