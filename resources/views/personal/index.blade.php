@extends('layouts.app')

@section('title', 'Personal - Proserge')

@section('content')
@php
    $activeFilterCount = collect([
        request('estado'),
        request('tipo'),
        request('mina'),
        request('mina_estado'),
        request('contrato'),
        request('sort') && request('sort') !== 'nombre' ? request('sort') : null,
    ])->filter(fn ($value) => filled($value))->count();
    $canCeasePersonal = \App\Support\Rbac\PermissionMatrix::allowsAny(session('user.permissions', []), 'personal', ['editar', 'actualizar', 'administrar']);
    $canEditContractData = \App\Support\Rbac\PermissionMatrix::allowsAny(session('user.permissions', []), 'personal', ['editar', 'actualizar', 'administrar']);
    $canUpdatePersonal = \App\Support\Rbac\PermissionMatrix::allowsAny(session('user.permissions', []), 'personal', ['actualizar', 'administrar']);
    $canDownloadContractFormats = \App\Support\Rbac\PermissionMatrix::allows(session('user.permissions', []), 'personal', 'exportar');
@endphp
<style>
.acciones-dropdown .accion-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 14px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #334155;
    text-decoration: none;
    font-size: 14px;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.acciones-dropdown .accion-item:hover {
    background-color: #f1f5f9;
    color: #0d9488;
}
.acciones-dropdown .accion-divider {
    height: 1px;
    background-color: #e2e8f0;
    margin: 6px 0;
}
/* Filter Panel Compact */
.filter-panel-compact {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 12px;
}
.filter-panel-compact-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-compact-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 120px;
}
.filter-compact-group.filter-compact-actions {
    min-width: auto;
    margin-left: auto;
}
.filter-compact-label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-compact-select {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    color: #334155;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.filter-compact-select:hover {
    border-color: #cbd5e1;
}
.filter-compact-select:focus {
    outline: none;
    border-color: #19D3C5;
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1);
}
.filter-chips-compact {
    display: flex;
    gap: 4px;
}
.chip-compact {
    padding: 5px 9px;
    font-size: 11px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    font-family: inherit;
}
.chip-compact:hover {
    background: #e2e8f0;
    color: #334155;
}
.chip-compact.active {
    background: #07142A;
    color: #fff;
    border-color: #07142A;
}
/* Chips con color - Estado */
.chip-compact.chip-activo.active {
    background: #10b981;
    border-color: #10b981;
    color: #fff;
}
.chip-compact.chip-inactivo.active {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
}
/* Chips con color - Tipo */
.chip-compact.chip-supervisor.active {
    background: #8b5cf6;
    border-color: #8b5cf6;
    color: #fff;
}
.chip-compact.chip-trabajador.active {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
}
/* Chips con color - Estado Mina */
.chip-compact.chip-habilitado.active {
    background: #22c55e;
    border-color: #22c55e;
    color: #fff;
}
.chip-compact.chip-proceso.active {
    background: #f59e0b;
    border-color: #f59e0b;
    color: #fff;
}
/* Labels con icono */
.filter-compact-label {
    display: flex;
    align-items: center;
    gap: 4px;
}
.btn-limpiar {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    font-size: 12px;
    color: #64748b;
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-limpiar:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #dc2626;
}

.personal-page {
    position: relative;
}

.personal-boot-overlay {
    position: fixed;
    inset: 0;
    z-index: 9998;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 88px 16px 16px;
    background: rgba(248, 250, 252, 0.92);
    backdrop-filter: blur(4px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.18s ease;
}

.personal-page.is-booting .personal-boot-overlay {
    opacity: 1;
    pointer-events: auto;
}

.personal-boot-card {
    width: min(440px, 100%);
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
    padding: 18px 18px 16px;
}

.personal-boot-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
}

.personal-boot-title {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
}

.personal-boot-status {
    font-size: 12px;
    font-weight: 600;
    color: #0d9488;
}

.personal-boot-copy {
    margin: 0 0 12px;
    font-size: 12px;
    line-height: 1.45;
    color: #64748b;
}

.personal-boot-progress {
    position: relative;
    height: 10px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
}

.personal-boot-progress-bar {
    width: 0%;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #19d3c5 0%, #0ea5e9 100%);
    transition: width 0.16s ease;
}

.dg-filter-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    border-radius: 6px;
    background: #e2e8f0;
    color: #475569;
    margin-left: 4px;
    border: 0;
    cursor: pointer;
    padding: 0;
    vertical-align: middle;
    position: relative;
}

.dg-filter-icon.is-active {
    background: #fee2e2;
    color: #b91c1c;
}

.dg-filter-icon.is-active::after {
    content: "";
    position: absolute;
    top: -3px;
    right: -3px;
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #ef4444;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.25);
}

.dg-filter-icon svg {
    width: 12px;
    height: 12px;
    display: block;
    pointer-events: none;
}

.dg-head-cell {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.dg-filter-popover {
    position: fixed;
    top: 0;
    left: 0;
    min-width: 190px;
    max-width: min(260px, calc(100vw - 24px));
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(2, 6, 23, 0.14);
    padding: 10px;
    z-index: 1200;
    display: none;
}

.dg-filter-popover.is-open {
    display: block;
}

.dg-pop-left {
    min-width: 210px;
}

.dg-pop-center {
    min-width: 210px;
}

.dg-pop-wide {
    min-width: 310px;
    max-width: min(390px, calc(100vw - 24px));
}

.dg-filter-popover .filter-compact-select,
.dg-filter-popover input.filter-compact-select {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.personal-page .table-responsive {
    overflow-x: auto;
    overflow-y: visible;
}

.dg-popover-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 4px;
}

.dg-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.1;
    border: 1px solid transparent;
    white-space: nowrap;
}

.dg-pill-neutral {
    background: #f1f5f9;
    color: #475569;
    border-color: #e2e8f0;
}

