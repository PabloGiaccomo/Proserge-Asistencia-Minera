@extends('layouts.app')

@section('title', 'Ingresos - Proserge')

@section('content')
@php
    $ingresoPermissions = session('user.permissions', []);
    $canEditIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'editar');
    $canUpdateIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'actualizar');
    $canDeleteIngresos = \App\Support\Rbac\PermissionMatrix::allowsDirect($ingresoPermissions, 'personal', 'eliminar');
@endphp

<div class="module-page ingresos-page">
    <div class="ingresos-header">
        <div>
            <h1>Ingresos</h1>
            <p class="page-subtitle">Revisa las fichas que llegan desde el link publico.</p>
        </div>
        <a class="btn btn-outline" href="{{ route('personal.index') }}">Volver a Personal</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <section class="ingresos-card">
        <div class="ingresos-card-header">
            <div>
                <h2 class="ingresos-card-title">Link unico de ficha</h2>
                <p class="page-subtitle">Este enlace es permanente. La clave cambia por dia y debe entregarla RRHH.</p>
            </div>
        </div>
        <div class="ingresos-body">
            <div class="ingresos-link-grid">
                <div>
                    <label class="form-label">Enlace para enviar al trabajador</label>
                    <div class="ingresos-copy-box">
                        <span id="publicIngresoUrl">{{ $publicUrl }}</span>
                        <button type="button" class="btn btn-outline btn-sm" data-copy-target="publicIngresoUrl">Copiar</button>
                    </div>
                </div>
                <div>
                    <label class="form-label">Clave diaria {{ $dailyKey['fecha'] ?? '' }}</label>
                    <div class="ingresos-copy-box">
                        <span class="ingresos-key" id="dailyIngresoKey">{{ $dailyKey['clave'] ?? '' }}</span>
                        <button type="button" class="btn btn-outline btn-sm" data-copy-target="dailyIngresoKey">Copiar</button>
                    </div>
                    <p class="page-subtitle">Actualizada: {{ $dailyKey['actualizada'] ?? 'hoy' }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="ingresos-card">
        <div class="ingresos-card-header">
            <div>
                <h2 class="ingresos-card-title">Bandeja de fichas recibidas</h2>
                <p class="page-subtitle">{{ $rowsTotal }} ficha(s) en la bandeja.</p>
            </div>
        </div>
        <div class="ingresos-body">
            <form method="GET" action="{{ route('personal.fichas.temporales') }}" class="ingresos-filters">
                <div class="form-group">
                    <label class="form-label" for="q">Buscar</label>
                    <input id="q" class="form-control" name="q" value="{{ $search }}" placeholder="Nombre, DNI, puesto o correo">
                </div>
                <div class="form-group">
                    <label class="form-label" for="estado">Estado</label>
                    <select id="estado" class="form-control" name="estado">
                        @foreach($statusLabels as $state => $label)
                            <option value="{{ $state }}" @selected($estadoFilter === $state)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <button class="btn btn-primary" type="submit">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="ingresos-table-wrap">
            <table class="ingresos-table">
                <thead>
                    <tr>
                        <th>Trabajador</th>
                        <th>Documento</th>
                        <th>Puesto</th>
                        <th>Correo / telefono</th>
                        <th>Estado</th>
                        <th>Fecha envio</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $ingreso)
                        @php
                            $data = is_array($ingreso->datos_json) ? $ingreso->datos_json : [];
                            $name = trim(collect([$data['apellido_paterno'] ?? '', $data['apellido_materno'] ?? '', $data['nombres'] ?? ''])->filter()->implode(' '));
                            $statusClass = $ingresosService->statusClass((string) $ingreso->estado);
                            $existing = $ingreso->personalExistente ?: $ingreso->personalCreado;
                            $locked = in_array($ingreso->estado, ['ACEPTADA', 'CONTRATO_NO_FIRMADO'], true);
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $name !== '' ? $name : 'Sin nombre' }}</strong>
                                @if($existing)
                                    <span class="ingresos-existing">Trabajador existente</span>
                                @endif
                            </td>
                            <td>{{ $ingreso->tipo_documento }} {{ $ingreso->numero_documento }}</td>
                            <td>{{ $data['puesto'] ?? '-' }}</td>
                            <td>
                                {{ $data['correo'] ?? '-' }}<br>
                                <span class="page-subtitle">{{ $data['telefono'] ?? '-' }}</span>
                            </td>
                            <td><span class="ingresos-status {{ $statusClass }}">{{ $ingresosService->statusLabel((string) $ingreso->estado) }}</span></td>
                            <td>{{ optional($ingreso->submitted_at)->format('d/m/Y H:i') ?: '-' }}</td>
                            <td>
                                <div class="ingresos-actions">
                                    <a class="btn btn-outline btn-sm" href="{{ route('personal.ingresos.show', $ingreso->id) }}">Ver ficha</a>
                                    @if(!$locked)
                                        @if($canEditIngresos)
                                            <a class="btn btn-outline btn-sm" href="{{ route('personal.ingresos.edit', $ingreso->id) }}">Editar</a>
                                        @endif
                                        @if($canUpdateIngresos)
                                        <button
                                            class="btn btn-primary btn-sm"
                                            type="button"
                                            data-open-contract-ingreso
                                            data-action="{{ route('personal.ingresos.accept', $ingreso->id) }}"
                                            data-worker="{{ $name !== '' ? $name : 'Sin nombre' }}"
                                            data-document="{{ trim(($ingreso->tipo_documento ?: 'DNI') . ' ' . $ingreso->numero_documento) }}"
                                        >Agregar a Personal</button>
                                        <form method="POST" action="{{ route('personal.ingresos.contract-not-signed', $ingreso->id) }}">
                                            @csrf
                                            <button class="btn btn-outline btn-sm" type="submit">No firmo contrato</button>
                                        </form>
                                        @endif
                                        @if($canDeleteIngresos)
                                        <form method="POST" action="{{ route('personal.ingresos.destroy', $ingreso->id) }}" class="js-delete-ingreso-form">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm" type="button" data-open-delete-ingreso>Ficha erronea</button>
                                        </form>
                                        @endif
                                    @elseif($existing)
                                        <a class="btn btn-outline btn-sm" href="{{ route('personal.edit', $existing->id) }}">Ver en Personal</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">No hay fichas recibidas con esos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<dialog class="ingreso-delete-dialog" id="deleteIngresoDialog">
    <div class="ingreso-delete-body">
        <h2 style="margin:0 0 8px;color:#0f172a;font-size:20px;">Eliminar ficha erronea</h2>
        <p style="margin:0;color:#475569;line-height:1.55;">
            Esta accion borrara definitivamente la ficha recibida y sus archivos. Confirma solo si la ficha fue enviada por error o no sirve para revision.
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
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-copy-target]');
    if (!button) return;
    const target = document.getElementById(button.dataset.copyTarget);
    if (!target) return;
    navigator.clipboard?.writeText(target.textContent.trim());
    const original = button.textContent;
    button.textContent = 'Copiado';
    setTimeout(function () { button.textContent = original; }, 1400);
});

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
