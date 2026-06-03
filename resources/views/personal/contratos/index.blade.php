@extends('layouts.app')

@section('title', 'Contratos del trabajador - Proserge')

@section('content')
@php
    $formatDate = function ($date): string {
        if (!$date) {
            return 'Sin fecha';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return 'Sin fecha';
        }
    };

    $estadoClass = fn (string $estado): string => strtoupper($estado) === 'ACTIVO' ? 'contract-status-active' : 'contract-status-closed';
@endphp

<style>
.contract-page {
    display: grid;
    gap: 16px;
}
.contract-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
}
.contract-summary-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}
.contract-summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.contract-summary-value {
    color: #0f172a;
    font-size: 18px;
    font-weight: 800;
    margin-top: 4px;
}
.contract-table-wrap {
    overflow-x: auto;
}
.contract-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 860px;
}
.contract-table th,
.contract-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 12px;
    text-align: left;
    vertical-align: top;
}
.contract-table th {
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.contract-status {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 800;
}
.contract-status-active {
    background: #dcfce7;
    color: #166534;
}
.contract-status-closed {
    background: #fee2e2;
    color: #991b1b;
}
.contract-reason {
    max-width: 320px;
    color: #475569;
    line-height: 1.4;
}
.contract-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    white-space: nowrap;
}
.contract-icon-btn {
    width: 32px;
    height: 32px;
    min-width: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.contract-icon-btn svg {
    width: 15px;
    height: 15px;
}
.contract-delete-btn {
    border-color: #fecaca;
    color: #b91c1c;
    background: #fff;
}
.contract-delete-btn:hover {
    border-color: #fca5a5;
    background: #fee2e2;
    color: #991b1b;
}
.contract-delete-modal {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15, 23, 42, .38);
}
.contract-delete-modal.is-open {
    display: flex;
}
.contract-delete-dialog {
    width: min(420px, 100%);
    border-radius: 10px;
    background: #fff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
    padding: 18px;
}
.contract-delete-title {
    margin: 0;
    color: #0f172a;
    font-size: 17px;
    font-weight: 800;
}
.contract-delete-text {
    margin: 8px 0 0;
    color: #475569;
    line-height: 1.45;
    font-size: 14px;
}
.contract-delete-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 16px;
}
</style>