.dg-pill-estado-activo {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.dg-pill-estado-contrato {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.dg-pill-estado-inactivo,
.dg-pill-estado-cesado {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fecaca;
}

.dg-pill-contrato-indefinido {
    background: #dbeafe;
    color: #1d4ed8;
    border-color: #93c5fd;
}

.dg-pill-contrato-temporal {
    background: #ffedd5;
    color: #9a3412;
    border-color: #fdba74;
}

.dg-pill-contrato-servicio {
    background: #e0f2fe;
    color: #0c4a6e;
    border-color: #7dd3fc;
}

.dg-pill-contrato-practicante {
    background: #ede9fe;
    color: #5b21b6;
    border-color: #c4b5fd;
}

.dg-pill-situacion-activo {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.dg-pill-situacion-vacaciones {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.dg-pill-situacion-revision {
    background: #fef3c7;
    color: #92400e;
    border-color: #f59e0b;
}

.dg-pill-situacion-descanso {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fca5a5;
}

.dg-pill-situacion-gestacion {
    background: #fce7f3;
    color: #9d174d;
    border-color: #f9a8d4;
}

.dg-pill-situacion-parada {
    background: #ffe4e6;
    color: #9f1239;
    border-color: #fda4af;
}

.dg-pill-situacion-bloqueo {
    background: #e0e7ff;
    color: #3730a3;
    border-color: #a5b4fc;
}

.dg-pill-situacion-inactivo {
    background: #e5e7eb;
    color: #374151;
    border-color: #d1d5db;
}

.dg-ocupacion-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.dg-pill-ocup-mina {
    background: #ecfdf5;
    color: #047857;
    border-color: #a7f3d0;
}

.dg-pill-ocup-mina-proceso {
    background: #fffbeb;
    color: #b45309;
    border-color: #fde68a;
}

.dg-pill-ocup-mina-no-habilitado {
    background: #fef2f2;
    color: #b91c1c;
    border-color: #fecaca;
}

.dg-pill-ocup-oficina {
    background: #d1fae5;
    color: #065f46;
    border-color: #6ee7b7;
}

.dg-pill-ocup-taller {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.dg-pill-ocup-more {
    background: #f8fafc;
    color: #334155;
    border-color: #cbd5e1;
}

.personal-page .table-responsive.personal-grid-wrap {
    position: relative;
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable both-edges;
    cursor: grab;
    user-select: auto;
}

.personal-page .table-responsive.personal-grid-wrap.is-dragging {
    cursor: grabbing;
    user-select: none;
}

.personal-page .table-responsive.personal-grid-wrap button,
.personal-page .table-responsive.personal-grid-wrap a,
.personal-page .table-responsive.personal-grid-wrap [role="button"] {
    cursor: pointer;
}

.personal-page .table-responsive.personal-grid-wrap input,
.personal-page .table-responsive.personal-grid-wrap textarea {
    cursor: text;
}

.personal-page .table-responsive.personal-grid-wrap select,
.personal-page .table-responsive.personal-grid-wrap label {
    cursor: default;
}

.personal-page .personal-grid-shell {
    width: 100%;
    max-width: 100%;
}

.personal-page .personal-grid-scroll-top {
    position: sticky;
    top: 0;
    z-index: 7;
    height: 16px;
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #e2e8f0;
    border-bottom: 0;
    border-radius: 12px 12px 0 0;
    background: #f8fafc;
    display: none;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable both-edges;
}

.personal-page .personal-grid-scroll-top.is-visible {
    display: block;
}

.personal-page .personal-grid-scroll-top-inner {
    height: 1px;
}

.personal-page .personal-grid-shell.is-expanded {
    width: 100%;
    max-width: 100%;
}

.personal-page .personal-grid-shell.is-expanded .personal-grid-wrap,
.personal-page .personal-grid-shell.is-expanded .personal-grid-scroll-top {
    width: 100%;
    max-width: 100%;
}

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact {
    width: max-content;
    min-width: 100%;
    table-layout: auto;
}

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="trabajador"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="trabajador"] { min-width: 240px; width: 240px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="correo"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="correo"] { min-width: 220px; width: 220px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="puesto"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="puesto"] { min-width: 210px; width: 210px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="situacion"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="situacion"] { min-width: 160px; width: 160px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="ocupacion"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="ocupacion"] { min-width: 420px; width: 420px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th[data-column="acciones"],
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td[data-column="acciones"] { min-width: 220px; width: 220px; }

.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact td,
.personal-page .personal-grid-shell.is-expanded .data-table.personal-grid-compact th {
    white-space: nowrap;
}

.personal-page .personal-grid-shell.is-expanded .personal-action-buttons {
    flex-wrap: nowrap;
}

.personal-page .personal-grid-shell.is-expanded .personal-action-buttons form {
    display: inline-flex;
}

.personal-page .data-table.personal-grid-compact {
    width: 100%;
    min-width: 1120px;
    table-layout: fixed;
    border-collapse: collapse;
}

.personal-page .data-table.personal-grid-compact th,
.personal-page .data-table.personal-grid-compact td {
    padding: 8px 10px;
    font-size: 12px;
    vertical-align: top;
    overflow: hidden;
    box-sizing: border-box;
}

.personal-page .data-table.personal-grid-compact thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
}

.personal-page .data-table.personal-grid-compact th[data-column="trabajador"],
.personal-page .data-table.personal-grid-compact td[data-column="trabajador"] {
    min-width: 220px;
    width: 220px;
    position: sticky;
    left: 0;
    z-index: 1;
    background: #fff;
}

.personal-page .data-table.personal-grid-compact thead th[data-column="trabajador"] {
    z-index: 4;
    background: #f8fafc;
}

.personal-page .data-table.personal-grid-compact th[data-column="documento"],
.personal-page .data-table.personal-grid-compact td[data-column="documento"] { width: 130px; min-width: 130px; }

.personal-page .data-table.personal-grid-compact th[data-column="celular"],
.personal-page .data-table.personal-grid-compact td[data-column="celular"] { width: 118px; min-width: 118px; }

.personal-page .data-table.personal-grid-compact th[data-column="correo"],
.personal-page .data-table.personal-grid-compact td[data-column="correo"] { width: 180px; min-width: 180px; }

.personal-page .data-table.personal-grid-compact th[data-column="puesto"],
.personal-page .data-table.personal-grid-compact td[data-column="puesto"] { width: 170px; min-width: 170px; }

.personal-page .data-table.personal-grid-compact th[data-column="contrato"],
.personal-page .data-table.personal-grid-compact td[data-column="contrato"] { width: 120px; min-width: 120px; }

.personal-page .data-table.personal-grid-compact th[data-column="estado"],
.personal-page .data-table.personal-grid-compact td[data-column="estado"] { width: 108px; min-width: 108px; }

.personal-page .data-table.personal-grid-compact th[data-column="situacion"],
.personal-page .data-table.personal-grid-compact td[data-column="situacion"] { width: 135px; min-width: 135px; }

.personal-page .data-table.personal-grid-compact th[data-column="ocupacion"],
.personal-page .data-table.personal-grid-compact td[data-column="ocupacion"] { width: 260px; min-width: 260px; }

.personal-page .data-table.personal-grid-compact th[data-column="acciones"],
.personal-page .data-table.personal-grid-compact td[data-column="acciones"] { width: 132px; min-width: 132px; }

.personal-page .data-table.personal-grid-compact td[data-column="documento"],
.personal-page .data-table.personal-grid-compact td[data-column="celular"],
.personal-page .data-table.personal-grid-compact td[data-column="correo"],
.personal-page .data-table.personal-grid-compact td[data-column="puesto"],
.personal-page .data-table.personal-grid-compact td[data-column="contrato"],
.personal-page .data-table.personal-grid-compact td[data-column="estado"],
.personal-page .data-table.personal-grid-compact td[data-column="situacion"] {
    word-break: break-word;
    overflow-wrap: anywhere;
}

.personal-page .data-table.personal-grid-compact td[data-column="contrato"] .dg-pill,
.personal-page .data-table.personal-grid-compact td[data-column="estado"] .dg-pill,
.personal-page .data-table.personal-grid-compact td[data-column="situacion"] .dg-pill {
    display: inline-flex;
    width: 100%;
    max-width: 100%;
    justify-content: center;
    text-align: center;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    padding-left: 8px;
    padding-right: 8px;
}

.personal-page .data-table.personal-grid-compact td[data-column="trabajador"] {
    font-size: 13px;
    line-height: 1.15;
    font-weight: 500;
    color: #0f172a;
    word-break: break-word;
}

.personal-page .data-table.personal-grid-compact td[data-column="trabajador"] .personal-worker-name {
    display: block;
    line-height: 1.15;
}

.personal-page .data-table.personal-grid-compact td[data-column="ocupacion"] {
    padding-top: 6px;
    padding-bottom: 6px;
}

.personal-page .data-table.personal-grid-compact .is-col-hidden {
    display: none;
}

.personal-view-tools {
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 20;
    flex-wrap: wrap;
}

.personal-grid-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.personal-view-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    position: relative;
    z-index: 21;
    cursor: pointer;
    pointer-events: auto;
}

.personal-column-popover {
    position: fixed;
    top: 0;
    left: 0;
    width: min(260px, calc(100vw - 32px));
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
    padding: 12px;
    z-index: 120;
    display: none;
    max-height: 70vh;
    overflow: auto;
    pointer-events: auto;
}

.personal-column-popover.is-open {
    display: block;
}

.personal-column-popover-title {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
}

.personal-column-popover-help {
    margin: 0 0 10px;
    font-size: 11px;
    color: #64748b;
    line-height: 1.35;
}

.personal-column-list {
    display: grid;
    gap: 8px;
}

.personal-view-actions {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.personal-column-option {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #334155;
}

.personal-column-option input {
    margin: 0;
}

.dg-ocupacion-list.dg-ocupacion-grid {
    display: grid;
    gap: 6px;
    max-width: 100%;
}

.dg-ocup-chip-btn {
    border: 0;
    background: transparent;
    padding: 0;
    margin: 0;
    cursor: pointer;
}

.dg-ocup-chip-btn:focus-visible .dg-pill,
.dg-ocup-chip-btn:hover .dg-pill {
    box-shadow: 0 0 0 2px rgba(15, 98, 254, 0.14);
}

.dg-pill-ocup-primary {
    border-width: 2px;
    font-weight: 800;
}

.dg-ocup-board {
    display: grid;
    gap: 5px;
}

.dg-ocup-flat {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
    white-space: nowrap;
    min-width: max-content;
}

.dg-ocup-cell {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
    max-width: 220px;
    padding: 7px 10px;
    border: 1px solid #dbe3ef;
    background: #fff;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
    color: #334155;
    white-space: normal;
    text-align: center;
    line-height: 1.2;
}

.dg-ocup-cell-mina {
    border-color: #a7f3d0;
    background: #ecfdf5;
    color: #047857;
}

.dg-ocup-cell-mina-proceso {
    border-color: #fde68a;
    background: #fffbeb;
    color: #b45309;
}

.dg-ocup-cell-mina-no-habilitado {
    border-color: #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.dg-ocup-cell-oficina {
    border-color: #6ee7b7;
    background: #d1fae5;
    color: #065f46;
}

.dg-ocup-cell-taller {
    border-color: #fcd34d;
    background: #fef3c7;
    color: #92400e;
}

.dg-ocup-cell-empty {
    border-style: dashed;
    border-color: #dbe3ef;
    background: #f8fafc;
    color: #94a3b8;
}

.is-ocup-state-hidden .dg-ocup-cell,
.is-ocup-state-hidden .dg-pill {
    border-style: dashed !important;
    border-color: #dbe3ef !important;
    background: #f8fafc !important;
    color: #94a3b8 !important;
    box-shadow: none !important;
}

.dg-ocup-row {
    display: grid;
    grid-template-columns: 62px minmax(0, 1fr);
    gap: 6px;
    align-items: start;
}

.dg-ocup-label {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding-top: 5px;
}

.dg-ocup-values {
    display: flex;
    flex-wrap: nowrap;
    gap: 4px;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 2px;
    -webkit-overflow-scrolling: touch;
}

.dg-ocup-values::-webkit-scrollbar {
    height: 6px;
}

.dg-ocup-values::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}

.dg-ocup-empty {
    font-size: 11px;
    color: #94a3b8;
    padding-top: 4px;
}

.is-ocup-section-hidden {
    display: none;
}

.is-ocup-row-hidden {
    display: none;
}

.dg-ocup-filter-checks {
    display: grid;
    gap: 6px;
    max-height: 180px;
    overflow: auto;
    margin-top: 8px;
    padding-right: 2px;
}

.dg-ocup-filter-option {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #334155;
}

.dg-ocup-filter-option input {
    margin: 0;
}

.personal-grid-shell.is-ocup-grouped .dg-ocup-flat {
    display: none;
}

.personal-grid-shell:not(.is-ocup-grouped) .dg-ocup-board {
    display: none;
}

.personal-grid-shell:not(.is-ocup-grouped) .data-table.personal-grid-compact {
    table-layout: auto;
    width: max-content;
}

.personal-grid-shell:not(.is-ocup-grouped) .data-table.personal-grid-compact th[data-column="ocupacion"],
.personal-grid-shell:not(.is-ocup-grouped) .data-table.personal-grid-compact td[data-column="ocupacion"] {
    width: auto;
    min-width: 340px;
}

/* Personal index refinements */
.personal-page .page-header {
    margin-bottom: 8px;
}

.personal-page .page-header-top {
    align-items: center;
    gap: 10px;
}

.personal-page .page-title {
    margin-bottom: 0;
    font-size: 24px;
    line-height: 1.1;
}

.personal-page .page-subtitle {
    display: none;
}

.personal-page .page-actions {
    gap: 6px;
}

.personal-page .toolbar-search {
    margin-top: 8px;
    margin-bottom: 10px;
}

.personal-page .toolbar-search .simple-search-input {
    height: 40px;
    padding-top: 8px;
    padding-bottom: 8px;
    border-radius: 10px;
}

.personal-page .card-header {
    padding-top: 14px;
    padding-bottom: 14px;
}

.personal-page .card-body {
    padding-top: 14px;
    overflow: visible;
}

.personal-page .card-header.personal-grid-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.personal-page .person-card {
    padding: 14px;
    border-radius: 14px;
}

.personal-page .person-badges {
    gap: 4px;
    margin-bottom: 0;
}

.personal-page .person-badge {
    padding: 3px 8px;
    font-size: 10px;
}

.personal-page .person-badge.mine-extra-count {
    background: #f8fafc;
    color: #334155;
    border: 1px dashed #cbd5e1;
}

.personal-page .person-actions {
    margin-left: 8px;
}

.personal-action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.personal-action-buttons form {
    margin: 0;
}

.personal-icon-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.personal-icon-btn svg {
    width: 16px;
    height: 16px;
}

.personal-page .personal-pagination-controls {
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.worker-detail-modal .detail-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: stretch;
}

.worker-detail-modal .detail-footer > * {
    min-width: 0;
}

.worker-detail-modal .detail-footer form {
    margin: 0;
    display: inline-flex;
    max-width: 100%;
}

.worker-detail-modal .detail-footer .btn {
    max-width: 100%;
    white-space: normal;
}

.personal-cease-modal {
    width: min(440px, calc(100vw - 32px));
    border-radius: 14px;
    padding: 18px;
}

.personal-cease-modal .modal-header {
    padding-bottom: 12px;
    margin-bottom: 12px;
}

.personal-cease-textarea {
    width: 100%;
    min-height: 108px;
    resize: vertical;
    box-sizing: border-box;
}

.personal-cease-error {
    display: none;
    margin-top: 8px;
    color: #dc2626;
    font-size: 12px;
    font-weight: 600;
}

.personal-cease-view {
    display: grid;
    gap: 12px;
}

.personal-cease-view-name {
    margin: 0;
    font-size: 13px;
    color: #64748b;
}

.personal-cease-view-reason {
    margin: 0;
    padding: 12px;
    min-height: 88px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    color: #0f172a;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
}

.personal-cease-view-meta {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 6px 10px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
    font-size: 12px;
}

.personal-cease-view-meta span:nth-child(odd) {
    color: #64748b;
    font-weight: 700;
}

.personal-cease-view-meta span:nth-child(even) {
    color: #0f172a;
}

.personal-contract-modal {
    width: min(760px, calc(100vw - 32px));
    height: auto;
    max-height: calc(100vh - 32px);
    overflow: hidden;
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    transition: width 0.18s ease, height 0.18s ease;
}

.personal-contract-modal.is-preview {
    width: calc(100vw - 32px);
    height: min(90vh, 900px);
    max-width: none;
}

.personal-contract-modal .modal-body {
    display: grid;
    gap: 14px;
    overflow: auto;
    padding-right: 4px;
    flex: 1;
    min-height: 0;
}

.contract-step {
    display: none;
}

.contract-step.is-active {
    display: grid;
    gap: 14px;
    min-height: 0;
}

#contractFormatStepWorkers.is-active {
    grid-template-rows: auto auto minmax(0, 1fr);
    flex: 1;
}

.contract-step-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.contract-selected-template {
    display: grid;
    gap: 3px;
    min-width: min(360px, 100%);
    padding: 10px 12px;
    border: 1px solid #ccfbf1;
    border-radius: 10px;
    background: #f0fdfa;
    color: #0f766e;
}

.contract-selected-template strong {
    color: #0f172a;
    font-size: 13px;
}

.contract-selected-template span {
    color: #0f766e;
    font-size: 12px;
}

.contract-format-template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
}

.contract-format-template {
    position: relative;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
    align-items: center;
    min-height: 94px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 14px 14px 18px;
    background: #ffffff;
    color: #0f172a;
    text-align: left;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.contract-format-template::before {
    content: "";
    position: absolute;
    left: 0;
    top: 12px;
    bottom: 12px;
    width: 4px;
    border-radius: 0 999px 999px 0;
    background: #0d9488;
}

.contract-format-template:hover {
    border-color: #99f6e4;
    box-shadow: 0 10px 24px rgba(15, 118, 110, 0.12);
    transform: translateY(-1px);
}

.contract-format-template.is-selected {
    border-color: #0d9488;
    box-shadow: 0 0 0 2px rgba(13, 148, 136, 0.12);
}

.contract-format-template strong {
    display: block;
    font-size: 14px;
}

.contract-format-template > span:first-child {
    display: block;
    margin-top: 0;
}

.contract-format-template > span:first-child span {
    display: block;
    margin-top: 3px;
    color: #64748b;
    font-size: 12px;
}

.contract-format-template-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 74px;
    margin-top: 0;
    padding: 5px 8px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.contract-worker-picker {
    display: grid;
    grid-template-columns: minmax(240px, 340px) minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}

.contract-preview-section {
    display: flex;
    flex-direction: column;
    min-height: 0;
    min-width: 0;
}

.contract-search-results {
    display: none;
    margin-top: 6px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    background: #ffffff;
}

.contract-search-result {
    width: 100%;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    background: #ffffff;
    padding: 9px 10px;
    text-align: left;
    cursor: pointer;
}

.contract-search-result:hover {
    background: #f8fafc;
}

.contract-search-result strong,
.contract-worker-chip strong {
    display: block;
    color: #0f172a;
    font-size: 12px;
}

.contract-search-result span,
.contract-worker-chip span {
    display: block;
    color: #64748b;
    font-size: 11px;
    margin-top: 2px;
}

.contract-selected-workers {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.contract-worker-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 7px 8px 7px 10px;
    background: #f8fafc;
}

.contract-worker-chip button {
    width: 22px;
    height: 22px;
    border: 0;
    border-radius: 999px;
    background: #e2e8f0;
    color: #334155;
    cursor: pointer;
}

.contract-preview-wrap {
    flex: 1;
    min-height: 0;
    min-width: 0;
    width: 100%;
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
}

.contract-preview-table {
    width: max-content;
    min-width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.contract-preview-table th,
.contract-preview-table td {
    border-bottom: 1px solid #e2e8f0;
    border-right: 1px solid #f1f5f9;
    padding: 8px 10px;
    max-width: 360px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.contract-preview-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8fafc;
    color: #334155;
    font-weight: 700;
}

.contract-preview-empty {
    padding: 18px;
    color: #64748b;
    font-size: 13px;
}

.dg-pill-button {
    border: 1px solid transparent;
    cursor: pointer;
    font-family: inherit;
}

.dg-pill-button:hover {
    box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.12);
}

@media (max-width: 768px) {
    .filter-panel-compact-row {
        flex-direction: column;
    }
    .filter-compact-group {
        width: 100%;
    }
    .filter-compact-group.filter-compact-actions {
        margin-left: 0;
    }
    .filter-chips-compact {
        flex-wrap: wrap;
    }

    .personal-page .page-title {
        font-size: 20px;
    }

    .personal-page .toolbar-search {
        margin-top: 6px;
    }

    .personal-page .data-table.personal-grid-compact {
        min-width: 900px;
    }

    .personal-page .personal-grid-shell.is-expanded {
        width: 100%;
        max-width: 100%;
    }

    .personal-view-tools {
        width: 100%;
        justify-content: space-between;
        gap: 8px;
    }

    .personal-view-toggle {
        padding-left: 10px;
        padding-right: 10px;
        font-size: 12px;
    }

    .personal-column-popover {
        right: auto;
        left: 0;
        width: min(280px, calc(100vw - 32px));
    }

    .personal-view-actions {
        width: 100%;
    }

    .personal-page .data-table.personal-grid-compact th,
    .personal-page .data-table.personal-grid-compact td {
        padding: 7px 8px;
        font-size: 11px;
    }

    .personal-page .data-table.personal-grid-compact td[data-column="trabajador"] {
        width: 180px;
        min-width: 180px;
        font-size: 12px;
    }

    .personal-page .data-table.personal-grid-compact td[data-column="trabajador"] .personal-worker-meta {
        font-size: 10px;
    }

    .personal-page .data-table.personal-grid-compact th[data-column="documento"],
    .personal-page .data-table.personal-grid-compact td[data-column="documento"] { width: 118px; min-width: 118px; }

    .personal-page .data-table.personal-grid-compact th[data-column="celular"],
    .personal-page .data-table.personal-grid-compact td[data-column="celular"] { width: 110px; min-width: 110px; }

    .personal-page .data-table.personal-grid-compact th[data-column="correo"],
    .personal-page .data-table.personal-grid-compact td[data-column="correo"] { width: 150px; min-width: 150px; }

    .personal-page .data-table.personal-grid-compact th[data-column="puesto"],
    .personal-page .data-table.personal-grid-compact td[data-column="puesto"] { width: 150px; min-width: 150px; }

    .personal-page .data-table.personal-grid-compact th[data-column="ocupacion"],
    .personal-page .data-table.personal-grid-compact td[data-column="ocupacion"] { width: 220px; min-width: 220px; }

    .dg-ocup-row {
        grid-template-columns: 56px minmax(0, 1fr);
        gap: 4px;
    }

    .dg-ocup-label {
        font-size: 9px;
    }

    .dg-ocup-cell {
        min-width: 96px;
        max-width: 160px;
        font-size: 10px;
        padding: 6px 8px;
    }

    .personal-icon-btn {
        width: 30px;
        height: 30px;
    }

    .worker-detail-modal .detail-footer {
        flex-direction: column;
    }

    .worker-detail-modal .detail-footer form,
    .worker-detail-modal .detail-footer .btn {
        width: 100%;
    }

    .contract-worker-picker {
        grid-template-columns: 1fr;
    }

    .personal-contract-modal {
        width: calc(100vw - 16px);
        height: auto;
        max-height: calc(100vh - 16px);
        padding: 14px;
    }

    .personal-contract-modal.is-preview {
        height: calc(100vh - 16px);
    }

    .contract-preview-wrap {
        min-height: 0;
    }
}
</style>
<div class="module-page personal-page is-booting" id="personalPageRoot">
    <div class="personal-boot-overlay" id="personalBootOverlay" aria-hidden="true">
        <div class="personal-boot-card">
            <div class="personal-boot-head">
                <h2 class="personal-boot-title">Cargando</h2>
                <span class="personal-boot-status" id="personalBootStatus">Iniciando...</span>
            </div>
            <div class="personal-boot-progress" aria-hidden="true">
                <div class="personal-boot-progress-bar" id="personalBootProgressBar"></div>
            </div>
        </div>
    </div>
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal</h1>
                <p class="page-subtitle">Gestión y búsqueda de trabajadores</p>
            </div>
            <div class="page-actions" style="gap: 8px;">
                <!-- Dropdown acciones principales -->
                <div class="dropdown-container" style="position: relative;">
                    <button type="button" id="accionesBtn" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="1"/>
                            <circle cx="19" cy="12" r="1"/>
                            <circle cx="5" cy="12" r="1"/>
                        </svg>
                        Acciones
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div id="accionesMenu" class="acciones-dropdown" style="display: none; position: absolute; top: calc(100% + 8px); right: 0; min-width: 260px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 8px; z-index: 9999;">
                        <a class="accion-item" href="{{ route('personal.create') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Añadir manualmente
                        </a>
                        <a class="accion-item" href="{{ route('personal.fichas.import') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="12" y1="18" x2="12" y2="12"/>
                                <polyline points="9 15 12 18 15 15"/>
                            </svg>
                            Importar macro / contrato
                        </a>
                        <a class="accion-item" href="{{ route('personal.fichas.temporales') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                            Temporales y links
                        </a>
                        <div class="accion-divider"></div>
                        <a class="accion-item" href="{{ route('personal.importar') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Importar Master General
                        </a>
                        <a class="accion-item" href="{{ route('personal.export.form', request()->query()) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Exportar
                        </a>
                        @if($canDownloadContractFormats)
                            <button type="button" class="accion-item" onclick="openContractFormatModal(); document.getElementById('accionesMenu').style.display = 'none';">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <path d="M14 2v6h6"/>
                                    <path d="M8 13h8"/>
                                    <path d="M8 17h5"/>
                                    <path d="M12 9v8"/>
                                    <path d="M9 14l3 3 3-3"/>
                                </svg>
                                Descargar formato de contrato
                            </button>
                        @endif
                     </div>
                </div>
                
                <!-- Quick Export Buttons removed as requested -->
                
                <!-- Botón filtros -->
                <button type="button" id="filterToggle" class="btn btn-outline d-flex align-items-center gap-2" aria-expanded="false" aria-label="Mostrar filtros" title="Mostrar filtros" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22 3 2 3 10 12.69 10 21 14 21 14 12.69 22 3"/>
                    </svg>
                    <span>Filtros</span>
                    <span id="filterBadge" class="badge bg-primary text-white {{ $activeFilterCount > 0 ? '' : 'hidden' }}" style="font-size: 11px; padding: 2px 6px;">{{ $activeFilterCount }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Simple Search -->
    <div class="toolbar-search">
        @include('components.ui.simple-search', [
            'searchId' => 'personal-search',
            'placeholder' => 'Buscar por nombre, documento, mina, puesto...',
            'showClear' => true
        ])
    </div>

<!-- Filter Panel -->
    <form method="GET" action="{{ route('personal.index') }}" class="filter-panel-compact" id="filterPanel" style="display: none;">
        <div class="filter-panel-compact-row">
            <!-- Ordenar por -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="4" y1="9" x2="20" y2="9"/>
                        <line x1="4" y1="15" x2="14" y2="15"/>
                        <line x1="4" y1="21" x2="8" y2="21"/>
                    </svg>
                    Ordenar
                </label>
                <select class="filter-compact-select" name="sort" data-filter-change>
                    <option value="nombre" {{ request('sort') == 'nombre' ? 'selected' : '' }}>Nombre</option>
                    <option value="puesto" {{ request('sort') == 'puesto' ? 'selected' : '' }}>Puesto</option>
                    <option value="contrato" {{ request('sort') == 'contrato' ? 'selected' : '' }}>Contrato</option>
                    <option value="estado" {{ request('sort') == 'estado' ? 'selected' : '' }}>Estado</option>
                    <option value="dni" {{ request('sort') == 'dni' ? 'selected' : '' }}>Documento</option>
                </select>
            </div>

            <!-- Estado -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Estado
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('estado', '') == '' ? 'active' : '' }}" data-filter-chip="estado" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-activo {{ strtoupper((string) request('estado')) == 'ACTIVO' ? 'active' : '' }}" data-filter-chip="estado" data-value="ACTIVO">Activos</button>
                    <button type="button" class="chip-compact chip-inactivo {{ strtoupper((string) request('estado')) == 'INACTIVO' ? 'active' : '' }}" data-filter-chip="estado" data-value="INACTIVO">Inactivos</button>
                    <button type="button" class="chip-compact {{ strtoupper((string) request('estado')) == 'CESADO' ? 'active' : '' }}" data-filter-chip="estado" data-value="CESADO">Cesados</button>
                </div>
            </div>

            <!-- Tipo -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Tipo
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('tipo', '') == '' ? 'active' : '' }}" data-filter-chip="tipo" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-supervisor {{ request('tipo') == 'supervisor' ? 'active' : '' }}" data-filter-chip="tipo" data-value="supervisor">Superv.</button>
                    <button type="button" class="chip-compact chip-trabajador {{ request('tipo') == 'trabajador' ? 'active' : '' }}" data-filter-chip="tipo" data-value="trabajador">Trab.</button>
                </div>
            </div>

            <!-- Mina -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M2 22h20"/>
                        <path d="M6 19V9a3 3 0 0 1 3-3h0"/>
                        <path d="M10 19V5a3 3 0 0 1 3-3h0"/>
                        <path d="M14 19v-6a3 3 0 0 1 3-3h0"/>
                        <path d="M18 19v-2a3 3 0 0 1 3-3h0"/>
                    </svg>
                    Mina
                </label>
                <select class="filter-compact-select" name="mina" data-filter-change id="filterMina">
                    <option value="">Todas</option>
                    @foreach(\App\Models\Mina::where('estado', 'ACTIVO')->orderBy('nombre')->get() as $mina)
                        <option value="{{ $mina->id }}" {{ request('mina') == $mina->id ? 'selected' : '' }}>{{ $mina->nombre }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Estado Mina (solo visible cuando hay mina seleccionada) -->
            <div class="filter-compact-group" id="filterEstadoMinaGroup" style="display: none;">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Estado Mina
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('mina_estado', '') == '' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-habilitado {{ request('mina_estado') == 'habilitado' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="habilitado">Habil.</button>
                    <button type="button" class="chip-compact chip-proceso {{ request('mina_estado') == 'proceso' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="proceso">Proceso</button>
                </div>
            </div>

            <!-- Contrato -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    Contrato
                </label>
                <select class="filter-compact-select" name="contrato" data-filter-change>
                    <option value="">Todos</option>
                    <option value="REG" {{ request('contrato') == 'REG' ? 'selected' : '' }}>Régimen</option>
                    <option value="FIJO" {{ request('contrato') == 'FIJO' ? 'selected' : '' }}>Fijo</option>
                    <option value="INTER" {{ request('contrato') == 'INTER' ? 'selected' : '' }}>Intermitente</option>
                    <option value="INDET" {{ request('contrato') == 'INDET' ? 'selected' : '' }}>Indeterminado</option>
                </select>
            </div>

            <!-- Limpiar -->
            <div class="filter-compact-group filter-compact-actions">
                <a href="{{ route('personal.index') }}" class="btn-limpiar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18"/>
                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                    </svg>
                    Limpiar
                </a>
            </div>
        </div>
    </form>

    <div class="personal-column-popover" id="personalColumnsPopover">
        <p class="personal-column-popover-title">Mostrar columnas</p>
        <p class="personal-column-popover-help">La vista recuerda columnas, busqueda, pagina y posicion en esta maquina.</p>
        <div class="personal-column-list">
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="documento" checked> Documento</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="celular" checked> Celular</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="correo" checked> Correo</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="puesto" checked> Puesto</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="contrato" checked> Contrato</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="estado" checked> Estado</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="situacion" checked> Situacion</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="ocupacion" checked> Ocupacion</label>
            <label class="personal-column-option"><input type="checkbox" class="js-col-toggle" value="acciones" checked> Acciones</label>
        </div>
        <div class="personal-view-actions">
            <button type="button" class="btn btn-outline btn-sm" id="personalExpandToggle">Extender pantalla</button>
        </div>
    </div>

    <div class="personal-grid-toolbar">
        <div class="personal-view-tools">
            <button type="button" class="btn btn-outline" id="personalResetViewButton" data-reset-url="{{ route('personal.index') }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18"/>
                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                </svg>
                Restaurar filtros
            </button>
            <button type="button" class="btn btn-outline personal-view-toggle" id="personalColumnsToggle" aria-expanded="false" aria-controls="personalColumnsPopover">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="16" rx="2"/>
                    <path d="M9 4v16"/>
                    <path d="M15 4v16"/>
                </svg>
                Vista
            </button>
            <span class="card-badge" id="personalCount">{{ count($trabajadores ?? []) }} trabajadores</span>
        </div>
    </div>

    <!-- Results -->
    <div class="card">
        <div class="card-header personal-grid-header">
            <span class="card-title">Listado de Personal</span>
        </div>
        <div class="card-body">
            @if(empty($trabajadores))
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">Aún no hay personal registrado</h3>
                    <p class="empty-description">Una vez que se integren trabajadores al sistema, aparecerán aquí.</p>
                    <div class="empty-action">
                        <a href="{{ route('personal.create') }}" class="btn btn-primary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Agregar Trabajador
                        </a>
                    </div>
                </div>
            @else
                <div class="personal-grid-shell" id="personalGridShell">
                    <div class="personal-grid-scroll-top" id="personalTopScrollbar" aria-hidden="true">
                        <div class="personal-grid-scroll-top-inner" id="personalTopScrollbarInner"></div>
                    </div>
                    <div class="table-responsive personal-grid-wrap" id="personalTableWrap">
                        <table class="data-table personal-grid-compact" id="personalDataGrid">
                        <thead>
                            <tr>
                                <th data-column="trabajador">
                                    <div class="dg-head-cell">
                                        <span>Trabajador</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterNombre" title="Filtrar Trabajador" aria-label="Filtrar Trabajador">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterNombre" class="dg-filter-popover dg-pop-left" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Orden</label>
                                            <select id="dgSortNombre" class="filter-compact-select">
                                                <option value="">Sin orden</option>
                                                <option value="asc">A-Z</option>
                                                <option value="desc">Z-A</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="documento">
                                    <div class="dg-head-cell">
                                        <span>Documento</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterDni" title="Filtrar documento" aria-label="Filtrar documento">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterDni" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Orden</label>
                                            <select id="dgSortDni" class="filter-compact-select">
                                                <option value="">Sin orden</option>
                                                <option value="asc">Asc</option>
                                                <option value="desc">Desc</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="celular">Celular</th>
                                <th data-column="correo">Correo</th>
                                <th data-column="puesto">
                                    <div class="dg-head-cell">
                                        <span>Puesto</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterPuesto" title="Filtrar Puesto" aria-label="Filtrar Puesto">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterPuesto" class="dg-filter-popover dg-pop-center dg-pop-wide" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Puesto</label>
                                            <select id="dgPuesto" class="filter-compact-select"><option value="">Todos</option></select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="contrato">
                                    <div class="dg-head-cell">
                                        <span>Contrato</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterContrato" title="Filtrar Contrato" aria-label="Filtrar Contrato">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterContrato" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Contrato</label>
                                            <select id="dgContrato" class="filter-compact-select"><option value="">Todos</option></select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="estado">
                                    <div class="dg-head-cell">
                                        <span>Estado</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterEstado" title="Filtrar Estado" aria-label="Filtrar Estado">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterEstado" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Estado</label>
                                            <select id="dgEstado" class="filter-compact-select">
                                                <option value="">Todos</option>
                                                <option value="activo">Activo</option>
                                                <option value="inactivo">Inactivo</option>
                                                <option value="cesado">Cesado</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="situacion">
                                    <div class="dg-head-cell">
                                        <span>Situación</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterBienestar" title="Filtrar Bienestar" aria-label="Filtrar Bienestar">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterBienestar" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Situación</label>
                                            <select id="dgBienestar" class="filter-compact-select">
                                                <option value="">Todos</option>
                                                <option value="parada">En parada</option>
                                                <option value="oficina">En oficina</option>
                                                <option value="taller">En taller</option>
                                                <option value="habilitado">Habilitado</option>
                                                <option value="no_habilitado">No habilitado</option>
                                                <option value="vacaciones">Vacaciones</option>
                                                <option value="descanso_medico">Descanso medico</option>
                                                <option value="gestacion">Gestacion</option>
                                                <option value="revisar_ficha">Revisar ficha</option>
                                                <option value="terminar_ficha">Terminar ficha</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="ocupacion">
                                    <div class="dg-head-cell">
                                        <span>Ocupación</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterOcupacion" title="Filtrar Ocupación" aria-label="Filtrar Ocupación">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
                                        </button>
                                        <div id="dgFilterOcupacion" class="dg-filter-popover dg-pop-center dg-pop-wide" onclick="event.stopPropagation()">
                                            <label class="personal-column-option" style="margin-bottom:8px;">
                                                <input type="checkbox" id="dgOcupGrouped" checked>
                                                Agrupar
                                            </label>
                                            <label class="dg-popover-label">Estado de minas visible</label>
                                            <div class="dg-ocup-filter-checks" style="margin-top:0;">
                                                <label class="dg-ocup-filter-option">
                                                    <input type="checkbox" id="dgOcupShowHabilitado" checked>
                                                    <span>Habilitados</span>
                                                </label>
                                                <label class="dg-ocup-filter-option">
                                                    <input type="checkbox" id="dgOcupShowProceso" checked>
                                                    <span>En proceso</span>
                                                </label>
                                            </div>
                                            <label class="dg-popover-label">Minas visibles en filtro</label>
                                            <div class="dg-ocup-filter-checks" id="dgOcupMineChecks">
                                                @foreach(($catalogMinas ?? []) as $catalogMina)
                                                    <label class="dg-ocup-filter-option">
                                                        <input type="checkbox" class="js-ocup-mine-check" value="{{ $catalogMina }}" checked>
                                                        <span>{{ $catalogMina }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            <label class="dg-popover-label" style="margin-top:10px;">Oficinas visibles en filtro</label>
                                            <div class="dg-ocup-filter-checks" id="dgOcupOfficeChecks">
                                                @foreach(($catalogOficinas ?? []) as $catalogOficina)
                                                    <label class="dg-ocup-filter-option">
                                                        <input type="checkbox" class="js-ocup-office-check" value="{{ $catalogOficina }}">
                                                        <span>{{ $catalogOficina }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            <label class="dg-popover-label" style="margin-top:10px;">Talleres visibles en filtro</label>
                                            <div class="dg-ocup-filter-checks" id="dgOcupWorkshopChecks">
                                                @foreach(($catalogTalleres ?? []) as $catalogTaller)
                                                    <label class="dg-ocup-filter-option">
                                                        <input type="checkbox" class="js-ocup-workshop-check" value="{{ $catalogTaller }}">
                                                        <span>{{ $catalogTaller }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                <th data-column="acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($trabajadores as $trabajador)
                                @php
                                    $estadoRaw = strtoupper((string) ($trabajador['estado'] ?? 'ACTIVO'));
                                    $estadoText = match ($estadoRaw) {
                                        'CESADO' => 'Cesado',
                                        'INACTIVO' => 'Inactivo',
                                        'FALTA_CONTRATO' => 'Falta contrato',
                                        default => 'Activo',
                                    };
                                    $situacionKey = (string) ($trabajador['situacion'] ?? 'habilitado');
                                    $situacionLabel = (string) ($trabajador['situacion_label'] ?? 'Habilitado');
                                    $estadoClass = match (mb_strtolower($estadoText)) {
                                        'activo' => 'dg-pill-estado-activo',
                                        'falta contrato' => 'dg-pill-estado-contrato',
                                        'inactivo' => 'dg-pill-estado-inactivo',
                                        'cesado' => 'dg-pill-estado-cesado',
                                        default => 'dg-pill-neutral',
                                    };

                                    $contratoText = trim((string) ($trabajador['tipo_contrato'] ?? '-'));
                                    $contratoLower = mb_strtolower($contratoText);
                                    $contratoClass = 'dg-pill-neutral';
                                    if ($contratoText !== '-' && $contratoText !== '') {
                                        if (str_contains($contratoLower, 'indef') || str_contains($contratoLower, 'planilla') || str_contains($contratoLower, 'fijo')) {
                                            $contratoClass = 'dg-pill-contrato-indefinido';
                                        } elseif (str_contains($contratoLower, 'temporal') || str_contains($contratoLower, 'plazo') || str_contains($contratoLower, 'eventual')) {
                                            $contratoClass = 'dg-pill-contrato-temporal';
                                        } elseif (str_contains($contratoLower, 'servicio') || str_contains($contratoLower, 'locaci') || str_contains($contratoLower, 'tercer')) {
                                            $contratoClass = 'dg-pill-contrato-servicio';
                                        } elseif (str_contains($contratoLower, 'practic') || str_contains($contratoLower, 'intern')) {
                                            $contratoClass = 'dg-pill-contrato-practicante';
                                        }
                                    }

                                    $situacionClass = match ($situacionKey) {
                                        'parada' => 'dg-pill-situacion-parada',
                                        'oficina', 'taller', 'habilitado' => 'dg-pill-situacion-activo',
                                        'no_habilitado' => 'dg-pill-situacion-bloqueo',
                                        'vacaciones' => 'dg-pill-situacion-vacaciones',
                                        'revisar_ficha', 'ficha_observada' => 'dg-pill-situacion-revision',
                                        'descanso_medico' => 'dg-pill-situacion-descanso',
                                        'gestacion' => 'dg-pill-situacion-gestacion',
                                        'terminar_ficha' => 'dg-pill-situacion-inactivo',
                                        default => 'dg-pill-situacion-inactivo',
                                    };

                                    $ocupAll = $trabajador['minas'] ?? [];
                                    $ocupMinas = array_values(array_filter($ocupAll, function ($item) {
                                        $lower = mb_strtolower((string) $item);
                                        return !str_contains($lower, 'oficina') && !str_contains($lower, 'taller');
                                    }));
                                    $ocupOficinas = array_values(array_filter($ocupAll, fn ($item) => str_contains(mb_strtolower((string) $item), 'oficina')));
                                    $ocupTalleres = array_values(array_filter($ocupAll, fn ($item) => str_contains(mb_strtolower((string) $item), 'taller')));

                                    $ocupPrincipal = $ocupAll[0] ?? null;
                                    $documentDisplay = trim((string) (($trabajador['tipo_documento'] ?? 'DNI') . ' ' . ($trabajador['numero_documento'] ?? $trabajador['dni'] ?? '')));
                                @endphp
                                <tr class="js-person-row {{ !$trabajador['activo'] ? 'inactive' : '' }}"
                                    style="cursor:pointer;"
                                    data-worker='@json($trabajador)'
                                    data-nombre="{{ $trabajador['nombre'] ?? '' }}"
                                    data-dni="{{ $trabajador['numero_documento'] ?? $trabajador['dni'] ?? '' }}"
                                    data-puesto="{{ $trabajador['puesto'] ?? '' }}"
                                    data-correo="{{ $trabajador['correo'] ?? ($trabajador['email'] ?? '') }}"
                                    data-telefono="{{ $trabajador['telefono'] ?? '' }}"
                                    data-telefono-1="{{ $trabajador['telefono_1'] ?? '' }}"
                                    data-telefono-2="{{ $trabajador['telefono_2'] ?? '' }}"
                                    data-fecha-ingreso="{{ $trabajador['fecha_ingreso'] ?? '' }}"
                                    data-contrato="{{ $trabajador['tipo_contrato'] ?? '' }}"
                                    data-minas="{{ implode(' ', $trabajador['minas'] ?? []) }}"
                                    data-estado="{{ $trabajador['estado_actual'] ?? mb_strtolower($estadoText) }}"
                                    data-bienestar="{{ $situacionKey }}"
                                    data-ocup-minas="{{ implode(' ', $ocupMinas) }}"
                                    data-ocup-minas-list="{{ implode('||', $ocupMinas) }}"
                                    data-ocup-oficina="{{ implode(' ', $ocupOficinas) }}"
                                    data-ocup-taller="{{ implode(' ', $ocupTalleres) }}"
                                    onclick="showWorkerDetail(this)">
                                    <td data-column="trabajador">
                                        <span class="personal-worker-name">{{ $trabajador['nombre'] ?? 'Sin nombre' }}</span>
                                    </td>
                                    <td data-column="documento">{{ $documentDisplay !== '' ? $documentDisplay : '-' }}</td>
                                    <td data-column="celular">{{ $trabajador['telefono'] ?? ($trabajador['telefono_1'] ?? '-') }}</td>
                                    <td data-column="correo">{{ $trabajador['correo'] ?? ($trabajador['email'] ?? '-') }}</td>
                                    <td data-column="puesto">{{ $trabajador['puesto'] ?? '-' }}</td>
                                    <td data-column="contrato"><span class="dg-pill {{ $contratoClass }}">{{ $contratoText !== '' ? $contratoText : '-' }}</span></td>
                                    <td data-column="estado">
                                        @if($canEditContractData && $estadoRaw === 'FALTA_CONTRATO')
                                            <a
                                                href="{{ route('personal.contrato-datos.edit', $trabajador['id'] ?? '') }}"
                                                class="dg-pill dg-pill-button {{ $estadoClass }}"
                                                title="Editar datos de contrato"
                                                onclick="event.stopPropagation();">
                                                {{ $estadoText }}
                                            </a>
                                        @elseif($estadoRaw === 'CESADO')
                                            <button
                                                type="button"
                                                class="dg-pill dg-pill-button {{ $estadoClass }}"
                                                onclick="event.stopPropagation(); showCeaseReasonFromRow(this.closest('tr'))">
                                                {{ $estadoText }}
                                            </button>
                                        @else
                                            <span class="dg-pill {{ $estadoClass }}">{{ $estadoText }}</span>
                                        @endif
                                    </td>
                                    <td data-column="situacion">
                                        <span class="dg-pill {{ $situacionClass }}">{{ $situacionLabel }}</span>
                                    </td>
                                    <td data-column="ocupacion">
                                        @if(count($ocupAll) > 0)
                                            <div class="dg-ocup-flat">
                                                @foreach(($catalogMinas ?? []) as $ocup)
                                                    @php
                                                        $hasOcup = in_array($ocup, $ocupMinas, true);
                                                        $ocupState = (string) (($trabajador['minas_estado'][$ocup] ?? 'habilitado'));
                                                        $ocupClass = !$hasOcup
                                                            ? 'dg-ocup-cell-empty'
                                                            : match ($ocupState) {
                                                                'proceso' => 'dg-ocup-cell-mina-proceso',
                                                                'no_habilitado' => 'dg-ocup-cell-mina-no-habilitado',
                                                                default => 'dg-ocup-cell-mina',
                                                            };
                                                        $ocupPrimaryClass = $ocup === $ocupPrincipal && $hasOcup ? ' dg-pill-ocup-primary' : '';
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        class="dg-ocup-chip-btn"
                                                        data-ocup-item-key="{{ $ocup }}"
                                                        data-ocup-item-category="mina"
                                                        data-ocup-item-state="{{ $hasOcup ? $ocupState : 'no_habilitado' }}"
                                                        data-ocup-filter-type="ocupMina"
                                                        data-ocup-filter-value="{{ $ocup }}"
                                                        title="Filtrar por {{ $ocup }}"
                                                        onclick="event.stopPropagation();">
                                                        <span class="dg-ocup-cell {{ $ocupClass }}{{ $ocupPrimaryClass }}">{{ $ocup }}</span>
                                                    </button>
                                                @endforeach
                                                @foreach(($catalogOficinas ?? []) as $ocup)
                                                    @php
                                                        $hasOcup = in_array($ocup, $ocupOficinas, true);
                                                        $ocupClass = $hasOcup ? 'dg-ocup-cell-oficina' : 'dg-ocup-cell-empty';
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        class="dg-ocup-chip-btn"
                                                        data-ocup-item-key="{{ $ocup }}"
                                                        data-ocup-item-category="oficina"
                                                        data-ocup-filter-type="ocupOffice"
                                                        data-ocup-filter-value="{{ $ocup }}"
                                                        title="Filtrar por {{ $ocup }}"
                                                        onclick="event.stopPropagation();">
                                                        <span class="dg-ocup-cell {{ $ocupClass }}">{{ $ocup }}</span>
                                                    </button>
                                                @endforeach
                                                @foreach(($catalogTalleres ?? []) as $ocup)
                                                    @php
                                                        $hasOcup = in_array($ocup, $ocupTalleres, true);
                                                        $ocupClass = $hasOcup ? 'dg-ocup-cell-taller' : 'dg-ocup-cell-empty';
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        class="dg-ocup-chip-btn"
                                                        data-ocup-item-key="{{ $ocup }}"
                                                        data-ocup-item-category="taller"
                                                        data-ocup-filter-type="ocupWorkshop"
                                                        data-ocup-filter-value="{{ $ocup }}"
                                                        title="Filtrar por {{ $ocup }}"
                                                        onclick="event.stopPropagation();">
                                                        <span class="dg-ocup-cell {{ $ocupClass }}">{{ $ocup }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                            <div class="dg-ocup-board">
                                                <div class="dg-ocup-row" data-ocup-section="mina">
                                                    <span class="dg-ocup-label">Minas</span>
                                                    <div class="dg-ocup-values">
                                                        @forelse($ocupMinas as $ocup)
                                                            @php
                                                                $ocupState = (string) (($trabajador['minas_estado'][$ocup] ?? 'habilitado'));
                                                                $ocupClass = match ($ocupState) {
                                                                    'proceso' => 'dg-pill-ocup-mina-proceso',
                                                                    'no_habilitado' => 'dg-pill-ocup-mina-no-habilitado',
                                                                    default => 'dg-pill-ocup-mina',
                                                                };
                                                                $ocupPrimaryClass = $ocup === $ocupPrincipal ? ' dg-pill-ocup-primary' : '';
                                                            @endphp
                                                            <button
                                                                type="button"
                                                                class="dg-ocup-chip-btn"
                                                                data-ocup-item-key="{{ $ocup }}"
                                                                data-ocup-item-category="mina"
                                                                data-ocup-item-state="{{ $ocupState }}"
                                                                data-ocup-filter-type="ocupMina"
                                                                data-ocup-filter-value="{{ $ocup }}"
                                                                title="Filtrar por {{ $ocup }}"
                                                                onclick="event.stopPropagation();">
                                                                <span class="dg-pill {{ $ocupClass }}{{ $ocupPrimaryClass }}">{{ $ocup }}</span>
                                                            </button>
                                                        @empty
                                                            <span class="dg-ocup-empty">-</span>
                                                        @endforelse
                                                    </div>
                                                </div>
                                                <div class="dg-ocup-row" data-ocup-section="oficina">
                                                    <span class="dg-ocup-label">Oficinas</span>
                                                    <div class="dg-ocup-values">
                                                        @forelse($ocupOficinas as $ocup)
                                                            <button
                                                                type="button"
                                                                class="dg-ocup-chip-btn"
                                                                data-ocup-item-key="{{ $ocup }}"
                                                                data-ocup-item-category="oficina"
                                                                data-ocup-filter-type="ocupOffice"
                                                                data-ocup-filter-value="{{ $ocup }}"
                                                                title="Filtrar por {{ $ocup }}"
                                                                onclick="event.stopPropagation();">
                                                                <span class="dg-pill dg-pill-ocup-oficina">{{ $ocup }}</span>
                                                            </button>
                                                        @empty
                                                            <span class="dg-ocup-empty">-</span>
                                                        @endforelse
                                                    </div>
                                                </div>
                                                <div class="dg-ocup-row" data-ocup-section="taller">
                                                    <span class="dg-ocup-label">Talleres</span>
                                                    <div class="dg-ocup-values">
                                                        @forelse($ocupTalleres as $ocup)
                                                            <button
                                                                type="button"
                                                                class="dg-ocup-chip-btn"
                                                                data-ocup-item-key="{{ $ocup }}"
                                                                data-ocup-item-category="taller"
                                                                data-ocup-filter-type="ocupWorkshop"
                                                                data-ocup-filter-value="{{ $ocup }}"
                                                                title="Filtrar por {{ $ocup }}"
                                                                onclick="event.stopPropagation();">
                                                                <span class="dg-pill dg-pill-ocup-taller">{{ $ocup }}</span>
                                                            </button>
                                                        @empty
                                                            <span class="dg-ocup-empty">-</span>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <span class="dg-pill dg-pill-neutral">-</span>
                                        @endif
                                    </td>
                                    <td data-column="acciones" onclick="event.stopPropagation()">
                                        <div class="personal-action-buttons">
                                            <a
                                                href="{{ route('personal.edit', $trabajador['id'] ?? '') }}"
                                                class="btn btn-outline btn-xs personal-icon-btn"
                                                title="Editar trabajador"
                                                aria-label="Editar trabajador">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M12 20h9"/>
                                                    <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                                                </svg>
                                            </a>
                                            @if(!empty($trabajador['ficha_id']))
                                                <a
                                                    href="{{ route('personal.fichas.review', $trabajador['ficha_id']) }}"
                                                    class="btn btn-outline btn-xs personal-icon-btn"
                                                    title="Ver ficha"
                                                    aria-label="Ver ficha">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                        <path d="M14 2v6h6"/>
                                                        <path d="M16 13H8"/>
                                                        <path d="M16 17H8"/>
                                                        <path d="M10 9H8"/>
                                                    </svg>
                                                </a>
                                            @endif
                                            <a
                                                href="{{ route('personal.documentos.index', $trabajador['id'] ?? '') }}"
                                                class="btn btn-outline btn-xs personal-icon-btn"
                                                title="Ver documentos"
                                                aria-label="Ver documentos">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="M8 2v4"/>
                                                    <path d="M16 2v4"/>
                                                    <path d="M8 11h8"/>
                                                    <path d="M8 15h5"/>
                                                </svg>
                                            </a>
                                            @if($canEditContractData && $estadoRaw === 'FALTA_CONTRATO')
                                                <a
                                                    href="{{ route('personal.contrato-datos.edit', $trabajador['id'] ?? '') }}"
                                                    class="btn btn-outline btn-xs personal-icon-btn"
                                                    title="Datos de contrato"
                                                    aria-label="Datos de contrato">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                        <path d="M14 2v6h6"/>
                                                        <path d="M8 13h8"/>
                                                        <path d="M8 17h5"/>
                                                    </svg>
                                                </a>
                                                @if($canUpdatePersonal && !empty($trabajador['contrato_datos']) && empty($trabajador['contrato_firmado']))
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline btn-xs personal-icon-btn"
                                                        title="Subir contrato firmado"
                                                        aria-label="Subir contrato firmado"
                                                        onclick="event.stopPropagation(); openSignedContractModal(this.closest('tr'))">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M20 6 9 17l-5-5"/>
                                                        </svg>
                                                    </button>
                                                @endif
                                            @endif
                                            @if($estadoRaw === 'CESADO')
                                                <button
                                                    type="button"
                                                    class="btn btn-outline btn-xs personal-icon-btn"
                                                    title="Ver motivo de cese"
                                                    aria-label="Ver motivo de cese"
                                                    onclick="event.stopPropagation(); showCeaseReasonFromRow(this.closest('tr'))">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <path d="M12 16v-4"/>
                                                        <path d="M12 8h.01"/>
                                                    </svg>
                                                </button>
                                                @if($canCeasePersonal && !empty($trabajador['puede_activar']))
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline btn-xs personal-icon-btn"
                                                        title="Activar trabajador"
                                                        aria-label="Activar trabajador"
                                                        onclick="event.stopPropagation(); openActivateWorkerFromRow(this.closest('tr'))">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                                            <circle cx="9" cy="7" r="4"/>
                                                            <path d="M19 8v6"/>
                                                            <path d="M22 11h-6"/>
                                                        </svg>
                                                    </button>
                                                @endif
                                            @elseif($canCeasePersonal && !empty($trabajador['puede_cesar']))
                                                <form method="POST" action="{{ route('personal.cease', $trabajador['id'] ?? '') }}" data-worker-name="{{ $trabajador['nombre'] ?? 'este trabajador' }}" onsubmit="return requestCeaseReason(this);">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline btn-xs personal-icon-btn"
                                                        title="Cesar trabajador"
                                                        aria-label="Cesar trabajador">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="12" cy="12" r="9"/>
                                                            <path d="M8 8l8 8"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            <button
                                                type="button"
                                                class="btn btn-outline btn-xs personal-icon-btn"
                                                title="Mostrar detalle"
                                                aria-label="Mostrar detalle"
                                                onclick="event.stopPropagation(); showWorkerDetail(this.closest('tr'))">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                                    <circle cx="12" cy="12" r="3"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        </table>
                    </div>
                </div>

                <div class="personal-pagination-controls">
                    <div class="personal-page-size">
                        Mostrar
                        <select id="personalPageSize" class="personal-page-size-select">
                        </select>
                        trabajadores
                    </div>
                    <div class="personal-pagination-info" id="personalPaginationInfo"></div>
                </div>

                <div class="personal-pagination" id="personalPagination"></div>
            @endif
        </div>
    </div>

    <div id="workerDetailModal" class="modal" style="display:none;" onclick="if (event.target === this) closeWorkerDetailModal()">
        <div class="modal-backdrop" onclick="closeWorkerDetailModal()"></div>
        <div class="modal-content"></div>
    </div>

    <div id="ceaseReasonModal" class="modal" style="display:none;" onclick="if (event.target === this) closeCeaseReasonModal()">
        <div class="modal-backdrop" onclick="closeCeaseReasonModal()"></div>
        <div class="modal-content personal-cease-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Cesar trabajador</h2>
                    <p class="modal-subtitle" id="ceaseReasonSubtitle">Indica el motivo de cese.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeCeaseReasonModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <label class="ficha-label" for="ceaseReasonTextarea">Motivo de cese <span class="ficha-required">*</span></label>
                <textarea id="ceaseReasonTextarea" class="ficha-input personal-cease-textarea" maxlength="2000" placeholder="Escribe el motivo de cese"></textarea>
                <div id="ceaseReasonError" class="personal-cease-error">El motivo de cese es obligatorio.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCeaseReasonModal()">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="submitCeaseReason()">Cesar</button>
            </div>
        </div>
    </div>

    <div id="ceaseReasonViewModal" class="modal" style="display:none;" onclick="if (event.target === this) closeModal('ceaseReasonViewModal')">
        <div class="modal-backdrop" onclick="closeModal('ceaseReasonViewModal')"></div>
        <div class="modal-content personal-cease-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Motivo de cese</h2>
                    <p class="modal-subtitle" id="ceaseReasonViewSubtitle">Detalle registrado.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('ceaseReasonViewModal')" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <div class="personal-cease-view">
                    <p class="personal-cease-view-name" id="ceaseReasonViewName"></p>
                    <div class="personal-cease-view-meta">
                        <span>Cesado por</span>
                        <span id="ceaseReasonViewUser">-</span>
                    </div>
                    <p class="personal-cease-view-reason" id="ceaseReasonViewText">Motivo no registrado</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('ceaseReasonViewModal')">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="activateWorkerModal" class="modal" style="display:none;" onclick="if (event.target === this) closeActivateWorkerModal()">
        <div class="modal-backdrop" onclick="closeActivateWorkerModal()"></div>
        <form id="activateWorkerForm" method="POST" action="" class="modal-content personal-cease-modal">
            @csrf
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Activar trabajador</h2>
                    <p class="modal-subtitle" id="activateWorkerSubtitle">Se creara un nuevo contrato para el trabajador.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeActivateWorkerModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <div class="personal-cease-view" style="margin-bottom:12px;">
                    <p class="personal-cease-view-name" id="activateWorkerName"></p>
                    <p class="personal-cease-view-reason" id="activateWorkerReason">Los datos actuales se usaran como base para el siguiente contrato y podran editarse despues.</p>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px;">
                    <div>
                        <label class="ficha-label" for="activateFechaInicio">Fecha de inicio <span class="ficha-required">*</span></label>
                        <input id="activateFechaInicio" class="ficha-input" type="date" name="fecha_inicio" value="{{ now()->toDateString() }}" required>
                    </div>
                    <div>
                        <label class="ficha-label" for="activateFechaFin">Fecha de fin</label>
                        <input id="activateFechaFin" class="ficha-input" type="date" name="fecha_fin">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeActivateWorkerModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="white-space:nowrap; min-width:160px;">Activar trabajador</button>
            </div>
        </form>
    </div>

    <div id="signedContractModal" class="modal" style="display:none;" onclick="if (event.target === this) closeSignedContractModal()">
        <div class="modal-backdrop" onclick="closeSignedContractModal()"></div>
        <form id="signedContractForm" method="POST" action="" enctype="multipart/form-data" class="modal-content personal-cease-modal">
            @csrf
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Contrato firmado</h2>
                    <p class="modal-subtitle" id="signedContractSubtitle">Sube el contrato firmado en PDF.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeSignedContractModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <label class="ficha-label" for="signedContractPdf">Contrato PDF <span class="ficha-required">*</span></label>
                <input id="signedContractPdf" class="ficha-input" type="file" name="contrato_pdf" accept="application/pdf,.pdf" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeSignedContractModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Subir contrato</button>
            </div>
        </form>
    </div>
</div>

@if($canDownloadContractFormats)
<div id="contractFormatModal" class="modal" style="display:none;" onclick="if (event.target === this) closeContractFormatModal()">
    <div class="modal-backdrop" onclick="closeContractFormatModal()"></div>
    <div id="contractFormatContent" class="modal-content personal-contract-modal">
        <div class="modal-header">
            <div>
                <h2 class="modal-title">Descargar formato de contrato</h2>
                <p class="modal-subtitle" id="contractFormatSubtitle">Primero escoge el formato que quieres descargar.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeContractFormatModal()" aria-label="Cerrar">X</button>
        </div>
        <div class="modal-body">
            <section id="contractFormatStepTemplates" class="contract-step is-active">
                <div>
                    <label class="ficha-label">Escoge un formato</label>
                    <p class="ficha-card-subtitle" style="margin:4px 0 0;">Selecciona una plantilla para continuar.</p>
                </div>
                <div id="contractFormatTemplateList" class="contract-format-template-grid">
                    <div class="contract-preview-empty">Cargando formatos...</div>
                </div>
            </section>
            <section id="contractFormatStepWorkers" class="contract-step">
                <div class="contract-step-header">
                    <div>
                        <label class="ficha-label">Formato seleccionado</label>
                        <div id="contractSelectedTemplateLabel" class="contract-selected-template">-</div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="showContractFormatStep('templates')">Cambiar formato</button>
                </div>
                <div class="contract-worker-picker">
                    <div>
                        <label class="ficha-label" for="contractWorkerSearch">Seleccionar personal</label>
                        <input id="contractWorkerSearch" class="ficha-input" type="search" autocomplete="off" placeholder="Nombre, DNI o puesto">
                        <div id="contractWorkerSearchResults" class="contract-search-results"></div>
                    </div>
                    <div>
                        <label class="ficha-label">Personal seleccionado</label>
                        <div id="contractSelectedWorkers" class="contract-selected-workers"></div>
                    </div>
                </div>
                <div class="contract-preview-section">
                    <label class="ficha-label">Vista previa del Excel</label>
                    <div id="contractPreviewWrap" class="contract-preview-wrap">
                        <div class="contract-preview-empty">Selecciona al menos un trabajador.</div>
                    </div>
                </div>
            </section>
        </div>
        <form id="contractFormatDownloadForm" method="POST" action="{{ route('personal.contrato-formatos.download') }}">
            @csrf
            <input type="hidden" name="template_id" id="contractDownloadTemplateId">
            <div id="contractDownloadWorkerInputs"></div>
        </form>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeContractFormatModal()">Cancelar</button>
            <button type="button" class="btn btn-outline" id="contractFormatBackButton" onclick="showContractFormatStep('templates')" style="display:none;">Volver</button>
            <button type="submit" form="contractFormatDownloadForm" class="btn btn-primary" id="contractFormatDownloadButton" style="display:none;" disabled>Descargar Excel</button>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
let pendingCeaseForm = null;
const todayForActivation = @json(now()->toDateString());
const personalCsrfToken = @json(csrf_token());
const signedContractRouteTemplate = @json(route('personal.contrato-datos.signed', '__ID__'));
const canDownloadContractFormats = @json($canDownloadContractFormats);
const contractFormatEndpoints = {
    templates: @json(route('personal.contrato-formatos.templates')),
    workers: @json(route('personal.contrato-formatos.personal')),
    preview: @json(route('personal.contrato-formatos.preview')),
};
let contractFormatTemplates = [];
let contractFormatTemplateId = '';
let contractFormatCurrentStep = 'templates';
let contractFormatSelectedWorkers = new Map();
let contractFormatSearchTimer = null;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
    });
}

function contractWorkerSummary(worker) {
    return {
        id: String(worker?.id || ''),
        nombre: String(worker?.nombre || worker?.nombre_completo || 'Trabajador').trim(),
        documento: String(worker?.documento || worker?.numero_documento || worker?.dni || '').trim(),
        puesto: String(worker?.puesto || '').trim(),
        correo: String(worker?.correo || worker?.email || '').trim(),
    };
}

async function openContractFormatModal(worker) {
    if (!canDownloadContractFormats) return;

    contractFormatSelectedWorkers = new Map();
    contractFormatTemplateId = '';
    const initialWorker = contractWorkerSummary(worker || {});
    if (initialWorker.id) {
        contractFormatSelectedWorkers.set(initialWorker.id, initialWorker);
    }

    const search = document.getElementById('contractWorkerSearch');
    if (search) {
        search.value = '';
    }
    renderContractWorkerResults([]);
    renderContractSelectedWorkers();
    showContractFormatStep('templates');
    updateContractDownloadForm();
    openModal('contractFormatModal');
    await loadContractFormatTemplates();
    renderContractFormatTemplates();
}

function closeContractFormatModal() {
    closeModal('contractFormatModal');
}

function showContractFormatStep(step) {
    contractFormatCurrentStep = step === 'workers' ? 'workers' : 'templates';
    const templatesStep = document.getElementById('contractFormatStepTemplates');
    const workersStep = document.getElementById('contractFormatStepWorkers');
    const subtitle = document.getElementById('contractFormatSubtitle');
    const backButton = document.getElementById('contractFormatBackButton');
    const downloadButton = document.getElementById('contractFormatDownloadButton');
    const content = document.getElementById('contractFormatContent');

    templatesStep?.classList.toggle('is-active', contractFormatCurrentStep === 'templates');
    workersStep?.classList.toggle('is-active', contractFormatCurrentStep === 'workers');
    content?.classList.toggle('is-preview', contractFormatCurrentStep === 'workers');

    if (subtitle) {
        subtitle.textContent = contractFormatCurrentStep === 'templates'
            ? 'Primero escoge el formato que quieres descargar.'
            : 'Selecciona el personal y revisa la vista previa antes de descargar.';
    }
    if (backButton) {
        backButton.style.display = contractFormatCurrentStep === 'workers' ? 'inline-flex' : 'none';
    }
    if (downloadButton) {
        downloadButton.style.display = contractFormatCurrentStep === 'workers' ? 'inline-flex' : 'none';
    }

    updateContractDownloadForm();
}

async function loadContractFormatTemplates() {
    if (contractFormatTemplates.length > 0) {
        renderContractFormatTemplates();
        return;
    }

    const list = document.getElementById('contractFormatTemplateList');
    if (list) {
        list.innerHTML = '<div class="contract-preview-empty">Cargando formatos...</div>';
    }

    try {
        const response = await fetch(contractFormatEndpoints.templates, {
            headers: {'Accept': 'application/json'},
        });
        const data = await response.json();
        contractFormatTemplates = Array.isArray(data.templates) ? data.templates : [];
    } catch (error) {
        contractFormatTemplates = [];
    }

    renderContractFormatTemplates();
}

function renderContractFormatTemplates() {
    const list = document.getElementById('contractFormatTemplateList');
    if (!list) return;

    if (contractFormatTemplates.length === 0) {
        list.innerHTML = '<div class="contract-preview-empty">No se encontraron formatos disponibles.</div>';
        return;
    }

    list.innerHTML = contractFormatTemplates.map(function (template) {
        const selectedClass = template.id === contractFormatTemplateId ? ' is-selected' : '';
        return `
            <button type="button" class="contract-format-template${selectedClass}" data-contract-template-id="${escapeHtml(template.id)}">
                <span>
                    <strong>${escapeHtml(template.title || template.label || 'Formato')}</strong>
                    <span>${escapeHtml(template.date || '')}</span>
                </span>
                <span class="contract-format-template-pill">${escapeHtml((template.columns || []).length)} col.</span>
            </button>
        `;
    }).join('');

    list.querySelectorAll('[data-contract-template-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            selectContractFormatTemplate(button.dataset.contractTemplateId || '');
        });
    });
}

function selectContractFormatTemplate(templateId) {
    contractFormatTemplateId = templateId;
    const hidden = document.getElementById('contractDownloadTemplateId');
    const label = document.getElementById('contractSelectedTemplateLabel');
    const template = contractFormatTemplates.find(function (item) {
        return item.id === templateId;
    });
    if (hidden) {
        hidden.value = templateId;
    }
    if (label) {
        label.innerHTML = template
            ? '<strong>' + escapeHtml(template.title || template.label || 'Formato') + '</strong><span>' + escapeHtml((template.date || '') + ' · ' + ((template.columns || []).length) + ' columnas') + '</span>'
            : '<strong>Formato seleccionado</strong>';
    }
    renderContractFormatTemplates();
    showContractFormatStep('workers');
    refreshContractPreview();
}

function renderContractSelectedWorkers() {
    const target = document.getElementById('contractSelectedWorkers');
    if (!target) return;

    const workers = Array.from(contractFormatSelectedWorkers.values());
    if (workers.length === 0) {
        target.innerHTML = '<div class="contract-preview-empty" style="padding:8px 0;">Sin personal seleccionado.</div>';
        updateContractDownloadForm();
        return;
    }

    target.innerHTML = workers.map(function (worker) {
        return `
            <div class="contract-worker-chip">
                <div>
                    <strong>${escapeHtml(worker.nombre)}</strong>
                    <span>${escapeHtml(worker.documento || '-')} · ${escapeHtml(worker.puesto || '-')}</span>
                </div>
                <button type="button" aria-label="Quitar trabajador" data-remove-contract-worker="${escapeHtml(worker.id)}">X</button>
            </div>
        `;
    }).join('');

    target.querySelectorAll('[data-remove-contract-worker]').forEach(function (button) {
        button.addEventListener('click', function () {
            contractFormatSelectedWorkers.delete(button.dataset.removeContractWorker || '');
            renderContractSelectedWorkers();
            refreshContractPreview();
        });
    });

    updateContractDownloadForm();
}

function addContractWorker(worker) {
    const summary = contractWorkerSummary(worker);
    if (!summary.id) return;

    contractFormatSelectedWorkers.set(summary.id, summary);
    renderContractSelectedWorkers();
    refreshContractPreview();
}

function renderContractWorkerResults(workers) {
    const target = document.getElementById('contractWorkerSearchResults');
    if (!target) return;

    if (!Array.isArray(workers) || workers.length === 0) {
        target.style.display = 'none';
        target.innerHTML = '';
        return;
    }

    target.style.display = 'block';
    target.innerHTML = workers.map(function (worker) {
        return `
            <button type="button" class="contract-search-result" data-worker-payload="${escapeHtml(JSON.stringify(worker))}">
                <strong>${escapeHtml(worker.nombre || 'Trabajador')}</strong>
                <span>${escapeHtml(worker.documento || '-')} · ${escapeHtml(worker.puesto || '-')}</span>
            </button>
        `;
    }).join('');

    target.querySelectorAll('.contract-search-result').forEach(function (button) {
        button.addEventListener('click', function () {
            try {
                addContractWorker(JSON.parse(button.dataset.workerPayload || '{}'));
            } catch (error) {
                // noop
            }
            const search = document.getElementById('contractWorkerSearch');
            if (search) {
                search.value = '';
                search.focus();
            }
            renderContractWorkerResults([]);
        });
    });
}

async function searchContractWorkers(query) {
    const value = String(query || '').trim();
    if (value.length < 2) {
        renderContractWorkerResults([]);
        return;
    }

    try {
        const url = contractFormatEndpoints.workers + '?q=' + encodeURIComponent(value);
        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
        const data = await response.json();
        renderContractWorkerResults(data.workers || []);
    } catch (error) {
        renderContractWorkerResults([]);
    }
}

async function refreshContractPreview() {
    const wrap = document.getElementById('contractPreviewWrap');
    const workerIds = Array.from(contractFormatSelectedWorkers.keys());
    if (!wrap) return;

    if (!contractFormatTemplateId || workerIds.length === 0) {
        wrap.innerHTML = '<div class="contract-preview-empty">Selecciona un formato y al menos un trabajador.</div>';
        updateContractDownloadForm();
        return;
    }

    wrap.innerHTML = '<div class="contract-preview-empty">Actualizando vista previa...</div>';

    try {
        const response = await fetch(contractFormatEndpoints.preview, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': personalCsrfToken,
            },
            body: JSON.stringify({
                template_id: contractFormatTemplateId,
                personal_ids: workerIds,
            }),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'No se pudo generar la vista previa.');
        }

        (data.workers || []).forEach(function (worker) {
            const summary = contractWorkerSummary(worker);
            if (summary.id) {
                contractFormatSelectedWorkers.set(summary.id, summary);
            }
        });

        renderContractSelectedWorkers();
        renderContractPreviewTable(data.template?.columns || [], data.rows || []);
    } catch (error) {
        wrap.innerHTML = '<div class="contract-preview-empty">' + escapeHtml(error.message || 'No se pudo generar la vista previa.') + '</div>';
    }

    updateContractDownloadForm();
}

