@extends('layouts.app')

@section('title', 'Revision de ingreso - Proserge')

@section('content')
<style>
    .ingreso-review-page {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .ingreso-review-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
    }

    .ingreso-review-header h1 {
        margin: 0;
        color: #0f172a;
        font-size: 28px;
    }

    .ingreso-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .ingreso-summary-item {
        padding: 14px;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #fff;
    }

    .ingreso-summary-item span {
        display: block;
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .ingreso-summary-item strong {
        display: block;
        margin-top: 5px;
        color: #0f172a;
        font-size: 14px;
    }

    .ingresos-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
    }

    .ingresos-status.pending { background:#fff7ed; color:#9a3412; }
    .ingresos-status.info { background:#dbeafe; color:#1d4ed8; }
    .ingresos-status.success { background:#dcfce7; color:#15803d; }
    .ingresos-status.warning { background:#fef3c7; color:#92400e; }

    .ingreso-actions-footer {
        position: sticky;
        bottom: 0;
        z-index: 5;
        display: flex;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 10px;
        padding: 14px;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 -8px 24px rgba(15, 23, 42, 0.08);
    }

    .ingreso-delete-dialog {
        width: min(520px, calc(100vw - 28px));
        border: 0;
        border-radius: 14px;
        padding: 0;
        box-shadow: 0 22px 70px rgba(15, 23, 42, 0.35);
    }

    .ingreso-delete-dialog::backdrop {
        background: rgba(15, 23, 42, 0.55);
    }

    .ingreso-delete-body {
        padding: 22px;
    }

    @media (max-width: 960px) {
        .ingreso-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 620px) {
        .ingreso-review-header {
            flex-direction: column;
        }

        .ingreso-summary-grid {
            grid-template-columns: 1fr;
        }

        .ingreso-actions-footer {
            position: static;
        }
    }
</style>

@php
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
            @if(!$editing && !$locked)
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
        @if($editing)
            <button class="btn btn-primary" type="submit" form="ingresoEditForm" data-loading-text="Guardando...">Guardar ficha (falta revision)</button>
        @endif

        @if(!$locked)
            <form method="POST" action="{{ route('personal.ingresos.accept', $ingreso->id) }}">
                @csrf
                <button class="btn btn-primary" type="submit">Agregar a la base de datos</button>
            </form>

            <form method="POST" action="{{ route('personal.ingresos.contract-not-signed', $ingreso->id) }}">
                @csrf
                <button class="btn btn-outline" type="submit">Contrato no firmado</button>
            </form>

            <form method="POST" action="{{ route('personal.ingresos.destroy', $ingreso->id) }}" class="js-delete-ingreso-form">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger" type="button" data-open-delete-ingreso>Eliminar trabajador</button>
            </form>
        @elseif($existing)
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
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('deleteIngresoDialog');
    let pendingForm = null;

    document.addEventListener('click', function (event) {
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

        if (event.target.closest('[data-confirm-delete-ingreso]') && pendingForm) {
            pendingForm.submit();
        }
    });

    dialog?.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
            pendingForm = null;
        }
    });
});
</script>
@endpush
