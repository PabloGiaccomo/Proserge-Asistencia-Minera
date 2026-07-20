@php
    $editorId = $editorId ?? 'rqPersonalRequestEditor';
    $detalle = $detalle ?? [];
    $rows = !empty($detalle) ? array_values($detalle) : [
        ['puesto' => '', 'cantidad' => null, 'cantidad_backup' => 0, 'cantidad_total' => 0, 'cantidad_atendida' => 0],
    ];

    $summary = [
        'puestos' => 0,
        'solicitado' => 0,
        'backup' => 0,
        'total' => 0,
        'atendido' => 0,
    ];

    foreach ($detalle as $line) {
        $cantidad = max(0, (int) ($line['cantidad'] ?? 0));
        $backup = array_key_exists('cantidad_backup', $line)
            ? max(0, (int) $line['cantidad_backup'])
            : (int) round($cantidad * 0.2);
        $total = array_key_exists('cantidad_total', $line)
            ? max(0, (int) $line['cantidad_total'])
            : $cantidad + $backup;

        if (trim((string) ($line['puesto'] ?? '')) !== '' && $cantidad > 0) {
            $summary['puestos']++;
        }

        $summary['solicitado'] += $cantidad;
        $summary['backup'] += $backup;
        $summary['total'] += $total;
        $summary['atendido'] += max(0, (int) ($line['cantidad_atendida'] ?? 0));
    }
@endphp

@once

<script>
window.rqMinaPersonnelEditors = window.rqMinaPersonnelEditors || {};