function renderContractPreviewTable(columns, rows) {
    const wrap = document.getElementById('contractPreviewWrap');
    if (!wrap) return;

    if (!columns.length) {
        wrap.innerHTML = '<div class="contract-preview-empty">El formato no tiene columnas reconocibles.</div>';
        return;
    }

    const bodyRows = rows.length > 0 ? rows : [columns.map(function () { return ''; })];
    wrap.innerHTML = `
        <table class="contract-preview-table">
            <thead>
                <tr>${columns.map(function (column) { return '<th>' + escapeHtml(column) + '</th>'; }).join('')}</tr>
            </thead>
            <tbody>
                ${bodyRows.map(function (row) {
                    return '<tr>' + columns.map(function (_column, index) {
                        return '<td title="' + escapeHtml(row[index] || '') + '">' + escapeHtml(row[index] || '') + '</td>';
                    }).join('') + '</tr>';
                }).join('')}
            </tbody>
        </table>
    `;
}

function updateContractDownloadForm() {
    const templateInput = document.getElementById('contractDownloadTemplateId');
    const idsTarget = document.getElementById('contractDownloadWorkerInputs');
    const button = document.getElementById('contractFormatDownloadButton');
    const workerIds = Array.from(contractFormatSelectedWorkers.keys());

    if (templateInput) {
        templateInput.value = contractFormatTemplateId;
    }
    if (idsTarget) {
        idsTarget.innerHTML = workerIds.map(function (id) {
            return '<input type="hidden" name="personal_ids[]" value="' + escapeHtml(id) + '">';
        }).join('');
    }
    if (button) {
        button.disabled = contractFormatCurrentStep !== 'workers' || !contractFormatTemplateId || workerIds.length === 0;
        button.style.display = contractFormatCurrentStep === 'workers' ? 'inline-flex' : 'none';
    }
}

