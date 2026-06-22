@extends('layouts.app')

@section('title', 'Ingresos - Proserge')

@section('content')
<style>
    .ingresos-page {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .ingresos-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
    }

    .ingresos-header h1 {
        margin: 0;
        color: #0f172a;
        font-size: 28px;
    }

    .ingresos-card {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .ingresos-card-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 18px;
        border-bottom: 1px solid #edf2f7;
    }

    .ingresos-card-title {
        margin: 0;
        color: #0f172a;
        font-size: 17px;
        font-weight: 800;
    }

    .ingresos-body {
        padding: 18px;
    }

    .ingresos-link-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(190px, 0.5fr);
        gap: 14px;
    }

    .ingresos-copy-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        background: #f8fafc;
        color: #0f172a;
        font-size: 13px;
        word-break: break-all;
    }

    .ingresos-key {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 48px;
        border: 1px solid #99f6e4;
        border-radius: 10px;
        background: #f0fdfa;
        color: #0f766e;
        font-size: 24px;
        font-weight: 900;
        letter-spacing: 0.08em;
    }

    .ingresos-filters {
        display: grid;
        grid-template-columns: minmax(240px, 1fr) minmax(180px, 240px) auto;
        gap: 12px;
        align-items: end;
    }

    .ingresos-table-wrap {
        overflow-x: auto;
    }

    .ingresos-table {
        width: 100%;
        min-width: 980px;
        border-collapse: collapse;
    }

    .ingresos-table th,
    .ingresos-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #edf2f7;
        text-align: left;
        vertical-align: top;
        color: #172033;
        font-size: 13px;
    }

    .ingresos-table th {
        background: #f8fafc;
        color: #52637a;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
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

    .ingresos-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .ingresos-existing {
        display: inline-flex;
        margin-top: 6px;
        padding: 4px 8px;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 11px;
        font-weight: 800;
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

    @media (max-width: 860px) {
        .ingresos-header,
        .ingresos-card-header {
            flex-direction: column;
        }

        .ingresos-link-grid,
        .ingresos-filters {
            grid-template-columns: 1fr;
        }
    }
</style>

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
                                        <a class="btn btn-outline btn-sm" href="{{ route('personal.ingresos.edit', $ingreso->id) }}">Editar</a>
                                        <form method="POST" action="{{ route('personal.ingresos.accept', $ingreso->id) }}">
                                            @csrf
                                            <button class="btn btn-primary btn-sm" type="submit">Agregar a Personal</button>
                                        </form>
                                        <form method="POST" action="{{ route('personal.ingresos.contract-not-signed', $ingreso->id) }}">
                                            @csrf
                                            <button class="btn btn-outline btn-sm" type="submit">No firmo contrato</button>
                                        </form>
                                        <form method="POST" action="{{ route('personal.ingresos.destroy', $ingreso->id) }}" class="js-delete-ingreso-form">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm" type="button" data-open-delete-ingreso>Ficha erronea</button>
                                        </form>
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