function initRQMinaPersonnelRequestEditor(root) {
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    const editorId = root.id;
    const tableBody = root.querySelector('[data-personnel-body]');
    const addButton = root.querySelector('[data-add-personnel-row]');
    const detail = root.querySelector('[data-personnel-detail]');
    const toggleButton = root.querySelector('[data-toggle-personnel-detail]');
    const toggleIcon = root.querySelector('[data-personnel-toggle-icon]');
    const template = root.querySelector('[data-personnel-template]');
    let nextIndex = Number(root.dataset.nextIndex || '0');
    let isCollapsed = false;

    function applyCollapseState() {
        if (detail) {
            detail.hidden = isCollapsed;
        }
        if (toggleButton) {
            toggleButton.classList.toggle('is-collapsed', isCollapsed);
            toggleButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            toggleButton.setAttribute('title', isCollapsed ? 'Mostrar cargos del pedido' : 'Ocultar cargos del pedido');
            toggleButton.setAttribute('aria-label', isCollapsed ? 'Mostrar cargos del pedido' : 'Ocultar cargos del pedido');
        }
        if (toggleIcon) {
            toggleIcon.innerHTML = isCollapsed ? '&darr;' : '&uarr;';
        }
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function backupFor(quantity) {
        return Math.round(Math.max(0, Number(quantity || 0)) * 0.2);
    }

    function updateRow(row) {
        const quantityInput = row.querySelector('[data-personnel-quantity]');
        const quantity = Math.max(0, Number(quantityInput ? quantityInput.value : 0));
        const backup = backupFor(quantity);
        const total = quantity + backup;

        row.querySelectorAll('[data-personnel-backup]').forEach(cell => cell.textContent = String(backup));
        row.querySelectorAll('[data-personnel-total]').forEach(cell => cell.textContent = String(total));
    }

    function updateSummary() {
        let puestos = 0;
        let solicitado = 0;
        let backup = 0;
        let total = 0;
        let atendido = 0;

        tableBody.querySelectorAll('[data-personnel-row]').forEach(row => {
            updateRow(row);
            const puesto = row.querySelector('[data-personnel-position]')?.value.trim() || '';
            const quantity = Math.max(0, Number(row.querySelector('[data-personnel-quantity]')?.value || 0));
            const rowBackup = backupFor(quantity);
            const rowTotal = quantity + rowBackup;
            const rowAttended = Math.max(0, Number(row.dataset.attended || 0));

            if (puesto !== '' && quantity > 0) {
                puestos++;
            }

            solicitado += quantity;
            backup += rowBackup;
            total += rowTotal;
            atendido += rowAttended;
        });

        root.querySelectorAll('[data-personnel-summary]').forEach(node => {
            const key = node.dataset.personnelSummary;
            const values = { puestos, solicitado, backup, total, atendido };
            node.textContent = String(values[key] || 0);
        });
    }

    function addRow(initialData = {}) {
        const index = nextIndex++;
        const html = template.innerHTML.replace(/__INDEX__/g, String(index));
        tableBody.insertAdjacentHTML('beforeend', html);
        const row = tableBody.lastElementChild;
        row.dataset.attended = String(Math.max(0, Number(initialData.cantidad_atendida || 0)));

        const positionInput = row.querySelector('[data-personnel-position]');
        const quantityInput = row.querySelector('[data-personnel-quantity]');
        const attendedCell = row.querySelector('.rq-personnel-pill.attended');

        if (positionInput) {
            positionInput.value = initialData.puesto || '';
        }
        if (quantityInput) {
            const quantity = Math.max(0, Number(initialData.cantidad || 0));
            quantityInput.value = quantity > 0 ? String(quantity) : '';
        }
        if (attendedCell) {
            attendedCell.textContent = row.dataset.attended;
        }

        if (window.RQMinaFieldOptions) {
            window.RQMinaFieldOptions.refresh(row);
        }
        updateSummary();
    }

    function collectRows() {
        const rows = [];
        tableBody.querySelectorAll('[data-personnel-row]').forEach(row => {
            const puesto = row.querySelector('[data-personnel-position]')?.value.trim() || '';
            const cantidad = Math.max(0, Number(row.querySelector('[data-personnel-quantity]')?.value || 0));
            const cantidadAtendida = Math.max(0, Number(row.dataset.attended || 0));

            if (puesto === '' && cantidad <= 0) {
                return;
            }

            rows.push({
                puesto,
                cantidad,
                cantidad_atendida: cantidadAtendida,
            });
        });

        return rows;
    }

    function setRows(rows) {
        const nextRows = Array.isArray(rows) && rows.length ? rows : [
            { puesto: '', cantidad: '', cantidad_atendida: 0 },
        ];

        tableBody.innerHTML = '';
        nextIndex = 0;
        nextRows.forEach(row => addRow(row));
        updateSummary();
    }

    addButton?.addEventListener('click', function() {
        if (isCollapsed) {
            isCollapsed = false;
            applyCollapseState();
        }
        addRow();
    });
    toggleButton?.addEventListener('click', function() {
        isCollapsed = !isCollapsed;
        applyCollapseState();
    });
    root.addEventListener('input', event => {
        if (event.target.matches('[data-personnel-position], [data-personnel-quantity]')) {
            updateSummary();
        }
    });
    root.addEventListener('click', event => {
        const removeButton = event.target.closest('[data-remove-personnel-row]');
        if (!removeButton) return;

        const rows = tableBody.querySelectorAll('[data-personnel-row]');
        if (rows.length <= 1) {
            const row = removeButton.closest('[data-personnel-row]');
            row.querySelectorAll('input').forEach(input => input.value = '');
            row.dataset.attended = '0';
            updateSummary();
            return;
        }

        removeButton.closest('[data-personnel-row]')?.remove();
        updateSummary();
    });

    if (window.RQMinaFieldOptions) {
        window.RQMinaFieldOptions.refresh(root);
    }
    applyCollapseState();
    updateSummary();

    window.rqMinaPersonnelEditors[editorId] = {
        getRows: collectRows,
        setRows,
        updateSummary,
    };
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-rq-personnel-editor]').forEach(initRQMinaPersonnelRequestEditor);
});
</script>
@endonce

