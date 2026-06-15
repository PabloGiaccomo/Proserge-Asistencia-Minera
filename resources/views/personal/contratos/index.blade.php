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

    $estadoClass = function (string $estado): string {
        return match (strtoupper($estado)) {
            'ACTIVO' => 'contract-status-active',
            'PREPARACION' => 'contract-status-preparing',
            'ANULADO' => 'contract-status-annulled',
            default => 'contract-status-closed',
        };
    };

    $permissions = session('user.permissions', []);
    $canManageContracts = \App\Support\Rbac\PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']);
    $estadoPersonal = strtoupper((string) ($trabajador['estado'] ?? $personal->estado ?? ''));
    $preparingContract = $contratos
        ->filter(fn ($contrato): bool => strtoupper((string) $contrato->estado) === 'PREPARACION')
        ->sortByDesc(fn ($contrato) => (int) ($contrato->contrato_numero ?? 0))
        ->first();
    $hasPreparation = $preparingContract !== null;
    $isRenewalPreparation = strtoupper((string) ($preparingContract?->tipo_movimiento ?? '')) === 'RENOVACION';
    $activeContract = $contratos
        ->filter(fn ($contrato): bool => strtoupper((string) $contrato->estado) === 'ACTIVO')
        ->sortByDesc(fn ($contrato) => (int) ($contrato->contrato_numero ?? 0))
        ->first();
    $hasActiveContract = $activeContract !== null;
    $renewalDefaultStart = $activeContract?->fecha_fin
        ? \Illuminate\Support\Carbon::parse($activeContract->fecha_fin)->addDay()->toDateString()
        : now()->toDateString();
    $renewalsByOrigin = $contratos
        ->filter(fn ($contrato): bool => filled($contrato->origen_contrato_id))
        ->groupBy('origen_contrato_id');
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
.contract-status-preparing {
    background: #dbeafe;
    color: #1d4ed8;
}
.contract-status-closed {
    background: #fee2e2;
    color: #991b1b;
}
.contract-status-annulled {
    background: #e2e8f0;
    color: #334155;
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
.contract-upload-btn {
    border-color: #bfdbfe;
    color: #1d4ed8;
    background: #fff;
}
.contract-upload-btn.has-file {
    border-color: #bbf7d0;
    color: #166534;
    background: #f0fdf4;
}
.contract-upload-btn:hover {
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1e40af;
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
.contract-upload-modal {
    position: fixed;
    inset: 0;
    z-index: 82;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15, 23, 42, .38);
}
.contract-upload-modal.is-open {
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
.contract-upload-dialog {
    width: min(460px, 100%);
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
.contract-upload-file {
    margin-top: 12px;
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px;
}
.contract-delete-reason {
    margin-top: 12px;
    width: 100%;
    min-height: 92px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px;
    resize: vertical;
}
.contract-delete-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 16px;
}
.contract-flow-card {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    padding: 14px;
}
.contract-flow-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.contract-flow-title {
    margin: 0;
    color: #0f172a;
    font-size: 15px;
    font-weight: 800;
}
.contract-flow-text {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.4;
}
.contract-flow-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(160px, 1fr));
    gap: 10px;
    margin-top: 12px;
}
.contract-flow-form .form-group-wide {
    grid-column: 1 / -1;
}
.contract-flow-form label {
    display: grid;
    gap: 5px;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
}
.contract-flow-form input,
.contract-flow-form textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 9px 10px;
    color: #0f172a;
}
.contract-flow-form textarea {
    min-height: 72px;
    resize: vertical;
}
.contract-flow-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
}
@media (max-width: 720px) {
    .contract-flow-form {
        grid-template-columns: 1fr;
    }
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
            <div class="contract-summary-value">{{ $contratos->whereIn('estado', ['CERRADO', 'CESADO', 'NO_RENOVADO'])->count() }}</div>
        </div>
        <div class="contract-summary-item">
            <div class="contract-summary-label">Estado actual</div>
            <div class="contract-summary-value">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</div>
        </div>
    </div>

    @if($canManageContracts)
        <div class="contract-flow-card">
            @if($hasPreparation)
                <div class="contract-flow-head">
                    <div>
                        <h2 class="contract-flow-title">{{ $isRenewalPreparation ? 'Renovacion en preparacion' : 'Contrato en preparacion' }}</h2>
                        <p class="contract-flow-text">
                            @if($isRenewalPreparation)
                                Ya existe una renovacion preparada para este trabajador. Esta renovacion queda pendiente hasta subir su propio PDF firmado.
                            @else
                                Ya existe un contrato editable para este trabajador. Actualiza sus datos o sube el PDF firmado antes de crear otro movimiento.
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('personal.contrato-datos.edit', $personal->id) }}" class="btn btn-primary btn-sm">Editar contrato en preparacion</a>
                </div>
            @elseif($hasActiveContract)
                <div class="contract-flow-head">
                    <div>
                        <h2 class="contract-flow-title">Renovar contrato</h2>
                        <p class="contract-flow-text">
                            Crea un nuevo contrato en preparacion copiando los datos del contrato activo del historial. El estado laboral del trabajador no bloquea esta renovacion.
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('personal.contratos.renew', $personal->id) }}" class="contract-flow-form">
                    @csrf
                    <label>
                        Inicio del nuevo contrato
                        <input type="date" name="fecha_inicio" value="{{ old('fecha_inicio', $renewalDefaultStart) }}" required>
                    </label>
                    <label>
                        Fin del nuevo contrato
                        <input type="date" name="fecha_fin" value="{{ old('fecha_fin') }}">
                    </label>
                    <label class="form-group-wide">
                        Observacion de renovacion
                        <textarea name="observacion_renovacion" maxlength="5000" placeholder="Referencia interna de la renovacion">{{ old('observacion_renovacion') }}</textarea>
                    </label>
                    <div class="contract-flow-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Renovar contrato</button>
                    </div>
                </form>
            @elseif($estadoPersonal === 'CESADO')
                <div class="contract-flow-head">
                    <div>
                        <h2 class="contract-flow-title">Reingresar trabajador</h2>
                        <p class="contract-flow-text">Crea un nuevo contrato en preparacion copiando los datos del ultimo contrato disponible. El trabajador seguira pendiente hasta subir el contrato firmado.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('personal.contratos.reentry', $personal->id) }}" class="contract-flow-form">
                    @csrf
                    <label>
                        Inicio del nuevo contrato
                        <input type="date" name="fecha_inicio" value="{{ old('fecha_inicio', now()->toDateString()) }}" required>
                    </label>
                    <label>
                        Fin del nuevo contrato
                        <input type="date" name="fecha_fin" value="{{ old('fecha_fin') }}">
                    </label>
                    <label class="form-group-wide">
                        Observacion de reingreso
                        <textarea name="observacion_renovacion" maxlength="5000" placeholder="Motivo o referencia del reingreso">{{ old('observacion_renovacion') }}</textarea>
                    </label>
                    <div class="contract-flow-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Reingresar trabajador</button>
                    </div>
                </form>
            @else
                <div class="contract-flow-head">
                    <div>
                        <h2 class="contract-flow-title">Movimiento contractual no disponible</h2>
                        <p class="contract-flow-text">Para renovar debe existir un contrato activo en el historial. Para reingresar sin contrato activo, el trabajador debe estar cesado.</p>
                    </div>
                </div>
            @endif
        </div>
    @endif

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
                                    $estadoContrato = strtoupper((string) $contrato->estado);
                                    $canAnnul = $estadoContrato === 'PREPARACION';
                                    $canUploadSignedContract = $canManageContracts && $contratoService->canUploadSignedFileForContract($contrato);
                                    $contractLabel = 'Contrato #' . $contrato->contrato_numero . ' - ' . $inicio . ' al ' . $fin;
                                    $relatedRenewals = $renewalsByOrigin->get($contrato->id, collect());
                                @endphp
                                <tr>
                                    <td>
                                        <strong>Contrato #{{ $contrato->contrato_numero }}</strong>
                                        @if($contrato->origen_contrato_id)
                                            <div class="text-muted">Creado desde contrato anterior</div>
                                        @endif
                                        @if($contrato->tipo_movimiento)
                                            <div class="text-muted">{{ ucfirst(strtolower($contrato->tipo_movimiento)) }}</div>
                                        @endif
                                        @if(($contrato->origen_registro ?? '') === 'ANTIGUO')
                                            <div class="text-muted">Registro antiguo</div>
                                        @endif
                                        @if($relatedRenewals->isNotEmpty())
                                            <div class="text-muted">
                                                Renovacion generada:
                                                {{ $relatedRenewals->map(fn ($renovacion) => 'Contrato #' . $renovacion->contrato_numero)->join(', ') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('personal.contratos.show', [$personal->id, $contrato->id]) }}">{{ $inicio }} al {{ $fin }}</a>
                                    </td>
                                    <td>
                                        <span class="contract-status {{ $estadoClass($contrato->estado) }}">{{ ucfirst(strtolower($contrato->estado)) }}</span>
                                        @if($contrato->hasSignedFile())
                                            <div class="text-muted">Firmado: {{ optional($contrato->signed_at)->format('d/m/Y H:i') }}</div>
                                        @endif
                                        @if($contrato->archivo_pendiente_regularizacion)
                                            <div class="text-muted">Sin archivo firmado / por regularizar</div>
                                        @endif
                                        @if($estadoContrato === 'ANULADO')
                                            <div class="text-muted">{{ $contrato->motivo_anulacion ?: 'Anulado sin motivo visible' }}</div>
                                        @endif
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
                                            @if($canUploadSignedContract)
                                                <button
                                                    type="button"
                                                    class="btn btn-outline btn-xs contract-icon-btn contract-upload-btn js-contract-upload"
                                                    title="Subir PDF firmado"
                                                    aria-label="Subir PDF firmado del {{ $contractLabel }}"
                                                    data-upload-url="{{ route('personal.contratos.signed', [$personal->id, $contrato->id]) }}"
                                                    data-contract-label="{{ $contractLabel }}"
                                                    data-current-file="">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                        <path d="M17 8 12 3 7 8"/>
                                                        <path d="M12 3v12"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            @if($canAnnul)
                                                <button
                                                    type="button"
                                                    class="btn btn-outline btn-xs contract-icon-btn contract-delete-btn js-contract-delete"
                                                    title="Anular contrato"
                                                    aria-label="Anular {{ $contractLabel }}"
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
        <h2 class="contract-delete-title" id="contractDeleteTitle">Anular contrato en preparacion</h2>
        <p class="contract-delete-text">
            Se marcara <strong id="contractDeleteLabel">este contrato</strong> como anulado, sin eliminarlo de la base de datos.
        </p>
        <p class="contract-delete-text">Solo se permite anular contratos en preparacion. Los contratos cerrados o historicos quedan inamovibles.</p>
        <form method="POST" id="contractDeleteForm">
            @csrf
            <textarea name="motivo_anulacion" class="contract-delete-reason" maxlength="2000" required placeholder="Motivo de anulacion"></textarea>
            <div class="contract-delete-actions">
                <button type="button" class="btn btn-outline btn-sm" data-contract-delete-cancel>Cancelar</button>
                <button type="submit" class="btn btn-danger btn-sm">Anular contrato</button>
            </div>
        </form>
    </div>
