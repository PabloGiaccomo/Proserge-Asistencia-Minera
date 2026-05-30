@extends('layouts.app')

@section('title', 'Ver Personal - Proserge')

@section('content')
@php
    $canCeasePersonal = \App\Support\Rbac\PermissionMatrix::allowsAny(session('user.permissions', []), 'personal', ['editar', 'actualizar', 'administrar']);
    $estadoActual = strtoupper((string) ($trabajador['estado'] ?? ''));
@endphp
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">{{ $trabajador['nombre'] ?? 'Detalle del trabajador' }}</h1>
                <p class="page-subtitle">{{ $trabajador['puesto'] ?? '-' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
                <a href="{{ route('personal.edit', $id) }}" class="btn btn-primary">Editar</a>
                @if($estadoActual === 'CESADO')
                    <button type="button" class="btn btn-outline" onclick="showCeaseReason()">Ver motivo de cese</button>
                @elseif($canCeasePersonal)
                    <form method="POST" action="{{ route('personal.cease', $id) }}" data-worker-name="{{ $trabajador['nombre'] ?? 'este trabajador' }}" onsubmit="return requestCeaseReason(this);">
                        @csrf
                        <button type="submit" class="btn btn-outline">Cesar</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">Informacion principal</h2>
                <p class="ficha-card-subtitle">{{ ($trabajador['tipo_documento'] ?? 'DNI') . ' ' . ($trabajador['numero_documento'] ?? $trabajador['dni'] ?? '-') }}</p>
            </div>
            @if($estadoActual === 'CESADO')
                <button type="button" class="ficha-status" style="border:0;cursor:pointer;" onclick="showCeaseReason()">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</button>
            @else
                <span class="ficha-status">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</span>
            @endif
        </div>
        <div class="ficha-card-body">
            <div class="ficha-fields" style="padding:0;">
                <div class="ficha-field"><span class="ficha-label">Telefono</span><div class="ficha-input">{{ $trabajador['telefono'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Correo</span><div class="ficha-input">{{ $trabajador['correo'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Contrato</span><div class="ficha-input">{{ $trabajador['tipo_contrato'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Fecha ingreso</span><div class="ficha-input">{{ $trabajador['fecha_ingreso'] ?? '-' }}</div></div>
                <div class="ficha-field ficha-field-wide"><span class="ficha-label">Minas / sedes</span><div class="ficha-input">{{ implode(', ', $trabajador['minas'] ?? []) ?: '-' }}</div></div>
            </div>
        </div>
    </div>

    @if($ficha)
        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Ficha del colaborador</h2>
                    <p class="ficha-card-subtitle">Estado: {{ \App\Modules\Personal\Support\PersonalFichaCatalog::stateLabel($ficha->estado) }}</p>
                </div>
                <div class="page-actions">
                    <a href="{{ route('personal.fichas.review', $ficha->id) }}" class="btn btn-outline">Revisar</a>
                    <a href="{{ route('personal.fichas.pdf', $ficha->id) }}" class="btn btn-primary">PDF</a>
                </div>
            </div>
            <div class="ficha-card-body">
                <div class="ficha-fields" style="padding:0;">
                    <div class="ficha-field"><span class="ficha-label">Creada</span><div class="ficha-input">{{ optional($ficha->created_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                    <div class="ficha-field"><span class="ficha-label">Enviada</span><div class="ficha-input">{{ optional($ficha->submitted_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                    <div class="ficha-field"><span class="ficha-label">Aprobada</span><div class="ficha-input">{{ optional($ficha->approved_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                </div>
            </div>
        </div>
    @endif

    <div id="ceaseReasonModal" class="modal" style="display:none;" onclick="if (event.target === this) closeCeaseReasonModal()">
        <div class="modal-backdrop" onclick="closeCeaseReasonModal()"></div>
        <div class="modal-content" style="width:min(440px, calc(100vw - 32px));border-radius:14px;padding:18px;">
            <div class="modal-header" style="padding-bottom:12px;margin-bottom:12px;">
                <div>
                    <h2 class="modal-title">Cesar trabajador</h2>
                    <p class="modal-subtitle" id="ceaseReasonSubtitle">Indica el motivo de cese.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeCeaseReasonModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <label class="ficha-label" for="ceaseReasonTextarea">Motivo de cese <span class="ficha-required">*</span></label>
                <textarea id="ceaseReasonTextarea" class="ficha-input" maxlength="2000" placeholder="Escribe el motivo de cese" style="width:100%;min-height:108px;resize:vertical;box-sizing:border-box;"></textarea>
                <div id="ceaseReasonError" style="display:none;margin-top:8px;color:#dc2626;font-size:12px;font-weight:600;">El motivo de cese es obligatorio.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCeaseReasonModal()">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="submitCeaseReason()">Cesar</button>
            </div>
        </div>
    </div>

    <div id="ceaseReasonViewModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('ceaseReasonViewModal')">
        <div class="modal-backdrop" onclick="closeModal('ceaseReasonViewModal')"></div>
        <div class="modal-content" style="width:min(440px, calc(100vw - 32px));border-radius:14px;padding:18px;">
            <div class="modal-header" style="padding-bottom:12px;margin-bottom:12px;">
                <div>
                    <h2 class="modal-title">Motivo de cese</h2>
                    <p class="modal-subtitle" id="ceaseReasonViewSubtitle">{{ !empty($trabajador['cese_automatico']) ? 'Cese automatico por contrato.' : 'Detalle registrado.' }}</p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('ceaseReasonViewModal')" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 12px;font-size:13px;color:#64748b;">{{ $trabajador['nombre'] ?? 'Trabajador' }}</p>
                <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 10px;padding:10px 12px;margin-bottom:12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;font-size:12px;">
                    <span style="color:#64748b;font-weight:700;">Cesado por</span>
                    <span style="color:#0f172a;">{{ $trabajador['cesado_por_nombre'] ?? (!empty($trabajador['cese_automatico']) ? 'Sistema - termino de contrato' : 'No registrado') }}</span>
                </div>
                <p id="ceaseReasonViewText" style="margin:0;padding:12px;min-height:88px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;color:#0f172a;font-size:14px;line-height:1.5;white-space:pre-wrap;">{{ $trabajador['motivo_cese'] ?? 'Motivo no registrado' }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('ceaseReasonViewModal')">Cerrar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let pendingCeaseForm = null;

function showCeaseReason() {
    openModal('ceaseReasonViewModal');
}

function requestCeaseReason(form) {
    const existingInput = form.querySelector('input[name="motivo_cese"]');
    if (existingInput && existingInput.value.trim() !== '') {
        return true;
    }

    pendingCeaseForm = form;
    const textarea = document.getElementById('ceaseReasonTextarea');
    const error = document.getElementById('ceaseReasonError');
    const subtitle = document.getElementById('ceaseReasonSubtitle');
    const workerName = form.dataset.workerName || @json($trabajador['nombre'] ?? 'este trabajador');

    if (subtitle) {
        subtitle.textContent = 'Indica el motivo por el que se cesara a ' + workerName + '.';
    }
    if (textarea) {
        textarea.value = '';
    }
    if (error) {
        error.style.display = 'none';
    }

    openModal('ceaseReasonModal');
    window.setTimeout(function () {
        textarea?.focus();
    }, 50);

    return false;
}

function closeCeaseReasonModal() {
    pendingCeaseForm = null;
    closeModal('ceaseReasonModal');
}

function submitCeaseReason() {
    const textarea = document.getElementById('ceaseReasonTextarea');
    const error = document.getElementById('ceaseReasonError');
    const trimmedReason = String(textarea?.value || '').trim();

    if (trimmedReason === '') {
        if (error) {
            error.style.display = 'block';
        }
        textarea?.focus();
        return;
    }

    if (!pendingCeaseForm) {
        closeCeaseReasonModal();
        return;
    }

    const existingInput = pendingCeaseForm.querySelector('input[name="motivo_cese"]');
    const input = existingInput || document.createElement('input');
    input.type = 'hidden';
    input.name = 'motivo_cese';
    input.value = trimmedReason;
    if (!existingInput) {
        pendingCeaseForm.appendChild(input);
    }

    const form = pendingCeaseForm;
    pendingCeaseForm = null;
    closeModal('ceaseReasonModal');
    form.requestSubmit();
}
</script>
@endpush