function openSignedContractModal(row) {
    if (!row) return;

    let worker = {};
    try {
        worker = JSON.parse(row.dataset.worker || '{}');
    } catch (error) {
        worker = {};
    }

    const workerId = String(worker.id || row.dataset.id || '').trim();
    if (!workerId) return;

    const form = document.getElementById('signedContractForm');
    const subtitle = document.getElementById('signedContractSubtitle');
    const input = document.getElementById('signedContractPdf');
    const workerName = worker.nombre || worker.nombre_completo || 'este trabajador';

    if (form) {
        form.action = signedContractRouteTemplate.replace('__ID__', encodeURIComponent(workerId));
    }
    if (subtitle) {
        subtitle.textContent = 'Sube el contrato firmado en PDF para ' + workerName + '.';
    }
    if (input) {
        input.value = '';
    }

    openModal('signedContractModal');
    window.setTimeout(function () {
        input?.focus();
    }, 50);
}

function closeSignedContractModal() {
    const form = document.getElementById('signedContractForm');
    const input = document.getElementById('signedContractPdf');
    if (form) {
        form.action = '';
    }
    if (input) {
        input.value = '';
    }
    closeModal('signedContractModal');
}

function requestCeaseReason(form) {
    const existingInput = form.querySelector('input[name="motivo_cese"]');
    if (existingInput && existingInput.value.trim() !== '') {
        return true;
    }

    pendingCeaseForm = form;
    const row = form.closest('tr');
    let worker = {};
    try {
        worker = row ? JSON.parse(row.dataset.worker || '{}') : {};
    } catch (error) {
        worker = {};
    }

    const subtitle = document.getElementById('ceaseReasonSubtitle');
    const textarea = document.getElementById('ceaseReasonTextarea');
    const error = document.getElementById('ceaseReasonError');
    const workerName = worker.nombre || worker.nombre_completo || form.dataset.workerName || 'este trabajador';

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

function showCeaseReason(worker) {
    const reason = String(worker?.motivo_cese || '').trim() || 'Motivo no registrado';
    const name = String(worker?.nombre || worker?.nombre_completo || 'Trabajador').trim();
    const nameNode = document.getElementById('ceaseReasonViewName');
    const textNode = document.getElementById('ceaseReasonViewText');
    const subtitleNode = document.getElementById('ceaseReasonViewSubtitle');
    const userNode = document.getElementById('ceaseReasonViewUser');
    const userName = String(worker?.cesado_por_nombre || worker?.cesado_por?.nombre || '').trim()
        || (worker?.cese_automatico ? 'Sistema - termino de contrato' : 'No registrado');

    if (nameNode) {
        nameNode.textContent = name;
    }
    if (textNode) {
        textNode.textContent = reason;
    }
    if (subtitleNode) {
        subtitleNode.textContent = worker?.cese_automatico ? 'Cese automatico por contrato.' : 'Detalle registrado.';
    }
    if (userNode) {
        userNode.textContent = userName;
    }

    openModal('ceaseReasonViewModal');
}

function showCeaseReasonFromRow(row) {
    if (!row) return;
    try {
        showCeaseReason(JSON.parse(row.dataset.worker || '{}'));
    } catch (error) {
        showCeaseReason({motivo_cese: 'Motivo de cese no disponible.'});
    }
}

function openActivateWorker(worker) {
    if (!worker || !worker.id) {
        return;
    }

    const form = document.getElementById('activateWorkerForm');
    const nameNode = document.getElementById('activateWorkerName');
    const subtitleNode = document.getElementById('activateWorkerSubtitle');
    const reasonNode = document.getElementById('activateWorkerReason');
    const inicioInput = document.getElementById('activateFechaInicio');
    const finInput = document.getElementById('activateFechaFin');
    const workerName = String(worker.nombre || worker.nombre_completo || 'Trabajador').trim();
    const lastClosed = worker.ultimo_contrato_cerrado || null;

    if (form) {
        form.action = '/personal/' + encodeURIComponent(worker.id) + '/activar';
    }
    if (nameNode) {
        nameNode.textContent = workerName;
    }
    if (subtitleNode) {
        subtitleNode.textContent = 'Se creara el siguiente contrato laboral para ' + workerName + '.';
    }
    if (reasonNode) {
        const reason = String(lastClosed?.motivo_cese || worker.motivo_cese || '').trim();
        const previous = lastClosed?.numero ? 'Contrato anterior: ' + lastClosed.numero + '. ' : '';
        reasonNode.textContent = previous + (reason ? 'Ultimo cese: ' + reason : 'Los datos actuales se usaran como base para el siguiente contrato.');
    }
    if (inicioInput) {
        inicioInput.value = todayForActivation;
    }
    if (finInput) {
        finInput.value = '';
    }

    openModal('activateWorkerModal');
    window.setTimeout(function () {
        inicioInput?.focus();
    }, 50);
}

function openActivateWorkerFromRow(row) {
    if (!row) return;
    try {
        openActivateWorker(JSON.parse(row.dataset.worker || '{}'));
    } catch (error) {
        openActivateWorker({});
    }
}

function closeActivateWorkerModal() {
    closeModal('activateWorkerModal');
}

function showWorkerDetail(card) {
    document.querySelectorAll('.js-person-row.is-selected').forEach(function (node) {
        node.classList.remove('is-selected');
    });
    card.classList.add('is-selected');

    const worker = JSON.parse(card.dataset.worker || '{}');
    const modal = document.getElementById('workerDetailModal');
    const canCeasePersonal = @json($canCeasePersonal);
    const csrfToken = @json(csrf_token());
    if (!modal || !worker.nombre) return;

    const telefonoAttr = card.getAttribute('data-telefono') || '';
    const telefono1Attr = card.getAttribute('data-telefono-1') || '';
    const telefono2Attr = card.getAttribute('data-telefono-2') || '';
    const fechaIngresoAttr = card.getAttribute('data-fecha-ingreso') || '';

    const telefonoRaw = worker.telefono
        || telefonoAttr
        || [worker.telefono_1, worker.telefono_2, telefono1Attr, telefono2Attr].filter(Boolean).join(' / ')
        || '-';

    const fechaIngresoRaw = worker.fecha_ingreso || fechaIngresoAttr || null;

    const isCentroTrabajo = function(ubicacion) {
        const value = String(ubicacion || '').toLowerCase();
        return value.includes('taller') || value.includes('oficina');
    };

    let estadoClass = '';
    let estadoLabel = '';
    switch (worker.estado_actual) {
        case 'activo': estadoClass = 'status-active'; estadoLabel = 'Activo'; break;
        case 'cesado': estadoClass = 'status-inactive'; estadoLabel = 'Cesado'; break;
        case 'inactivo': estadoClass = 'status-inactive'; estadoLabel = 'Inactivo'; break;
        default: estadoClass = 'status-active'; estadoLabel = 'Activo';
    }

    const fechas = worker.fechas || {};
    const resumenBienestar = worker.resumen_bienestar || {};
    const situacionLabel = worker.situacion_label || 'Habilitado';
    const ingresoRaw = fechaIngresoRaw || fechas.ingreso || null;
    const ingreso = ingresoRaw ? new Date(ingresoRaw).toLocaleDateString('es-PE') : '-';
    const vacStr = resumenBienestar.vacaciones || 'Sin vacaciones próximas en los siguientes 2 meses. Disponible por ahora.';
    const descansoStr = resumenBienestar.descanso_medico || 'Sin descanso médico vigente. Estado de salud operativo.';
    const gestacionStr = resumenBienestar.gestacion || 'Sin periodo de gestacion registrado.';
    const parStr = resumenBienestar.parada || 'Sin parada vigente en este momento.';
    const telefono = telefonoRaw;
    const documento = [worker.tipo_documento || 'DNI', worker.numero_documento || worker.dni || '-'].join(' ').trim();
    const lastClosedContract = worker.ultimo_contrato_cerrado || null;
    const contractHistoryHtml = lastClosedContract ? `
                <div class="detail-section">
                    <h3 class="detail-section-title">Historial laboral</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Ultimo contrato cerrado</span>
                            <span class="detail-value">Contrato ${escapeHtml(lastClosedContract.numero || '-')} - ${escapeHtml(lastClosedContract.fecha_inicio_label || '-')} al ${escapeHtml(lastClosedContract.fecha_fin_label || '-')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Motivo anterior</span>
                            <span class="detail-value">${escapeHtml(lastClosedContract.motivo_cese || 'Motivo no registrado')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cesado por</span>
                            <span class="detail-value">${escapeHtml(lastClosedContract.cerrado_por_nombre || worker.cesado_por_nombre || 'No registrado')}</span>
                        </div>
                    </div>
                </div>
    ` : '';

    let minasHtml = '';
    let centrosHtml = '';
    if (worker.minas && worker.minas.length > 0) {
        worker.minas.forEach(function(mina) {
            const isMina = !isCentroTrabajo(mina);
            if (isMina) {
                const estado = (worker.minas_estado || {})[mina] || 'habilitado';
                const estadoMina = estado === 'proceso'
                    ? '<span class="badge badge-warning ml-2">En proceso</span>'
                    : (estado === 'no_habilitado'
                        ? '<span class="badge badge-danger ml-2">No habilitado</span>'
                        : '<span class="badge badge-success ml-2">Habilitado</span>');
                minasHtml += `<div class="detail-mina-item"><span>${mina}</span>${estadoMina}</div>`;
            } else {
                const etiqueta = String(mina || '').toLowerCase().includes('oficina') ? 'En oficina' : 'En taller';
                centrosHtml += `<div class="detail-mina-item"><span>${mina}</span><span class="badge badge-info ml-2">${etiqueta}</span></div>`;
            }
        });
    }

    const detailContent = `
        <div class="worker-detail-modal">
            <div class="detail-header" style="position:relative;">
                <button type="button" class="btn btn-outline" onclick="closeWorkerDetailModal()" style="position:absolute; top:12px; right:12px; width:32px; height:32px; padding:0; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; z-index:2;" aria-label="Cerrar">
                    ×
                </button>
                <div class="detail-avatar">${(worker.nombre || 'U').substring(0, 2).toUpperCase()}</div>
                <div class="detail-header-info">
                    <h2 class="detail-name">${worker.nombre || '-'}</h2>
                    <p class="detail-puesto">${worker.puesto || '-'}</p>
                    <span class="person-badge ${estadoClass}">${estadoLabel}</span>
                </div>
            </div>
            <div class="detail-body">
                <div class="detail-section">
                    <h3 class="detail-section-title">Información Personal</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Documento</span>
                            <span class="detail-value">${documento || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Teléfono</span>
                            <span class="detail-value">${telefono}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Fecha de Ingreso</span>
                            <span class="detail-value">${ingreso}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tipo de Contrato</span>
                            <span class="detail-value">${worker.tipo_contrato || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Supervisor</span>
                            <span class="detail-value">${worker.supervisor ? 'Sí' : 'No'}</span>
                        </div>
                    </div>
                </div>
                <div class="detail-section">
                    <h3 class="detail-section-title">Ubicación en Minas</h3>
                    <div class="detail-minas">${minasHtml || '<p class="text-muted">Sin minas asignadas</p>'}</div>
                </div>
                ${centrosHtml ? `
                <div class="detail-section">
                    <h3 class="detail-section-title">Oficina / Taller</h3>
                    <div class="detail-minas">${centrosHtml}</div>
                </div>` : ''}
                <div class="detail-section">
                    <h3 class="detail-section-title">Estado y Fechas</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Estado</span>
                            <span class="detail-value"><span class="person-badge ${estadoClass}">${estadoLabel}</span></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Situacion</span>
                            <span class="detail-value">${situacionLabel}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Vacaciones</span>
                            <span class="detail-value">${vacStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Descanso médico</span>
                            <span class="detail-value">${descansoStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Gestacion</span>
                            <span class="detail-value">${gestacionStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Parada</span>
                            <span class="detail-value">${parStr}</span>
                        </div>
                    </div>
                </div>
                ${contractHistoryHtml}
            </div>
            <div class="detail-footer">
                ${worker.ficha_id ? `<a href="/personal/fichas/${worker.ficha_id}/revisar" class="btn btn-outline">Ficha Colaborador</a>` : ''}
                <a href="/bienestar/${worker.id}?solo_calendario=1" class="btn btn-outline">Cartilla Ocupación</a>
                <a href="/personal/${worker.id}/documentos" class="btn btn-outline">Documentos</a>
                <a href="/personal/${worker.id}/contratos" class="btn btn-outline">Contratos</a>
                <a href="/personal/${worker.id}/editar" class="btn btn-primary">Editar Trabajador</a>
                ${worker.estado_actual === 'cesado' ? `<button type="button" class="btn btn-outline" data-cease-reason-btn>Ver motivo de cese</button>` : ''}
                ${worker.estado_actual === 'cesado' && canCeasePersonal ? `<button type="button" class="btn btn-primary" data-activate-worker-btn>Activar trabajador</button>` : ''}
                ${canCeasePersonal && worker.puede_cesar ? `<form method="POST" action="/personal/${worker.id}/cesar" data-worker-name="${escapeHtml(worker.nombre || worker.nombre_completo || 'este trabajador')}" onsubmit="return requestCeaseReason(this);"><input type="hidden" name="_token" value="${csrfToken}"><button type="submit" class="btn btn-outline">Cesar</button></form>` : ''}
            </div>
        </div>
    `;

    modal.querySelector('.modal-content').innerHTML = detailContent;
    modal.querySelector('[data-cease-reason-btn]')?.addEventListener('click', function () {
        showCeaseReason(worker);
    });
    modal.querySelector('[data-activate-worker-btn]')?.addEventListener('click', function () {
        openActivateWorker(worker);
    });
    openModal('workerDetailModal');
}

function closeWorkerDetailModal() {
    closeModal('workerDetailModal');
}

document.addEventListener('search:select', function(e) {
    const { item } = e.detail;
    console.log('Selected worker:', item);
    const searchInput = document.getElementById('personal-search') || document.querySelector('[data-search-input]');
    if (searchInput) {
        searchInput.value = item.nombre;
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const pageRoot = document.getElementById('personalPageRoot');
    const bootOverlay = document.getElementById('personalBootOverlay');
    const bootProgressBar = document.getElementById('personalBootProgressBar');
    const bootStatus = document.getElementById('personalBootStatus');
    const rows = Array.from(document.querySelectorAll('.js-person-row'));
    const gridShell = document.getElementById('personalGridShell');
    const topScrollbar = document.getElementById('personalTopScrollbar');
    const topScrollbarInner = document.getElementById('personalTopScrollbarInner');
    const tableWrap = document.getElementById('personalTableWrap');
    const dataGrid = document.getElementById('personalDataGrid');
    const pageSizeSelect = document.getElementById('personalPageSize');
    const paginationInfo = document.getElementById('personalPaginationInfo');
    const paginationWrap = document.getElementById('personalPagination');
    const countBadge = document.getElementById('personalCount');
    const searchInput = document.getElementById('personal-search');
    const sortNombre = document.getElementById('dgSortNombre');
    const sortDni = document.getElementById('dgSortDni');
    const puestoFilter = document.getElementById('dgPuesto');
    const contratoFilter = document.getElementById('dgContrato');
    const estadoFilter = document.getElementById('dgEstado');
    const bienestarFilter = document.getElementById('dgBienestar');
    const ocupGroupedToggle = document.getElementById('dgOcupGrouped');
    const ocupShowHabilitadoToggle = document.getElementById('dgOcupShowHabilitado');
    const ocupShowProcesoToggle = document.getElementById('dgOcupShowProceso');
    const ocupMineCheckboxes = Array.from(document.querySelectorAll('.js-ocup-mine-check'));
    const ocupOfficeCheckboxes = Array.from(document.querySelectorAll('.js-ocup-office-check'));
    const ocupWorkshopCheckboxes = Array.from(document.querySelectorAll('.js-ocup-workshop-check'));
    const resetViewButton = document.getElementById('personalResetViewButton');
    const columnsToggle = document.getElementById('personalColumnsToggle');
    const columnsPopover = document.getElementById('personalColumnsPopover');
    const expandToggle = document.getElementById('personalExpandToggle');
    const contractWorkerSearch = document.getElementById('contractWorkerSearch');
    const contractDownloadForm = document.getElementById('contractFormatDownloadForm');
    const columnCheckboxes = Array.from(document.querySelectorAll('.js-col-toggle'));
    const viewStateKey = 'proserge.personal.index.viewState.v1';
    const defaultVisibleColumns = ['documento', 'celular', 'correo', 'puesto', 'contrato', 'estado', 'situacion', 'ocupacion', 'acciones'];
    let syncingScroll = false;
    let gridDragState = null;

    const filterTriggers = Array.from(document.querySelectorAll('.js-dg-filter-trigger'));
    const filterPopovers = Array.from(document.querySelectorAll('.dg-filter-popover'));
    const filterTriggerByTarget = filterTriggers.reduce(function (map, trigger) {
        if (trigger.dataset.target) {
            map[trigger.dataset.target] = trigger;
        }
        return map;
    }, {});

    const setBootProgress = function (value, message) {
        if (bootProgressBar) {
            bootProgressBar.style.width = Math.max(0, Math.min(100, value)) + '%';
        }
        if (bootStatus && message) {
            bootStatus.textContent = message;
        }
    };

    const finishBootLoading = function () {
        setBootProgress(100, 'Vista lista');
        window.requestAnimationFrame(function () {
            setTimeout(function () {
                if (pageRoot) {
                    pageRoot.classList.remove('is-booting');
                }
                if (bootOverlay) {
                    bootOverlay.setAttribute('aria-hidden', 'true');
                }
            }, 80);
        });
    };

    const readViewState = function () {
        try {
            return JSON.parse(window.localStorage.getItem(viewStateKey) || '{}');
        } catch (error) {
            return {};
        }
    };

    const saveViewState = function (patch) {
        const nextState = Object.assign({}, readViewState(), patch || {});
        try {
            window.localStorage.setItem(viewStateKey, JSON.stringify(nextState));
        } catch (error) {
            // noop
        }
    };

    const clearViewState = function () {
        try {
            window.localStorage.removeItem(viewStateKey);
        } catch (error) {
            // noop
        }
    };

    if (contractWorkerSearch) {
        contractWorkerSearch.addEventListener('input', function () {
            window.clearTimeout(contractFormatSearchTimer);
            contractFormatSearchTimer = window.setTimeout(function () {
                searchContractWorkers(contractWorkerSearch.value);
            }, 220);
        });
    }

    if (contractDownloadForm) {
        contractDownloadForm.addEventListener('submit', function (event) {
            updateContractDownloadForm();
            if (!contractFormatTemplateId || contractFormatSelectedWorkers.size === 0) {
                event.preventDefault();
            }
        });
    }

    const collectVisibleColumns = function () {
        return columnCheckboxes
            .filter(function (input) { return input.checked; })
            .map(function (input) { return input.value; });
    };

    const applyVisibleColumns = function (visibleColumns) {
        const visible = Array.isArray(visibleColumns) && visibleColumns.length > 0
            ? visibleColumns
            : defaultVisibleColumns;

        columnCheckboxes.forEach(function (input) {
            input.checked = visible.indexOf(input.value) !== -1;
        });

        document.querySelectorAll('[data-column]').forEach(function (cell) {
            const key = cell.getAttribute('data-column');
            if (!key || key === 'trabajador') {
                cell.classList.remove('is-col-hidden');
                return;
            }

            cell.classList.toggle('is-col-hidden', visible.indexOf(key) === -1);
        });
    };

    const collectSelectedOcupMinas = function () {
        return ocupMineCheckboxes
            .filter(function (input) { return input.checked; })
            .map(function (input) { return input.value; });
    };

    const collectSelectedOcupOffices = function () {
        return ocupOfficeCheckboxes
            .filter(function (input) { return input.checked; })
            .map(function (input) { return input.value; });
    };

    const collectSelectedOcupWorkshops = function () {
        return ocupWorkshopCheckboxes
            .filter(function (input) { return input.checked; })
            .map(function (input) { return input.value; });
    };

    const syncFilterIndicators = function () {
        const allMinesSelected = ocupMineCheckboxes.every(function (input) {
            return input.checked;
        });
        const noOfficeSelected = ocupOfficeCheckboxes.every(function (input) {
            return !input.checked;
        });
        const noWorkshopSelected = ocupWorkshopCheckboxes.every(function (input) {
            return !input.checked;
        });
        const occupationFilterActive =
            !allMinesSelected ||
            !noOfficeSelected ||
            !noWorkshopSelected ||
            ocupShowHabilitadoToggle?.checked === false ||
            ocupShowProcesoToggle?.checked === false;

        const states = {
            dgFilterNombre: !!(sortNombre?.value || ''),
            dgFilterDni: !!(sortDni?.value || ''),
            dgFilterPuesto: !!(puestoFilter?.value || ''),
            dgFilterContrato: !!(contratoFilter?.value || ''),
            dgFilterEstado: !!(estadoFilter?.value || ''),
            dgFilterBienestar: !!(bienestarFilter?.value || ''),
            dgFilterOcupacion: occupationFilterActive,
        };

        Object.keys(states).forEach(function (targetId) {
            const trigger = filterTriggerByTarget[targetId];
            if (!trigger) return;
            trigger.classList.toggle('is-active', !!states[targetId]);
            trigger.setAttribute('aria-pressed', states[targetId] ? 'true' : 'false');
        });
    };

    const applyOccupationVisibility = function () {
        const visibleMinas = new Set(collectSelectedOcupMinas());
        const visibleOffices = new Set(collectSelectedOcupOffices());
        const visibleWorkshops = new Set(collectSelectedOcupWorkshops());
        const showHabilitado = !!ocupShowHabilitadoToggle?.checked;
        const showProceso = !!ocupShowProcesoToggle?.checked;

        document.querySelectorAll('.dg-ocup-chip-btn[data-ocup-item-key]').forEach(function (button) {
            const key = button.dataset.ocupItemKey || '';
            const category = button.dataset.ocupItemCategory || '';
            const state = button.dataset.ocupItemState || '';
            let visible = true;
            let matchesState = true;

            if (category === 'mina') {
                visible = visibleMinas.has(key);
                if (visible) {
                    if (showHabilitado && showProceso) {
                        matchesState = true;
                    } else if (showHabilitado && !showProceso) {
                        matchesState = state === 'habilitado';
                    } else if (!showHabilitado && showProceso) {
                        matchesState = state === 'proceso';
                    } else {
                        matchesState = state === 'no_habilitado';
                    }
                }
            } else if (category === 'oficina') {
                visible = visibleOffices.has(key);
            } else if (category === 'taller') {
                visible = visibleWorkshops.has(key);
            }

            button.style.display = visible ? '' : 'none';
            button.classList.toggle('is-ocup-state-hidden', visible && !matchesState);
        });

        document.querySelectorAll('.dg-ocup-row[data-ocup-section]').forEach(function (row) {
            const visibleChildren = Array.from(row.querySelectorAll('.dg-ocup-chip-btn')).filter(function (button) {
                return button.style.display !== 'none';
            });
            row.classList.toggle('is-ocup-row-hidden', visibleChildren.length === 0);
        });

        syncFilterIndicators();
    };

    const applyGroupedOccupation = function (grouped) {
        if (!gridShell || !ocupGroupedToggle) return;
        gridShell.classList.toggle('is-ocup-grouped', !!grouped);
        ocupGroupedToggle.checked = !!grouped;
    };

    const applyExpandedState = function (expanded) {
        if (!gridShell || !expandToggle) return;
        gridShell.classList.toggle('is-expanded', !!expanded);
        expandToggle.textContent = expanded ? 'Ajustar pantalla' : 'Extender pantalla';
    };

    const syncExpandedLayout = function () {
        if (!gridShell) return;
        gridShell.style.removeProperty('--personal-grid-expanded-width');
    };

    const buildPageSizeOptions = function (totalCount) {
        if (!pageSizeSelect) return;
        const total = Math.max(1, Number(totalCount || rows.length || 1));
        const base = [10, 50, 100, 200, 300, total];
        const values = Array.from(new Set(base.filter(function (value) {
            return Number.isFinite(value) && value > 0 && value <= total;
        }))).sort(function (a, b) {
            return a - b;
        });

        if (values.length === 0) {
            values.push(total || 1);
        }

        pageSizeSelect.innerHTML = values.map(function (value) {
            return '<option value="' + value + '">' + value + '</option>';
        }).join('');
    };

    const syncPageSizeOptions = function (totalCount, preferredValue) {
        if (!pageSizeSelect) return;

        buildPageSizeOptions(totalCount);

        const optionValues = Array.from(pageSizeSelect.options).map(function (option) {
            return String(option.value);
        });
        const preferred = String(preferredValue || pageSize || pageSizeSelect.value || '');

        if (preferred && optionValues.indexOf(preferred) !== -1) {
            pageSizeSelect.value = preferred;
        } else if (optionValues.length > 0) {
            pageSizeSelect.value = optionValues[optionValues.length - 1];
        }

        pageSize = Number(pageSizeSelect.value || optionValues[0] || 10);
    };

    const syncTopScrollbar = function () {
        if (!topScrollbar || !topScrollbarInner || !tableWrap || !dataGrid) return;
        const tableMaxScrollLeft = Math.max(0, tableWrap.scrollWidth - tableWrap.clientWidth);
        const needsHorizontalScroll = tableMaxScrollLeft > 2;
        const currentScrollLeft = Math.max(0, Math.min(tableWrap.scrollLeft, tableMaxScrollLeft));
        topScrollbar.classList.toggle('is-visible', needsHorizontalScroll);
        topScrollbar.style.width = tableWrap.getBoundingClientRect().width + 'px';
        topScrollbarInner.style.width = (topScrollbar.clientWidth + tableMaxScrollLeft) + 'px';
        tableWrap.scrollLeft = currentScrollLeft;
        const topMaxScrollLeft = Math.max(0, topScrollbar.scrollWidth - topScrollbar.clientWidth);
        topScrollbar.scrollLeft = topMaxScrollLeft > 0 && Math.abs(currentScrollLeft - tableMaxScrollLeft) <= 2
            ? topMaxScrollLeft
            : Math.max(0, Math.min(currentScrollLeft, topMaxScrollLeft));
    };

    const syncHorizontalScrollPosition = function (preferredScrollLeft) {
        if (!tableWrap || !dataGrid) return;

        const maxScrollLeft = Math.max(0, tableWrap.scrollWidth - tableWrap.clientWidth);
        const requested = Number.isFinite(preferredScrollLeft)
            ? preferredScrollLeft
            : tableWrap.scrollLeft;
        const nextScrollLeft = maxScrollLeft > 0 && Math.abs(requested - maxScrollLeft) <= 2
            ? maxScrollLeft
            : Math.max(0, Math.min(requested, maxScrollLeft));

        tableWrap.scrollLeft = nextScrollLeft;
        if (topScrollbar) {
            syncScrollPair(tableWrap, topScrollbar);
        }
    };

    const syncScrollPair = function (source, target) {
        if (!source || !target) return;

        const sourceMax = Math.max(0, source.scrollWidth - source.clientWidth);
        const targetMax = Math.max(0, target.scrollWidth - target.clientWidth);
        const sourceAtEnd = sourceMax > 0 && Math.abs(source.scrollLeft - sourceMax) <= 2;
        const ratio = sourceMax > 0 ? source.scrollLeft / sourceMax : 0;
        const nextScrollLeft = sourceAtEnd ? targetMax : targetMax * ratio;

        target.scrollLeft = Math.max(0, Math.min(nextScrollLeft, targetMax));
    };

    const isGridDragBlockedTarget = function (target) {
        return !!target.closest('a, button, input, select, textarea, label, form, [role="button"], [contenteditable="true"], .dg-filter-popover, .personal-action-buttons, .modal, .dropdown-menu');
    };

    const canStartGridDrag = function (event) {
        if (!tableWrap || event.button !== 0 || event.pointerType === 'touch') return false;
        if (isGridDragBlockedTarget(event.target)) return false;
        return (tableWrap.scrollWidth - tableWrap.clientWidth) > 2;
    };

    const finishGridDrag = function () {
        if (!tableWrap || !gridDragState) return;
        tableWrap.classList.remove('is-dragging');
        gridDragState = null;
    };

    const closeAllPopovers = function () {
        filterPopovers.forEach(function (panel) {
            panel.classList.remove('is-open');
            panel._triggerEl = null;
        });
    };

    const positionPopover = function (panel, triggerEl) {
        if (!panel || !triggerEl) return;

        const triggerRect = triggerEl.getBoundingClientRect();
        const gap = 8;
        const viewportMargin = 12;

        panel.style.top = (triggerRect.bottom + gap) + 'px';
        panel.style.left = '0px';

        const panelRect = panel.getBoundingClientRect();
        let left = triggerRect.right - panelRect.width;

        if (panel.classList.contains('dg-pop-left')) {
            left = triggerRect.left;
        } else if (panel.classList.contains('dg-pop-center')) {
            left = triggerRect.left + (triggerRect.width / 2) - (panelRect.width / 2);
        }

        const maxLeft = window.innerWidth - panelRect.width - viewportMargin;
        left = Math.max(viewportMargin, Math.min(left, maxLeft));

        panel.style.left = left + 'px';

        const finalRect = panel.getBoundingClientRect();
        if (finalRect.bottom > (window.innerHeight - viewportMargin)) {
            panel.style.top = Math.max(viewportMargin, triggerRect.top - panelRect.height - gap) + 'px';
        }
    };

    const repositionOpenPopover = function () {
        const active = document.querySelector('.dg-filter-popover.is-open');
        if (!active || !active._triggerEl) return;
        positionPopover(active, active._triggerEl);
    };

    const closeColumnsPopover = function () {
        if (!columnsPopover || !columnsToggle) return;
        columnsPopover.classList.remove('is-open');
        columnsToggle.setAttribute('aria-expanded', 'false');
    };

    const positionColumnsPopover = function () {
        if (!columnsPopover || !columnsToggle) return;

        const triggerRect = columnsToggle.getBoundingClientRect();
        const gap = 8;
        const viewportMargin = 12;

        columnsPopover.style.top = (triggerRect.bottom + gap) + 'px';
        columnsPopover.style.left = '0px';

        const popoverRect = columnsPopover.getBoundingClientRect();
        let left = triggerRect.right - popoverRect.width;
        const maxLeft = window.innerWidth - popoverRect.width - viewportMargin;
        left = Math.max(viewportMargin, Math.min(left, maxLeft));

        columnsPopover.style.left = left + 'px';

        const finalRect = columnsPopover.getBoundingClientRect();
        if (finalRect.bottom > (window.innerHeight - viewportMargin)) {
            columnsPopover.style.top = Math.max(viewportMargin, triggerRect.top - popoverRect.height - gap) + 'px';
        }
    };

    const fitPopoverWithinViewport = function (panel) {
        const triggerEl = panel && panel._triggerEl;
        if (!panel || !triggerEl) return;
        positionPopover(panel, triggerEl);

        const rect = panel.getBoundingClientRect();
        const margin = 12;
        if (rect.top < margin) {
            panel.style.top = margin + 'px';
        }
    };

    filterTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const targetId = trigger.dataset.target;
            const panel = targetId ? document.getElementById(targetId) : null;
            if (!panel) return;
            const willOpen = !panel.classList.contains('is-open');
            closeAllPopovers();
            if (willOpen) {
                panel.classList.add('is-open');
                panel._triggerEl = trigger;
                fitPopoverWithinViewport(panel);
            }
        });
    });

    window.addEventListener('resize', function () {
        repositionOpenPopover();
        if (columnsPopover?.classList.contains('is-open')) {
            positionColumnsPopover();
        }
    });

    document.addEventListener('scroll', function () {
        repositionOpenPopover();
        if (columnsPopover?.classList.contains('is-open')) {
            positionColumnsPopover();
        }
    }, true);

    document.addEventListener('click', function (event) {
        const inPopover = event.target.closest('.dg-filter-popover');
        const inTrigger = event.target.closest('.js-dg-filter-trigger');
        const inColumns = event.target.closest('#personalColumnsPopover');
        const inColumnsTrigger = event.target.closest('#personalColumnsToggle');
        if (!inPopover && !inTrigger) {
            closeAllPopovers();
        }
        if (!inColumns && !inColumnsTrigger) {
            closeColumnsPopover();
        }
    });

    if (columnsToggle && columnsPopover) {
        const toggleColumnsPopover = function (event) {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !columnsPopover.classList.contains('is-open');
            closeColumnsPopover();
            if (willOpen) {
                columnsPopover.classList.add('is-open');
                columnsToggle.setAttribute('aria-expanded', 'true');
                positionColumnsPopover();
            }
        };

        columnsToggle.addEventListener('click', toggleColumnsPopover);
        columnsToggle.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                toggleColumnsPopover(event);
            }
        });

        columnsPopover.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    if (resetViewButton) {
        resetViewButton.addEventListener('click', function () {
            clearViewState();
            const targetUrl = resetViewButton.dataset.resetUrl || window.location.pathname;
            window.location.href = targetUrl;
        });
    }

    const normalizeText = function(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const populateSelect = function(selectEl, values) {
        if (!selectEl) return;
        const current = selectEl.value;
        const options = Array.from(new Set(values.filter(Boolean))).sort();
        options.forEach(function(value) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            selectEl.appendChild(opt);
        });
        if (current) selectEl.value = current;
    };

    populateSelect(puestoFilter, rows.map(r => r.dataset.puesto || ''));
    populateSelect(contratoFilter, rows.map(r => r.dataset.contrato || ''));

    let currentPage = 1;
    let pageSize = Number(pageSizeSelect?.value || 9);
    const savedState = readViewState();
    setBootProgress(18, 'Cargando preferencias...');

    buildPageSizeOptions(rows.length);

    if (searchInput && typeof savedState.search === 'string') {
        searchInput.value = savedState.search;
    }

    if (pageSizeSelect) {
        const optionValues = Array.from(pageSizeSelect.options).map(function (option) {
            return String(option.value);
        });
        const preferredPageSize = String(savedState.pageSize || '');
        if (preferredPageSize && optionValues.indexOf(preferredPageSize) !== -1) {
            pageSizeSelect.value = preferredPageSize;
        }
        pageSize = Number(pageSizeSelect.value || optionValues[0] || 10);
    }

    if (sortNombre && typeof savedState.sortNombre === 'string') sortNombre.value = savedState.sortNombre;
    if (sortDni && typeof savedState.sortDni === 'string') sortDni.value = savedState.sortDni;
    if (puestoFilter && typeof savedState.puesto === 'string') puestoFilter.value = savedState.puesto;
    if (contratoFilter && typeof savedState.contrato === 'string') contratoFilter.value = savedState.contrato;
    if (estadoFilter && typeof savedState.estado === 'string') estadoFilter.value = savedState.estado;
    if (bienestarFilter && typeof savedState.bienestar === 'string') bienestarFilter.value = savedState.bienestar;

    setBootProgress(42, 'Aplicando columnas y filtros...');
    applyVisibleColumns(savedState.visibleColumns || defaultVisibleColumns);
    applyExpandedState(!!savedState.expanded);
    applyGroupedOccupation(savedState.occupationGrouped !== false);
    if (Array.isArray(savedState.selectedOcupMinas)) {
        ocupMineCheckboxes.forEach(function (input) {
            input.checked = savedState.selectedOcupMinas.indexOf(input.value) !== -1;
        });
    }
    if (Array.isArray(savedState.selectedOcupOffices)) {
        ocupOfficeCheckboxes.forEach(function (input) {
            input.checked = savedState.selectedOcupOffices.indexOf(input.value) !== -1;
        });
    }
    if (Array.isArray(savedState.selectedOcupWorkshops)) {
        ocupWorkshopCheckboxes.forEach(function (input) {
            input.checked = savedState.selectedOcupWorkshops.indexOf(input.value) !== -1;
        });
    }
    if (ocupShowHabilitadoToggle) {
        ocupShowHabilitadoToggle.checked = savedState.showOcupHabilitado !== false;
    }
    if (ocupShowProcesoToggle) {
        ocupShowProcesoToggle.checked = savedState.showOcupProceso !== false;
    }
    applyOccupationVisibility();
    setBootProgress(64, 'Preparando tabla...');
    currentPage = Number(savedState.currentPage || 1);

    const applyFiltersAndSort = function() {
        const search = normalizeText(searchInput?.value || '');
        const searchTokens = search.split(' ').filter(Boolean);
        const puesto = normalizeText(puestoFilter?.value || '');
        const contrato = normalizeText(contratoFilter?.value || '');
        const estado = normalizeText(estadoFilter?.value || '');
        const bienestar = normalizeText(bienestarFilter?.value || '');

        let filtered = rows.filter(function(row) {
            const searchable = normalizeText([
                row.dataset.nombre,
                row.dataset.dni,
                row.dataset.puesto,
                row.dataset.contrato,
                row.dataset.minas,
            ].join(' '));

            if (searchTokens.length && !searchTokens.every(t => searchable.includes(t))) return false;
            if (puesto && normalizeText(row.dataset.puesto).indexOf(puesto) === -1) return false;
            if (contrato && normalizeText(row.dataset.contrato).indexOf(contrato) === -1) return false;
            if (estado && normalizeText(row.dataset.estado) !== estado) return false;
            if (bienestar && normalizeText(row.dataset.bienestar) !== bienestar) return false;
            return true;
        });

        const compareText = function(a, b) {
            return a.localeCompare(b, 'es', { sensitivity: 'base' });
        };

        filtered.sort(function(a, b) {
            const nSort = sortNombre?.value || '';
            if (nSort) {
                const cmp = compareText(String(a.dataset.nombre || ''), String(b.dataset.nombre || ''));
                if (cmp !== 0) return nSort === 'asc' ? cmp : -cmp;
            }
            const dSort = sortDni?.value || '';
            if (dSort) {
                const cmp = compareText(String(a.dataset.dni || ''), String(b.dataset.dni || ''));
                if (cmp !== 0) return dSort === 'asc' ? cmp : -cmp;
            }
            return 0;
        });

        return filtered;
    };

    const renderPagination = function(totalPages) {
        if (!paginationWrap) return;
        if (totalPages <= 1) {
            paginationWrap.innerHTML = '';
            return;
        }
        const maxVisible = 7;
        const visiblePages = [];

        if (totalPages <= maxVisible) {
            for (let p = 1; p <= totalPages; p++) {
                visiblePages.push(p);
            }
        } else {
            const pages = new Set([1, totalPages]);
            const around = Math.max(1, Math.floor((maxVisible - 3) / 2));
            const start = Math.max(2, currentPage - around);
            const end = Math.min(totalPages - 1, currentPage + around);

            for (let page = start; page <= end; page++) {
                pages.add(page);
            }

            const ordered = Array.from(pages).sort((a, b) => a - b);
            ordered.forEach(function (page, index) {
                if (index > 0 && page - ordered[index - 1] > 1) {
                    visiblePages.push('ellipsis');
                }
                visiblePages.push(page);
            });
        }

        let html = '';
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '>&lsaquo;</button>';
        visiblePages.forEach(function (page) {
            if (page === 'ellipsis') {
                html += '<span class="personal-pager-ellipsis">...</span>';
                return;
            }
            html += '<button type="button" class="personal-pager-btn ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage === totalPages ? 'disabled' : '') + '>&rsaquo;</button>';

        paginationWrap.innerHTML = html;
    };

    const clampPage = function(page, totalPages) {
        if (Number.isNaN(page) || page < 1) return 1;
        if (page > totalPages) return totalPages;
        return page;
    };

    const renderGrid = function(resetPage) {
        if (resetPage) currentPage = 1;
        const filtered = applyFiltersAndSort();
        const total = filtered.length;
        syncPageSizeOptions(total, pageSize);
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = clampPage(currentPage, totalPages);
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        const visibleRows = filtered.slice(start, end);

        rows.forEach(r => r.style.display = 'none');
        visibleRows.forEach(r => r.style.display = 'table-row');

        if (paginationInfo) {
            paginationInfo.textContent = total === 0
                ? '0 resultados'
                : 'Mostrando ' + (start + 1) + '-' + (start + visibleRows.length) + ' de ' + total;
        }
        if (countBadge) {
            countBadge.textContent = total + ' trabajadores';
        }
        syncFilterIndicators();
        renderPagination(totalPages);
        syncTopScrollbar();

        saveViewState({
            search: searchInput?.value || '',
            currentPage: currentPage,
            pageSize: pageSize,
            sortNombre: sortNombre?.value || '',
            sortDni: sortDni?.value || '',
            puesto: puestoFilter?.value || '',
            contrato: contratoFilter?.value || '',
            estado: estadoFilter?.value || '',
            bienestar: bienestarFilter?.value || '',
            visibleColumns: collectVisibleColumns(),
            selectedOcupMinas: collectSelectedOcupMinas(),
            selectedOcupOffices: collectSelectedOcupOffices(),
            selectedOcupWorkshops: collectSelectedOcupWorkshops(),
            showOcupHabilitado: !!ocupShowHabilitadoToggle?.checked,
            showOcupProceso: !!ocupShowProcesoToggle?.checked,
            occupationGrouped: !!ocupGroupedToggle?.checked,
            expanded: !!gridShell?.classList.contains('is-expanded'),
        });
    };

    const simpleSearchInput = document.getElementById('personal-search');
    const simpleSearchClear = document.querySelector('[data-simple-search-clear]');
    if (simpleSearchInput && simpleSearchClear) {
        const syncSearchClear = function () {
            simpleSearchClear.style.display = simpleSearchInput.value.trim().length > 0 ? 'flex' : 'none';
        };

        simpleSearchInput.addEventListener('input', function () {
            syncSearchClear();
            renderGrid(true);
        });
        simpleSearchClear.addEventListener('click', function () {
            simpleSearchInput.value = '';
            simpleSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
            syncSearchClear();
            simpleSearchInput.focus();
        });

        syncSearchClear();
    }

    [sortNombre, sortDni, puestoFilter, contratoFilter, estadoFilter, bienestarFilter].forEach(function(el) {
        if (!el) return;
        el.addEventListener('change', function () { renderGrid(true); });
    });
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            pageSize = Number(pageSizeSelect.value || 9);
            renderGrid(true);
        });
    }

    columnCheckboxes.forEach(function (input) {
        input.addEventListener('change', function () {
            let visibleColumns = collectVisibleColumns();
            if (visibleColumns.length === 0) {
                input.checked = true;
                visibleColumns = collectVisibleColumns();
            }

            applyVisibleColumns(visibleColumns);
            saveViewState({
                visibleColumns: visibleColumns,
            });
            syncTopScrollbar();
        });
    });

    ocupMineCheckboxes.forEach(function (input) {
        input.addEventListener('change', function () {
            applyOccupationVisibility();
            saveViewState({
                selectedOcupMinas: collectSelectedOcupMinas(),
                selectedOcupOffices: collectSelectedOcupOffices(),
                selectedOcupWorkshops: collectSelectedOcupWorkshops(),
            });
            window.requestAnimationFrame(syncTopScrollbar);
        });
    });

    ocupOfficeCheckboxes.forEach(function (input) {
        input.addEventListener('change', function () {
            applyOccupationVisibility();
            saveViewState({
                selectedOcupMinas: collectSelectedOcupMinas(),
                selectedOcupOffices: collectSelectedOcupOffices(),
                selectedOcupWorkshops: collectSelectedOcupWorkshops(),
            });
            window.requestAnimationFrame(syncTopScrollbar);
        });
    });

    ocupWorkshopCheckboxes.forEach(function (input) {
        input.addEventListener('change', function () {
            applyOccupationVisibility();
            saveViewState({
                selectedOcupMinas: collectSelectedOcupMinas(),
                selectedOcupOffices: collectSelectedOcupOffices(),
                selectedOcupWorkshops: collectSelectedOcupWorkshops(),
                showOcupHabilitado: !!ocupShowHabilitadoToggle?.checked,
                showOcupProceso: !!ocupShowProcesoToggle?.checked,
            });
            window.requestAnimationFrame(syncTopScrollbar);
        });
    });

    [ocupShowHabilitadoToggle, ocupShowProcesoToggle].forEach(function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            applyOccupationVisibility();
            saveViewState({
                showOcupHabilitado: !!ocupShowHabilitadoToggle?.checked,
                showOcupProceso: !!ocupShowProcesoToggle?.checked,
            });
            window.requestAnimationFrame(syncTopScrollbar);
        });
    });

    if (ocupGroupedToggle) {
        ocupGroupedToggle.addEventListener('change', function () {
            applyGroupedOccupation(ocupGroupedToggle.checked);
            applyOccupationVisibility();
            saveViewState({
                occupationGrouped: !!ocupGroupedToggle.checked,
            });
            window.requestAnimationFrame(function () {
                syncTopScrollbar();
                renderGrid(false);
            });
        });
    }

    if (expandToggle) {
        expandToggle.addEventListener('click', function () {
            const nextExpanded = !gridShell?.classList.contains('is-expanded');
            const currentScrollLeft = tableWrap ? tableWrap.scrollLeft : 0;
            applyExpandedState(nextExpanded);
            saveViewState({
                expanded: nextExpanded,
            });
            window.requestAnimationFrame(function () {
                syncExpandedLayout();
                syncTopScrollbar();
                syncHorizontalScrollPosition(currentScrollLeft);
            });
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function (event) {
            const btn = event.target.closest('button[data-page]');
            if (!btn || btn.hasAttribute('disabled')) return;
            currentPage = Number(btn.dataset.page || 1);
            renderGrid(false);
        });
    }

    if (tableWrap) {
        tableWrap.addEventListener('pointerdown', function (event) {
            if (!canStartGridDrag(event)) return;

            gridDragState = {
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                startScrollLeft: tableWrap.scrollLeft,
                hasMoved: false,
            };
            tableWrap.setPointerCapture?.(event.pointerId);
        });

        tableWrap.addEventListener('pointermove', function (event) {
            if (!gridDragState || gridDragState.pointerId !== event.pointerId) return;

            const deltaX = event.clientX - gridDragState.startX;
            const deltaY = event.clientY - gridDragState.startY;
            if (!gridDragState.hasMoved && Math.abs(deltaX) < 4 && Math.abs(deltaY) < 4) return;

            gridDragState.hasMoved = true;
            tableWrap.classList.add('is-dragging');
            event.preventDefault();
            syncHorizontalScrollPosition(gridDragState.startScrollLeft - deltaX);
        });

        tableWrap.addEventListener('pointerup', finishGridDrag);
        tableWrap.addEventListener('pointercancel', finishGridDrag);
        tableWrap.addEventListener('lostpointercapture', finishGridDrag);

        tableWrap.addEventListener('scroll', function () {
            if (topScrollbar && !syncingScroll) {
                syncingScroll = true;
                syncScrollPair(tableWrap, topScrollbar);
                window.requestAnimationFrame(function () {
                    syncingScroll = false;
                });
            }
            saveViewState({
                tableScrollLeft: tableWrap.scrollLeft,
                tableScrollTop: tableWrap.scrollTop,
            });
        }, { passive: true });
    }

    if (topScrollbar) {
        topScrollbar.addEventListener('scroll', function () {
            if (tableWrap && !syncingScroll) {
                syncingScroll = true;
                syncScrollPair(topScrollbar, tableWrap);
                window.requestAnimationFrame(function () {
                    syncingScroll = false;
                });
            }
        }, { passive: true });
    }

    window.addEventListener('scroll', function () {
        saveViewState({
            pageScrollY: window.scrollY,
        });
    }, { passive: true });

    window.addEventListener('resize', function () {
        syncExpandedLayout();
        syncTopScrollbar();
        syncHorizontalScrollPosition();
    });

    document.querySelectorAll('.dg-ocup-chip-btn').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const filterType = button.dataset.ocupFilterType;
            const value = button.dataset.ocupFilterValue || '';
            if (filterType === 'ocupMina') {
                ocupMineCheckboxes.forEach(function (input) {
                    input.checked = input.value === value;
                });
                ocupOfficeCheckboxes.forEach(function (input) { input.checked = false; });
                ocupWorkshopCheckboxes.forEach(function (input) { input.checked = false; });
            } else if (filterType === 'ocupOffice') {
                ocupOfficeCheckboxes.forEach(function (input) {
                    input.checked = input.value === value;
                });
                ocupMineCheckboxes.forEach(function (input) { input.checked = false; });
                ocupWorkshopCheckboxes.forEach(function (input) { input.checked = false; });
            } else if (filterType === 'ocupWorkshop') {
                ocupWorkshopCheckboxes.forEach(function (input) {
                    input.checked = input.value === value;
                });
                ocupMineCheckboxes.forEach(function (input) { input.checked = false; });
                ocupOfficeCheckboxes.forEach(function (input) { input.checked = false; });
            } else {
                return;
            }
            applyOccupationVisibility();
            saveViewState({
                selectedOcupMinas: collectSelectedOcupMinas(),
                selectedOcupOffices: collectSelectedOcupOffices(),
                selectedOcupWorkshops: collectSelectedOcupWorkshops(),
                showOcupHabilitado: !!ocupShowHabilitadoToggle?.checked,
                showOcupProceso: !!ocupShowProcesoToggle?.checked,
            });
            window.requestAnimationFrame(syncTopScrollbar);
        });
    });
    
    // Dropdown Acciones - mostrar/ocultar sin mover contenido
    const accionesBtn = document.getElementById('accionesBtn');
    const accionesMenu = document.getElementById('accionesMenu');
    
    if (accionesBtn && accionesMenu) {
        accionesBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const isVisible = accionesMenu.style.display === 'block';
            accionesMenu.style.display = isVisible ? 'none' : 'block';
        });

        accionesMenu.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function () {
            accionesMenu.style.display = 'none';
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                accionesMenu.style.display = 'none';
            }
        });
    }
    
    const filterToggle = document.getElementById('filterToggle');
    const filterPanel = document.getElementById('filterPanel');
    if (filterToggle) filterToggle.style.display = 'none';
    if (filterPanel) filterPanel.style.display = 'none';

    setBootProgress(82, 'Mostrando trabajadores...');
    renderGrid(false);

    window.requestAnimationFrame(function () {
        setBootProgress(94, 'Ajustando vista final...');
        syncExpandedLayout();
        syncTopScrollbar();
        if (tableWrap) {
            tableWrap.scrollTop = Number(savedState.tableScrollTop || 0);
        }
        syncHorizontalScrollPosition(Number(savedState.tableScrollLeft || 0));
        if (savedState.pageScrollY) {
            window.scrollTo({ top: Number(savedState.pageScrollY || 0), behavior: 'auto' });
        }
        finishBootLoading();
    });
});
</script>
@endpush