</div>

<div class="contract-upload-modal" id="contractUploadModal" aria-hidden="true">
    <div class="contract-upload-dialog" role="dialog" aria-modal="true" aria-labelledby="contractUploadTitle">
        <h2 class="contract-delete-title" id="contractUploadTitle">Subir contrato firmado</h2>
        <p class="contract-delete-text">
            Se adjuntara el PDF firmado a <strong id="contractUploadLabel">este contrato</strong>.
        </p>
        <p class="contract-delete-text" id="contractUploadCurrentFile"></p>
        <form method="POST" id="contractUploadForm" enctype="multipart/form-data">
            @csrf
            <input type="file" name="contrato_pdf" class="contract-upload-file" accept="application/pdf" required>
            <div class="contract-delete-actions">
                <button type="button" class="btn btn-outline btn-sm" data-contract-upload-cancel>Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Subir PDF</button>
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
    const uploadModal = document.getElementById('contractUploadModal');
    const uploadForm = document.getElementById('contractUploadForm');
    const uploadLabel = document.getElementById('contractUploadLabel');
    const uploadCurrentFile = document.getElementById('contractUploadCurrentFile');
    const uploadCancelButton = uploadModal?.querySelector('[data-contract-upload-cancel]');

    const closeModal = function () {
        if (!modal || !form) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        form.removeAttribute('action');
    };

    const closeUploadModal = function () {
        if (!uploadModal || !uploadForm) return;
        uploadModal.classList.remove('is-open');
        uploadModal.setAttribute('aria-hidden', 'true');
        uploadForm.removeAttribute('action');
        uploadForm.reset();
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

    document.querySelectorAll('.js-contract-upload').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!uploadModal || !uploadForm || !uploadLabel || !uploadCurrentFile) return;
            uploadForm.setAttribute('action', button.dataset.uploadUrl || '');
            uploadLabel.textContent = button.dataset.contractLabel || 'este contrato';
            uploadCurrentFile.textContent = button.dataset.currentFile
                ? 'Archivo actual: ' + button.dataset.currentFile
                : 'No hay PDF firmado registrado para este contrato.';
            uploadModal.classList.add('is-open');
            uploadModal.setAttribute('aria-hidden', 'false');
        });
    });

    cancelButton?.addEventListener('click', closeModal);
    uploadCancelButton?.addEventListener('click', closeUploadModal);
    modal?.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    uploadModal?.addEventListener('click', function (event) {
        if (event.target === uploadModal) {
            closeUploadModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
            closeUploadModal();
        }
    });
});
</script>
@endpush
