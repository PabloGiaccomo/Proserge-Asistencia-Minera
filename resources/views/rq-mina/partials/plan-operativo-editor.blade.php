@php
    $editorId = $editorId ?? 'rqPlanOperativoEditor';
    $planOperativo = $planOperativo ?? [];
    $weekNumber = $weekNumber ?? null;
    $weekYear = $weekYear ?? null;
@endphp

@once
<style>
.rq-plan-editor { border:1px solid #dbe4ef; border-radius:12px; background:#fff; overflow:hidden; }
.rq-plan-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.rq-plan-head h3 { margin:0; font-size:15px; color:#0f172a; }
.rq-plan-head span { display:block; margin-top:2px; font-size:12px; color:#64748b; }
.rq-plan-week-pill { display:inline-flex; align-items:center; border-radius:999px; background:#ecfeff; color:#0e7490; padding:5px 10px; font-size:12px; font-weight:800; margin-top:8px; }
.rq-plan-actions { display:flex; gap:8px; flex-wrap:wrap; }
.rq-plan-body { display:flex; flex-direction:column; gap:14px; padding:14px; }
.rq-plan-empty { border:1px dashed #cbd5e1; border-radius:10px; padding:18px; color:#64748b; background:#f8fafc; font-size:13px; text-align:center; }
.rq-plan-group { border:1px solid #e2e8f0; border-radius:10px; background:#fff; overflow:hidden; }
.rq-plan-group-head { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)) auto; gap:10px; padding:12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; align-items:end; }
.rq-plan-group-main { display:contents; }
.rq-plan-field label { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:5px; text-transform:uppercase; }
.rq-plan-input, .rq-plan-textarea, .rq-plan-select { width:100%; border:1px solid #dbe4ef; border-radius:8px; padding:8px 9px; font-size:12px; color:#0f172a; background:#fff; }
.rq-plan-textarea { min-height:38px; resize:vertical; }
.rq-plan-section { padding:12px; border-top:1px solid #f1f5f9; }
.rq-plan-section:first-of-type { border-top:0; }
.rq-plan-section-title { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.rq-plan-section-title h4 { margin:0; font-size:13px; color:#0f172a; }
.rq-plan-activity { border:1px solid #e2e8f0; border-radius:10px; padding:10px; margin-bottom:10px; background:#fcfdff; }
.rq-plan-activity-grid { display:grid; grid-template-columns:repeat(5,minmax(120px,1fr)); gap:8px; }
.rq-plan-activity-wide { grid-column:span 2; }
.rq-plan-supervisors { display:grid; grid-template-columns:repeat(4,minmax(120px,1fr)); gap:8px; margin-top:8px; }
.rq-plan-schedule { margin-top:10px; overflow:auto; border:1px solid #e2e8f0; border-radius:8px; }
.rq-plan-schedule table { width:100%; border-collapse:collapse; min-width:760px; }
.rq-plan-schedule th, .rq-plan-schedule td { border-bottom:1px solid #eef2f7; border-right:1px solid #eef2f7; padding:6px; font-size:11px; vertical-align:top; }
.rq-plan-schedule th { background:#f8fafc; color:#475569; text-align:left; }
.rq-plan-schedule td:last-child, .rq-plan-schedule th:last-child { border-right:0; }
.rq-plan-schedule input { width:100%; min-width:70px; border:1px solid #dbe4ef; border-radius:6px; padding:6px; font-size:11px; }
.rq-plan-transport-row { display:grid; grid-template-columns:1fr 1fr 1.4fr 1.4fr auto; gap:8px; align-items:start; margin-bottom:8px; }
.rq-plan-btn { border:1px solid #cbd5e1; background:#fff; color:#0f172a; border-radius:8px; padding:8px 10px; font-size:12px; font-weight:700; cursor:pointer; }
.rq-plan-btn:hover { background:#f8fafc; }
.rq-plan-btn.primary { border-color:#0f766e; background:#0f766e; color:#fff; }
.rq-plan-btn.danger { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
.modalrq-container { max-width:min(1180px, calc(100vw - 32px)); }
@media (max-width:900px) {
    .rq-plan-group-head, .rq-plan-activity-grid, .rq-plan-supervisors, .rq-plan-transport-row { grid-template-columns:1fr; }
    .rq-plan-activity-wide { grid-column:auto; }
}
</style>

<script>
window.rqMinaPlanEditors = window.rqMinaPlanEditors || {};
window.rqMinaPlanEditorSet = function(editorId, plan) {
    const instance = window.rqMinaPlanEditors[editorId];
    if (instance) instance.setPlan(plan || []);
};

function initRQMinaPlanEditor(root) {
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    const editorId = root.id;
    const body = root.querySelector('[data-plan-body]');
    const initialScript = document.querySelector('script[data-rq-plan-initial="' + editorId + '"]');
    let plan = initialScript ? JSON.parse(initialScript.textContent || '[]') : [];

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function dateInputs() {
        const form = root.closest('form') || document;
        return {
            start: form.querySelector('input[name="fecha_inicio"]'),
            end: form.querySelector('input[name="fecha_fin"]'),
        };
    }

    function weekDays(existingTurnos) {
        const turnos = Array.isArray(existingTurnos) ? existingTurnos : [];
        const byExisting = turnos.filter(t => t.fecha || t.dia_label);
        if (byExisting.length) return byExisting;

        const inputs = dateInputs();
        const startValue = inputs.start ? inputs.start.value : '';
        const endValue = inputs.end ? inputs.end.value : '';
        if (!startValue) {
            return ['Lun','Mar','Mie','Jue','Vie','Sab','Dom'].map((label, index) => ({ fecha: '', dia_label: label, orden: index + 1 }));
        }

        const start = new Date(startValue + 'T00:00:00');
        const end = endValue ? new Date(endValue + 'T00:00:00') : new Date(start);
        const max = 14;
        const rows = [];
        for (let cursor = new Date(start), i = 0; cursor <= end && i < max; cursor.setDate(cursor.getDate() + 1), i++) {
            const iso = cursor.toISOString().slice(0, 10);
            rows.push({ fecha: iso, dia_label: cursor.toLocaleDateString('es-PE', { weekday: 'short', day: '2-digit', month: '2-digit' }) });
        }
        return rows.length ? rows : [{ fecha: startValue, dia_label: startValue }];
    }

    function optionAttr(fieldKey) {
        return fieldKey ? ' data-rq-option-field="' + escapeHtml(fieldKey) + '"' : '';
    }

    function personalAttr(enabled, type = 'personal') {
        return enabled ? ' data-rq-personal-search="1" data-rq-personal-type="' + escapeHtml(type) + '"' : '';
    }

    function input(name, value, placeholder = '', extraClass = '', fieldKey = '', personalSearch = false) {
        const autocomplete = (fieldKey || personalSearch) ? ' autocomplete="off"' : '';
        return '<input type="text" class="rq-plan-input ' + extraClass + '" name="' + name + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '"' + optionAttr(fieldKey) + personalAttr(personalSearch) + autocomplete + '>';
    }

    function textarea(name, value, placeholder = '', fieldKey = '') {
        return '<textarea class="rq-plan-textarea" name="' + name + '" placeholder="' + escapeHtml(placeholder) + '"' + optionAttr(fieldKey) + '>' + escapeHtml(value) + '</textarea>';
    }

    function activityTemplate(groupIndex, activityIndex, activity) {
        const key = activity.client_key || activity.id || (Date.now() + '-' + groupIndex + '-' + activityIndex);
        const prefix = 'plan_operativo[' + groupIndex + '][actividades][' + activityIndex + ']';
        const days = weekDays(activity.turnos || []);
        const byDate = {};
        (activity.turnos || []).forEach(t => { byDate[t.fecha || t.dia_label || ''] = t; });
        const scheduleRows = days.map((day, turnIndex) => {
            const saved = byDate[day.fecha || day.dia_label || ''] || day;
            const sp = prefix + '[turnos][' + turnIndex + ']';
            return '<tr>' +
                '<th>' + escapeHtml(day.dia_label || day.fecha || '') +
                    '<input type="hidden" name="' + sp + '[fecha]" value="' + escapeHtml(day.fecha || '') + '">' +
                    '<input type="hidden" name="' + sp + '[dia_label]" value="' + escapeHtml(day.dia_label || '') + '">' +
                '</th>' +
                '<td>' + input(sp + '[turno_a]', saved.turno_a || '', 'Turno A', '', 'rq_mina.plan.turno_a') + '</td>' +
                '<td>' + input(sp + '[turno_b]', saved.turno_b || '', 'Turno B', '', 'rq_mina.plan.turno_b') + '</td>' +
                '<td>' + input(sp + '[real]', saved.real || '', 'Real', '', 'rq_mina.plan.real') + '</td>' +
            '</tr>';
        }).join('');

        return '<div class="rq-plan-activity" data-activity-index="' + activityIndex + '">' +
            '<input type="hidden" name="' + prefix + '[client_key]" value="' + escapeHtml(key) + '">' +
            '<div class="rq-plan-activity-grid">' +
                '<div class="rq-plan-field"><label>SAIT / Punto</label>' + input(prefix + '[sait]', activity.sait || '', 'Ej. SAIT 01', '', 'rq_mina.plan.sait') + '</div>' +
                '<div class="rq-plan-field"><label>Sector</label>' + input(prefix + '[sector]', activity.sector || '', 'Sector', '', 'rq_mina.plan.sector') + '</div>' +
                '<div class="rq-plan-field"><label>Area</label>' + input(prefix + '[area]', activity.area || '', 'Area', '', 'rq_mina.plan.actividad_area') + '</div>' +
                '<div class="rq-plan-field rq-plan-activity-wide"><label>AIT trabajo</label>' + textarea(prefix + '[ait_trabajo]', activity.ait_trabajo || '', 'Puede contener mas de un AIT', 'rq_mina.plan.ait_trabajo') + '</div>' +
                '<div class="rq-plan-field rq-plan-activity-wide"><label>Trabajos relevantes</label>' + textarea(prefix + '[detalle_trabajos_relevantes]', activity.detalle_trabajos_relevantes || '', 'Detalle de trabajos relevantes', 'rq_mina.plan.trabajos_relevantes') + '</div>' +
            '</div>' +
            '<div class="rq-plan-supervisors">' +
                '<div class="rq-plan-field"><label>Sup. campo dia</label>' + input(prefix + '[supervisor_campo_dia]', activity.supervisor_campo_dia || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. campo noche</label>' + input(prefix + '[supervisor_campo_noche]', activity.supervisor_campo_noche || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad dia</label>' + input(prefix + '[supervisor_seguridad_dia]', activity.supervisor_seguridad_dia || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad noche</label>' + input(prefix + '[supervisor_seguridad_noche]', activity.supervisor_seguridad_noche || '', 'Buscar personal', '', '', true) + '</div>' +
            '</div>' +
            '<div class="rq-plan-schedule"><table><thead><tr><th>Dia</th><th>Turno A / Dia</th><th>Turno B / Noche</th><th>Real</th></tr></thead><tbody>' + scheduleRows + '</tbody></table></div>' +
            '<div style="margin-top:8px;text-align:right;"><button type="button" class="rq-plan-btn danger" data-remove-activity>Quitar actividad</button></div>' +
        '</div>';
    }

    function transportTemplate(groupIndex, transportIndex, row) {
        const prefix = 'plan_operativo[' + groupIndex + '][transportes][' + transportIndex + ']';
        return '<div class="rq-plan-transport-row">' +
            input(prefix + '[alcance]', row.alcance || '', 'Ej. Sector, area, SAIT o varios SAIT', '', 'rq_mina.plan.transporte_alcance') +
            input(prefix + '[unidad_carga]', row.unidad_carga || '', 'Unidad de carga', '', 'rq_mina.plan.unidad_carga') +
            textarea(prefix + '[unidades_transporte]', row.unidades_transporte || '', 'Van 15, minibus 35, alquilado...', 'rq_mina.plan.unidades_transporte') +
            textarea(prefix + '[indicaciones]', row.indicaciones || '', 'Ej. Desde miercoles turno A', 'rq_mina.plan.transporte_indicaciones') +
            '<button type="button" class="rq-plan-btn danger" data-remove-transport>Quitar</button>' +
        '</div>';
    }

    function render() {
        body.innerHTML = '';
        if (!plan.length) {
            body.innerHTML = '<div class="rq-plan-empty">Sin areas cargadas. Agrega un area de la parada para registrar SAIT, actividades, turnos y transporte.</div>';
        }

        plan.forEach((group, groupIndex) => {
            const groupPrefix = 'plan_operativo[' + groupIndex + ']';
            const activities = Array.isArray(group.actividades) && group.actividades.length ? group.actividades : [];
            const transports = Array.isArray(group.transportes) && group.transportes.length ? group.transportes : [];
            const html = '<div class="rq-plan-group" data-group-index="' + groupIndex + '">' +
                '<div class="rq-plan-group-head">' +
                    '<div class="rq-plan-group-main">' +
                        '<div class="rq-plan-field"><label>Area</label>' + input(groupPrefix + '[area_operativa]', group.area_operativa || '', 'C1, C2', '', 'rq_mina.plan.area_operativa') + '</div>' +
                        '<div class="rq-plan-field"><label>Modulo</label>' + input(groupPrefix + '[modulo]', group.modulo || '', 'Seca, Humeda', '', 'rq_mina.plan.modulo') + '</div>' +
                        '<div class="rq-plan-field"><label>Grupo / frente</label>' + input(groupPrefix + '[nombre]', group.nombre || ('Grupo ' + (groupIndex + 1)), 'Grupo', '', 'rq_mina.plan.grupo_nombre') + '</div>' +
                        '<div class="rq-plan-field"><label>Observaciones</label>' + input(groupPrefix + '[observaciones]', group.observaciones || '', 'Observaciones', '', 'rq_mina.plan.grupo_observaciones') + '</div>' +
                    '</div>' +
                    '<button type="button" class="rq-plan-btn danger" data-remove-group>Quitar grupo</button>' +
                '</div>' +
                '<div class="rq-plan-section">' +
                    '<div class="rq-plan-section-title"><h4>Lista de actividades</h4><button type="button" class="rq-plan-btn" data-add-activity>Agregar actividad</button></div>' +
                    '<div data-activities>' + activities.map((activity, i) => activityTemplate(groupIndex, i, activity)).join('') + '</div>' +
                '</div>' +
                '<div class="rq-plan-section">' +
                    '<div class="rq-plan-section-title"><h4>Unidades de carga y transporte</h4><button type="button" class="rq-plan-btn" data-add-transport>Agregar transporte</button></div>' +
                    '<div data-transports>' + transports.map((transport, i) => transportTemplate(groupIndex, i, transport)).join('') + '</div>' +
                '</div>' +
            '</div>';
            body.insertAdjacentHTML('beforeend', html);
        });

        if (window.RQMinaFieldOptions) {
            window.RQMinaFieldOptions.refresh(root);
        }
        if (window.RQMinaPersonalAutocomplete) {
            window.RQMinaPersonalAutocomplete.refresh(root);
        }
    }

    function fieldValue(scope, suffix) {
        const field = scope.querySelector('[name$="' + suffix + '"]');
        return field ? field.value : '';
    }

    function syncFromDom() {
        const next = [];
        body.querySelectorAll('.rq-plan-group').forEach((groupEl) => {
            const group = {
                area_operativa: fieldValue(groupEl, '[area_operativa]'),
                modulo: fieldValue(groupEl, '[modulo]'),
                nombre: fieldValue(groupEl, '[nombre]'),
                observaciones: fieldValue(groupEl, '[observaciones]'),
                actividades: [],
                transportes: [],
            };

            groupEl.querySelectorAll('.rq-plan-activity').forEach((activityEl) => {
                const activity = {
                    client_key: fieldValue(activityEl, '[client_key]'),
                    sait: fieldValue(activityEl, '[sait]'),
                    sector: fieldValue(activityEl, '[sector]'),
                    area: fieldValue(activityEl, '[area]'),
                    ait_trabajo: fieldValue(activityEl, '[ait_trabajo]'),
                    detalle_trabajos_relevantes: fieldValue(activityEl, '[detalle_trabajos_relevantes]'),
                    supervisor_campo_dia: fieldValue(activityEl, '[supervisor_campo_dia]'),
                    supervisor_campo_noche: fieldValue(activityEl, '[supervisor_campo_noche]'),
                    supervisor_seguridad_dia: fieldValue(activityEl, '[supervisor_seguridad_dia]'),
                    supervisor_seguridad_noche: fieldValue(activityEl, '[supervisor_seguridad_noche]'),
                    turnos: [],
                };

                activityEl.querySelectorAll('.rq-plan-schedule tbody tr').forEach((row) => {
                    activity.turnos.push({
                        fecha: fieldValue(row, '[fecha]'),
                        dia_label: fieldValue(row, '[dia_label]'),
                        turno_a: fieldValue(row, '[turno_a]'),
                        turno_b: fieldValue(row, '[turno_b]'),
                        real: fieldValue(row, '[real]'),
                    });
                });

                group.actividades.push(activity);
            });

            groupEl.querySelectorAll('.rq-plan-transport-row').forEach((transportEl) => {
                group.transportes.push({
                    alcance: fieldValue(transportEl, '[alcance]'),
                    unidad_carga: fieldValue(transportEl, '[unidad_carga]'),
                    unidades_transporte: fieldValue(transportEl, '[unidades_transporte]'),
                    indicaciones: fieldValue(transportEl, '[indicaciones]'),
                });
            });

            next.push(group);
        });

        plan = next;
        return plan;
    }

    root.addEventListener('click', function(event) {
        syncFromDom();
        const groupEl = event.target.closest('.rq-plan-group');
        const groupIndex = groupEl ? Number(groupEl.dataset.groupIndex) : -1;

        if (event.target.matches('[data-add-plan-group]')) {
            plan.push({ area_operativa: '', modulo: '', nombre: 'Grupo ' + (plan.length + 1), observaciones: '', actividades: [{}], transportes: [{}] });
            render();
        }
        if (event.target.matches('[data-remove-group]') && groupIndex >= 0) {
            plan.splice(groupIndex, 1);
            render();
        }
        if (event.target.matches('[data-add-activity]') && groupIndex >= 0) {
            plan[groupIndex].actividades = plan[groupIndex].actividades || [];
            plan[groupIndex].actividades.push({});
            render();
        }
        if (event.target.matches('[data-remove-activity]') && groupIndex >= 0) {
            const activityEl = event.target.closest('.rq-plan-activity');
            plan[groupIndex].actividades.splice(Number(activityEl.dataset.activityIndex), 1);
            render();
        }
        if (event.target.matches('[data-add-transport]') && groupIndex >= 0) {
            plan[groupIndex].transportes = plan[groupIndex].transportes || [];
            plan[groupIndex].transportes.push({});
            render();
        }
        if (event.target.matches('[data-remove-transport]') && groupIndex >= 0) {
            const rows = Array.from(groupEl.querySelectorAll('[data-remove-transport]'));
            plan[groupIndex].transportes.splice(rows.indexOf(event.target), 1);
            render();
        }
    });

    window.rqMinaPlanEditors[editorId] = {
        setPlan: function(nextPlan) {
            plan = Array.isArray(nextPlan) ? nextPlan : [];
            render();
        },
        getPlan: syncFromDom,
    };

    render();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-rq-plan-editor]').forEach(initRQMinaPlanEditor);
});
</script>
@endonce

<div id="{{ $editorId }}" class="rq-plan-editor" data-rq-plan-editor>
    <div class="rq-plan-head">
        <div>
            <h3>Areas y plan operativo semanal</h3>
            <span>Primero registra las areas de la parada; dentro de cada area carga SAIT, actividades, turnos, supervisores y transporte.</span>
            @if($weekNumber)
                <span class="rq-plan-week-pill">Semana {{ $weekNumber }} / {{ $weekYear }}</span>
            @endif
        </div>
        <div class="rq-plan-actions">
            <button type="button" class="rq-plan-btn primary" data-add-plan-group>Agregar area</button>
        </div>
    </div>
    <div class="rq-plan-body" data-plan-body></div>
</div>

<script type="application/json" data-rq-plan-initial="{{ $editorId }}">@json($planOperativo)</script>
