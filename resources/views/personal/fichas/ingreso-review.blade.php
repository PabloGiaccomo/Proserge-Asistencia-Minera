@extends('layouts.app')

@section('title', 'Revision de ingreso - Proserge')

@section('content')

@php
    $ingresoPermissions = session('user.permissions', []);
    $canEditIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'editar');
    $canUpdateIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'actualizar');
    $canDeleteIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'eliminar');
    $data = is_array($data ?? null) ? $data : [];
    $name = trim(collect([$data['apellido_paterno'] ?? '', $data['apellido_materno'] ?? '', $data['nombres'] ?? ''])->filter()->implode(' '));
    $statusClass = $ingresosService->statusClass((string) $ingreso->estado);
    $existing = $ingreso->personalExistente ?: $ingreso->personalCreado;
    $locked = in_array($ingreso->estado, ['ACEPTADA', 'CONTRATO_NO_FIRMADO'], true);
@endphp

<div class="module-page ingreso-review-page">
    <div class="ingreso-review-header">
        <div>
            <h1>{{ $editing ? 'Editar ficha de ingreso' : 'Revision de ficha de ingreso' }}</h1>
            <p class="page-subtitle">{{ $name !== '' ? $name : 'Ficha sin nombre completo' }} - {{ $ingreso->tipo_documento }} {{ $ingreso->numero_documento }}</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline" href="{{ route('personal.fichas.temporales') }}">Volver a Ingresos</a>
            @if(!$editing && !$locked && $canEditIngresos)
                <a class="btn btn-primary" href="{{ route('personal.ingresos.edit', $ingreso->id) }}">Editar ficha</a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Revisa los campos marcados.</strong>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="ingreso-summary-grid">
        <div class="ingreso-summary-item">
            <span>Estado ingreso</span>
            <strong><span class="ingresos-status {{ $statusClass }}">{{ $ingresosService->statusLabel((string) $ingreso->estado) }}</span></strong>
        </div>
        <div class="ingreso-summary-item">
            <span>Documento</span>
            <strong>{{ $ingreso->tipo_documento }} {{ $ingreso->numero_documento }}</strong>
        </div>
        <div class="ingreso-summary-item">
            <span>Enviado</span>
            <strong>{{ optional($ingreso->submitted_at)->format('d/m/Y H:i') ?: '-' }}</strong>
        </div>
        <div class="ingreso-summary-item">
            <span>Coincidencia Personal</span>
            <strong>{{ $existing ? 'Trabajador existente' : 'No existe en Personal' }}</strong>
        </div>
    </section>

    @if($editing)
        <form id="ingresoEditForm" method="POST" action="{{ route('personal.ingresos.update', $ingreso->id) }}" enctype="multipart/form-data" data-ingreso-submit>
            @csrf
            @method('PUT')
            @include('personal.fichas.partials.ingreso-form-fields', [
                'readonly' => false,
                'formMode' => 'internal',
                'archivos' => $ingreso->archivos,
                'firmaBase64' => $ingreso->firma_base64,
            ])
        </form>
    @else
        @include('personal.fichas.partials.ingreso-form-fields', [
            'readonly' => true,
            'formMode' => 'internal',
            'archivos' => $ingreso->archivos,
            'firmaBase64' => $ingreso->firma_base64,
        ])
    @endif

    <div class="ingreso-actions-footer">
        @if($editing && $canUpdateIngresos)
            <button class="btn btn-primary" type="submit" form="ingresoEditForm" data-loading-text="Guardando...">Guardar ficha (falta revision)</button>
        @endif

        @if(!$locked && $canUpdateIngresos)
            <button
                class="btn btn-primary"
                type="button"
                data-open-contract-ingreso
                data-action="{{ route('personal.ingresos.accept', $ingreso->id) }}"
                data-worker="{{ $name !== '' ? $name : 'Sin nombre' }}"
                data-document="{{ trim(($ingreso->tipo_documento ?: 'DNI') . ' ' . $ingreso->numero_documento) }}"
            >Agregar a la base de datos</button>

            <form method="POST" action="{{ route('personal.ingresos.contract-not-signed', $ingreso->id) }}">
                @csrf
                <button class="btn btn-outline" type="submit">Contrato no firmado</button>
            </form>
        @endif

        @if(!$locked && $canDeleteIngresos)
            <form method="POST" action="{{ route('personal.ingresos.destroy', $ingreso->id) }}" class="js-delete-ingreso-form">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger" type="button" data-open-delete-ingreso>Eliminar trabajador</button>
            </form>
        @endif

        @if($locked && $existing)
            <a class="btn btn-primary" href="{{ route('personal.edit', $existing->id) }}">Ver trabajador en Personal</a>
        @endif
    </div>
</div>