<div class="module-page contract-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Contratos laborales</h1>
                <p class="page-subtitle">{{ $personal->nombre_completo }} - {{ $personal->tipo_documento ?: 'DNI' }} {{ $personal->numero_documento ?: $personal->dni }}</p>
            </div>
            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.documentos.index', $personal->id) }}" class="btn btn-outline btn-sm">Documentos</a>
                <a href="{{ route('personal.edit', $personal->id) }}" class="btn btn-outline btn-sm">Editar trabajador</a>
                <a href="{{ route('personal.index') }}" class="btn btn-primary btn-sm">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="contract-summary-grid">
        <div class="contract-summary-item">
            <div class="contract-summary-label">Contratos registrados</div>
            <div class="contract-summary-value">{{ $contratos->count() }}</div>
        </div>
        <div class="contract-summary-item">
            <div class="contract-summary-label">Contratos cerrados</div>
            <div class="contract-summary-value">{{ $contratos->where('estado', 'CERRADO')->count() }}</div>
        </div>
        <div class="contract-summary-item">
            <div class="contract-summary-label">Estado actual</div>
            <div class="contract-summary-value">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Historial de contratos</span>
        </div>
        <div class="card-body">
            @if($contratos->isEmpty())
                <p class="text-muted" style="margin:0;">Aun no hay contratos guardados para este trabajador. El primer cierre o activacion creara el historial.</p>
            @else
                <div class="contract-table-wrap">
                    <table class="contract-table">
                        <thead>
                            <tr>
                                <th>Contrato</th>
                                <th>Periodo</th>
                                <th>Estado</th>
                                <th>Motivo de cese</th>
                                <th>Activado por</th>
                                <th>Cerrado por</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contratos as $contrato)
                                @php
                                    $inicio = $formatDate($contrato->fecha_inicio);
                                    $fin = $contrato->fecha_fin ? $formatDate($contrato->fecha_fin) : 'Vigente';
                                    $activadoPor = $contrato->activadoPor?->personal?->nombre_completo ?: $contrato->activadoPor?->email ?: 'No registrado';
                                    $cerradoPor = $contrato->cerradoPor?->personal?->nombre_completo ?: $contrato->cerradoPor?->email ?: 'No registrado';
                                    $isHistorical = strtoupper((string) $contrato->estado) !== 'ACTIVO';
                                    $contractLabel = 'Contrato #' . $contrato->contrato_numero . ' - ' . $inicio . ' al ' . $fin;
                                @endphp
                                <tr>
                                    <td>
                                        <strong>Contrato #{{ $contrato->contrato_numero }}</strong>
                                        @if($contrato->origen_contrato_id)
                                            <div class="text-muted">Activado desde contrato anterior</div>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('personal.contratos.show', [$personal->id, $contrato->id]) }}">{{ $inicio }} al {{ $fin }}</a>
                                    </td>
                                    <td>
                                        <span class="contract-status {{ $estadoClass($contrato->estado) }}">{{ ucfirst(strtolower($contrato->estado)) }}</span>
                                    </td>
                                    <td><div class="contract-reason">{{ $contrato->motivo_cese ?: '-' }}</div></td>
                                    <td>{{ $activadoPor }}</td>
                                    <td>{{ $cerradoPor }}</td>
                                    <td style="text-align:right;">
                                        <div class="contract-actions">
                                            <a href="{{ route('personal.contratos.show', [$personal->id, $contrato->id]) }}" class="btn btn-outline btn-xs contract-icon-btn" title="Ver detalle" aria-label="Ver detalle del {{ $contractLabel }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                    <path d="M14 2v6h6"/>
                                                    <circle cx="11" cy="14" r="2"/>
                                                    <path d="m13 16 2 2"/>
                                                </svg>
                                            </a>
                                            @if($isHistorical)
                                                <button
                                                    type="button"
                                                    class="btn btn-outline btn-xs contract-icon-btn contract-delete-btn js-contract-delete"
                                                    title="Eliminar contrato"
                                                    aria-label="Eliminar {{ $contractLabel }}"
                                                    data-delete-url="{{ route('personal.contratos.destroy', [$personal->id, $contrato->id]) }}"
                                                    data-contract-label="{{ $contractLabel }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M3 6h18"/>
                                                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                        <path d="M10 11v6"/>
                                                        <path d="M14 11v6"/>
                                                    </svg>
                                                </button>
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
    </div>
</div>

<div class="contract-delete-modal" id="contractDeleteModal" aria-hidden="true">
    <div class="contract-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="contractDeleteTitle">
        <h2 class="contract-delete-title" id="contractDeleteTitle">Eliminar contrato anterior</h2>
        <p class="contract-delete-text">
            Se eliminara permanentemente <strong id="contractDeleteLabel">este contrato</strong> de la base de datos.
        </p>
        <p class="contract-delete-text">Esta accion no cambia el contrato vigente del trabajador.</p>
        <form method="POST" id="contractDeleteForm">
            @csrf
            <div class="contract-delete-actions">
                <button type="button" class="btn btn-outline btn-sm" data-contract-delete-cancel>Cancelar</button>
                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('contractDeleteModal');
    const form = document.getElementById('contractDeleteForm');
    const label = document.getElementById('contractDeleteLabel');
    const cancelButton = modal?.querySelector('[data-contract-delete-cancel]');

    const closeModal = function () {
        if (!modal || !form) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        form.removeAttribute('action');
    };

    document.querySelectorAll('.js-contract-delete').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!modal || !form || !label) return;
            form.setAttribute('action', button.dataset.deleteUrl || '');
            label.textContent = button.dataset.contractLabel || 'este contrato';
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    cancelButton?.addEventListener('click', closeModal);
    modal?.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
});
</script>
@endpush