<div id="{{ $editorId }}" class="rq-personnel-editor" data-rq-personnel-editor data-next-index="{{ count($rows) }}">
    <div class="rq-personnel-head">
        <div>
            <h3>Pedido de personal para RRHH</h3>
            <span>Agrega los cargos que se solicitaran en RQ Proserge. El back up 20% y el total se calculan automaticamente.</span>
        </div>
        <div class="rq-personnel-actions">
            <button type="button" class="rq-personnel-btn primary" data-add-personnel-row>Agregar cargo</button>
            <button type="button" class="rq-personnel-btn rq-personnel-toggle" data-toggle-personnel-detail aria-expanded="true" title="Ocultar cargos del pedido" aria-label="Ocultar cargos del pedido">
                <span class="rq-personnel-toggle-icon" data-personnel-toggle-icon>&uarr;</span>
            </button>
        </div>
    </div>

    <div class="rq-personnel-body">
        <div class="rq-personnel-kpis">
            <div class="rq-personnel-kpi">
                <span>Puestos requeridos</span>
                <strong data-personnel-summary="puestos">{{ $summary['puestos'] }}</strong>
                <small>cargos distintos</small>
            </div>
            <div class="rq-personnel-kpi">
                <span>Cantidad RQ</span>
                <strong data-personnel-summary="solicitado">{{ $summary['solicitado'] }}</strong>
                <small>personas solicitadas</small>
            </div>
            <div class="rq-personnel-kpi">
                <span>Back up 20%</span>
                <strong data-personnel-summary="backup">{{ $summary['backup'] }}</strong>
                <small>sin decimales</small>
            </div>
            <div class="rq-personnel-kpi">
                <span>Total con back up</span>
                <strong data-personnel-summary="total">{{ $summary['total'] }}</strong>
                <small>RQ + back up</small>
            </div>
            <div class="rq-personnel-kpi">
                <span>Entregado por RRHH</span>
                <strong data-personnel-summary="atendido">{{ $summary['atendido'] }}</strong>
                <small>se actualiza desde RQ Proserge</small>
            </div>
        </div>

        <div class="rq-personnel-detail" data-personnel-detail>
            <div class="rq-personnel-note">
                El pedido se guarda junto con el plan operativo. RRHH vera estos cargos para ir atendiendo el RQ Proserge.
            </div>

            <div class="rq-personnel-table-wrap">
                <table class="rq-personnel-table">
                    <thead>
                        <tr>
                            <th style="width:42%;">Cargo</th>
                            <th>Cantidad RQ</th>
                            <th>Back up 20%</th>
                            <th>Total con back up</th>
                            <th>Entregado por RRHH</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody data-personnel-body>
                        @foreach($rows as $index => $line)
                            @php
                                $cantidad = max(0, (int) ($line['cantidad'] ?? 0));
                                $backup = array_key_exists('cantidad_backup', $line)
                                    ? max(0, (int) $line['cantidad_backup'])
                                    : (int) round($cantidad * 0.2);
                                $total = array_key_exists('cantidad_total', $line)
                                    ? max(0, (int) $line['cantidad_total'])
                                    : $cantidad + $backup;
                                $atendido = max(0, (int) ($line['cantidad_atendida'] ?? 0));
                            @endphp
                            <tr data-personnel-row data-attended="{{ $atendido }}">
                                <td>
                                    <input
                                        type="text"
                                        class="rq-personnel-input"
                                        name="detalle[{{ $index }}][puesto]"
                                        value="{{ $line['puesto'] ?? '' }}"
                                        placeholder="Cargo o puesto"
                                        data-personnel-position
                                        data-rq-option-field="rq_mina.detalle_puesto"
                                        autocomplete="off"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        min="1"
                                        step="1"
                                        class="rq-personnel-input qty"
                                        name="detalle[{{ $index }}][cantidad]"
                                        value="{{ $cantidad > 0 ? $cantidad : '' }}"
                                        placeholder="0"
                                        data-personnel-quantity
                                    >
                                </td>
                                <td><span class="rq-personnel-pill" data-personnel-backup>{{ $backup }}</span></td>
                                <td><span class="rq-personnel-pill total" data-personnel-total>{{ $total }}</span></td>
                                <td><span class="rq-personnel-pill attended">{{ $atendido }}</span></td>
                                <td><button type="button" class="rq-personnel-btn danger" data-remove-personnel-row>Quitar</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <template data-personnel-template>
        <tr data-personnel-row data-attended="0">
            <td>
                <input
                    type="text"
                    class="rq-personnel-input"
                    name="detalle[__INDEX__][puesto]"
                    placeholder="Cargo o puesto"
                    data-personnel-position
                    data-rq-option-field="rq_mina.detalle_puesto"
                    autocomplete="off"
                >
            </td>
            <td>
                <input
                    type="number"
                    min="1"
                    step="1"
                    class="rq-personnel-input qty"
                    name="detalle[__INDEX__][cantidad]"
                    placeholder="0"
                    data-personnel-quantity
                >
            </td>
            <td><span class="rq-personnel-pill" data-personnel-backup>0</span></td>
            <td><span class="rq-personnel-pill total" data-personnel-total>0</span></td>
            <td><span class="rq-personnel-pill attended">0</span></td>
            <td><button type="button" class="rq-personnel-btn danger" data-remove-personnel-row>Quitar</button></td>
        </tr>
    </template>
</div>