<dialog class="ingreso-delete-dialog" id="deleteIngresoDialog">
    <div class="ingreso-delete-body">
        <h2 style="margin:0 0 8px;color:#0f172a;font-size:20px;">Eliminar ficha erronea</h2>
        <p style="margin:0;color:#475569;line-height:1.55;">
            Esta accion borrara definitivamente la ficha de la bandeja de ingresos y sus archivos. Usala solo si la ficha fue enviada por error o no sirve para revision.
        </p>
        <div class="ficha-actions-bar" style="margin-top:18px;">
            <button type="button" class="btn btn-outline" data-close-delete-dialog>Cancelar</button>
            <button type="button" class="btn btn-danger" data-confirm-delete-ingreso>Eliminar definitivamente</button>
        </div>
    </div>
</dialog>

<dialog class="ingreso-contract-dialog" id="contractIngresoDialog">
    <form method="POST" id="contractIngresoForm" class="ingreso-contract-body" enctype="multipart/form-data">
        @csrf
        <h2 style="margin:0 0 8px;color:#0f172a;font-size:22px;">Registrar fechas de contrato</h2>
        <p style="margin:0;color:#475569;line-height:1.55;">
            Antes de agregar a Personal, indica el periodo del contrato actual o del proximo contrato. Si ya tienes el contrato firmado, puedes adjuntarlo ahora en PDF.
        </p>
        <div class="ingreso-contract-worker">
            <span id="contractIngresoWorker">Trabajador</span><br>
            <small id="contractIngresoDocument" style="color:#64748b;font-weight:700;"></small>
        </div>
        <div class="ingreso-contract-grid">
            <label>
                Fecha inicio contrato *
                <input class="form-control" type="date" name="fecha_inicio_contrato" id="contractIngresoStart" required>
            </label>
            <label>
                Fecha fin
                <input class="form-control" type="date" name="fecha_fin_contrato" id="contractIngresoEnd">
            </label>
        </div>
        <label class="ingreso-contract-file">
            Contrato firmado PDF (opcional)
            <input class="form-control" type="file" name="contrato_pdf" id="contractIngresoPdf" accept="application/pdf,.pdf">
            <small>Si adjuntas el PDF ahora, se asociara al contrato creado y se quitara la marca de pendiente.</small>
        </label>
        <div class="ingreso-contract-help">
            Si aun no hay fecha de fin, puedes dejarla vacia. Si no adjuntas el PDF, el trabajador quedara pendiente de contrato firmado.
        </div>
        <div class="ficha-actions-bar" style="margin-top:18px;">
            <button type="button" class="btn btn-outline" data-close-contract-dialog>Cancelar</button>
            <button type="submit" class="btn btn-primary">Agregar a Personal</button>
        </div>
    </form>
</dialog>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('deleteIngresoDialog');
    const contractDialog = document.getElementById('contractIngresoDialog');
    const contractForm = document.getElementById('contractIngresoForm');
    const contractWorker = document.getElementById('contractIngresoWorker');
    const contractDocument = document.getElementById('contractIngresoDocument');
    const contractStart = document.getElementById('contractIngresoStart');
    const contractEnd = document.getElementById('contractIngresoEnd');
    const contractPdf = document.getElementById('contractIngresoPdf');
    let pendingForm = null;
    const localDateValue = function () {
        const today = new Date();
        today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
        return today.toISOString().slice(0, 10);
    };

    document.addEventListener('click', function (event) {
        const contractButton = event.target.closest('[data-open-contract-ingreso]');
        if (contractButton) {
            if (contractForm) {
                contractForm.action = contractButton.dataset.action || '';
            }
            if (contractWorker) {
                contractWorker.textContent = contractButton.dataset.worker || 'Trabajador';
            }
            if (contractDocument) {
                contractDocument.textContent = contractButton.dataset.document || '';
            }
            if (contractStart) {
                contractStart.value = localDateValue();
            }
            if (contractEnd) {
                contractEnd.value = '';
            }
            if (contractPdf) {
                contractPdf.value = '';
            }
            if (contractDialog?.showModal) {
                contractDialog.showModal();
            }
            return;
        }

        const openButton = event.target.closest('[data-open-delete-ingreso]');
        if (openButton) {
            pendingForm = openButton.closest('form');
            if (dialog?.showModal) {
                dialog.showModal();
            }
            return;
        }

        if (event.target.closest('[data-close-delete-dialog]')) {
            dialog?.close();
            pendingForm = null;
        }

        if (event.target.closest('[data-close-contract-dialog]')) {
            contractDialog?.close();
        }

        if (event.target.closest('[data-confirm-delete-ingreso]') && pendingForm) {
            pendingForm.submit();
        }
    });

    contractForm?.addEventListener('submit', function (event) {
        if (contractStart?.value && contractEnd?.value && contractEnd.value < contractStart.value) {
            event.preventDefault();
            contractEnd.setCustomValidity('La fecha de fin no puede ser menor a la fecha de inicio.');
            contractEnd.reportValidity();
            return;
        }
        contractEnd?.setCustomValidity('');
    });

    dialog?.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
            pendingForm = null;
        }
    });

    contractDialog?.addEventListener('click', function (event) {
        if (event.target === contractDialog) {
            contractDialog.close();
        }
    });
});
</script>
@endpush
