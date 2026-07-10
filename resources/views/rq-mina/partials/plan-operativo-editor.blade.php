@php
    $editorId = $editorId ?? 'rqPlanOperativoEditor';
    $planOperativo = $planOperativo ?? [];
    $weekNumber = $weekNumber ?? null;
    $weekYear = $weekYear ?? null;
    $paradaFechaInicio = $paradaFechaInicio ?? '';
    $paradaFechaFin = $paradaFechaFin ?? '';
@endphp

@once
<style>
.rq-plan-editor { border:1px solid #dbe4ef; border-radius:12px; background:#fff; overflow:hidden; }
.rq-plan-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.rq-plan-head h3 { margin:0; font-size:15px; color:#0f172a; }
.rq-plan-head span { display:block; margin-top:2px; font-size:12px; color:#64748b; }
.rq-plan-week-pill { display:inline-flex; align-items:center; border-radius:999px; background:#ecfeff; color:#0e7490; padding:5px 10px; font-size:12px; font-weight:800; margin-top:8px; }
.rq-plan-actions { display:flex; gap:8px; flex-wrap:wrap; }
.rq-plan-toggle { width:38px; min-width:38px; height:38px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
.rq-plan-toggle-icon { display:inline-block; line-height:1; transition:transform .18s ease; }
.rq-plan-editor.is-collapsed .rq-plan-toggle-icon { transform:rotate(180deg); }
.rq-plan-body { display:flex; flex-direction:column; gap:14px; padding:14px; }
.rq-plan-body[hidden] { display:none; }
.rq-plan-empty { border:1px dashed #cbd5e1; border-radius:10px; padding:18px; color:#64748b; background:#f8fafc; font-size:13px; text-align:center; }
.rq-plan-group { border:1px solid #e2e8f0; border-radius:10px; background:#fff; overflow:hidden; }
.rq-plan-group-head { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)) auto; gap:10px; padding:12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; align-items:end; }
.rq-plan-group-main { display:contents; }
.rq-plan-group-actions { display:flex; gap:8px; align-items:center; justify-content:flex-end; }
.rq-plan-group-toggle { width:38px; min-width:38px; height:38px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
.rq-plan-group-toggle.is-collapsed { border-color:#fde68a; background:#fffbeb; color:#92400e; }
.rq-plan-group-toggle-icon { display:inline-block; line-height:1; transition:transform .18s ease; }
.rq-plan-group.is-group-collapsed .rq-plan-group-head { border-bottom:0; }
.rq-plan-group.is-group-collapsed [data-group-content] { display:none; }
.rq-plan-field label { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:5px; text-transform:uppercase; }
.rq-plan-input, .rq-plan-textarea, .rq-plan-select { width:100%; border:1px solid #dbe4ef; border-radius:8px; padding:8px 9px; font-size:12px; color:#0f172a; background:#fff; }
.rq-plan-textarea { min-height:38px; resize:vertical; }
.rq-plan-section { padding:12px; border-top:1px solid #f1f5f9; }
.rq-plan-section:first-of-type { border-top:0; }
.rq-plan-section-title { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.rq-plan-section-title h4 { margin:0; font-size:13px; color:#0f172a; }
.rq-plan-activity { border:1px solid #e2e8f0; border-radius:10px; padding:10px; margin-bottom:10px; background:#fcfdff; }
.rq-plan-activity-head { display:grid; grid-template-columns:minmax(120px,1fr) minmax(120px,1fr) minmax(120px,1fr) minmax(220px,2fr) auto; gap:8px; align-items:end; }
.rq-plan-activity-actions { display:flex; gap:8px; align-items:center; justify-content:flex-end; }
.rq-plan-activity-toggle { width:38px; min-width:38px; height:38px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
.rq-plan-activity-toggle.is-collapsed { border-color:#fde68a; background:#fffbeb; color:#92400e; }
.rq-plan-activity-toggle-icon { display:inline-block; line-height:1; }
.rq-plan-activity.is-activity-collapsed [data-activity-content] { display:none; }
.rq-plan-activity-grid { display:grid; grid-template-columns:repeat(5,minmax(120px,1fr)); gap:8px; }
.rq-plan-activity-wide { grid-column:span 2; }
.rq-plan-supervisors { display:grid; grid-template-columns:repeat(4,minmax(120px,1fr)); gap:8px; margin-top:8px; }
.rq-plan-schedule { margin-top:10px; overflow:auto; border:1px solid #e2e8f0; border-radius:8px; }
.rq-plan-schedule table { width:100%; border-collapse:collapse; min-width:760px; }
.rq-plan-schedule th, .rq-plan-schedule td { border-bottom:1px solid #eef2f7; border-right:1px solid #eef2f7; padding:6px; font-size:11px; vertical-align:top; }
.rq-plan-schedule th { background:#f8fafc; color:#475569; text-align:left; }
.rq-plan-schedule td:last-child, .rq-plan-schedule th:last-child { border-right:0; }
.rq-plan-schedule input { width:100%; min-width:70px; border:1px solid #dbe4ef; border-radius:6px; padding:6px; font-size:11px; }
.rq-plan-schedule input.rq-plan-real-input {
    background:#f1f5f9;
    border-color:#cbd5e1;
    color:#64748b;
    cursor:not-allowed;
}
.rq-plan-schedule input.rq-plan-real-input:focus {
    outline:none;
    box-shadow:none;
    border-color:#cbd5e1;
}
.rq-plan-input.rq-plan-real-input {
    background:#f1f5f9;
    border-color:#cbd5e1;
    color:#64748b;
    cursor:not-allowed;
}
.rq-plan-saits-grid { display:flex; flex-wrap:wrap; gap:6px; }
.rq-plan-sait-chip { display:inline-flex; align-items:center; gap:5px; border:1.5px solid #dbe4ef; border-radius:999px; padding:5px 12px; background:#fff; color:#334155; font-size:12px; font-weight:700; cursor:pointer; transition:all .18s cubic-bezier(.16,1,.3,1); user-select:none; }
.rq-plan-sait-chip:hover { border-color:#0d9488; background:#f0fdfa; transform:translateY(-1px); box-shadow:0 2px 8px rgba(13,148,136,.12); }
.rq-plan-sait-chip.is-checked { border-color:#0d9488; background:linear-gradient(135deg,#ccfbf1,#f0fdfa); color:#0f766e; box-shadow:0 0 0 1.5px #0d9488,0 2px 6px rgba(13,148,136,.15); }
.rq-plan-sait-chip:active { transform:translateY(0); }
.rq-plan-sait-check { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.rq-plan-empty-saits { padding:8px 0; color:#94a3b8; font-size:12px; font-style:italic; }
/* --- Transporte: tarjeta con diseno mejorado --- */
.rq-plan-transport-row { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.04); margin-bottom:16px; overflow:hidden; transition:box-shadow .2s ease,border-color .2s ease; animation:rqTransportSlideIn .3s cubic-bezier(.16,1,.3,1); }
.rq-plan-transport-row:hover { border-color:#cbd5e1; box-shadow:0 4px 12px rgba(0,0,0,.06),0 2px 4px rgba(0,0,0,.04); }
.rq-plan-transport-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:linear-gradient(135deg,#f8fafc,#f1f5f9); border-bottom:1px solid #eef2f7; }
.rq-plan-transport-number { font-size:12px; font-weight:700; color:#1e293b; display:inline-flex; align-items:center; gap:8px; }
.rq-plan-transport-number-badge { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#0f766e; color:#fff; border-radius:6px; font-size:11px; font-weight:800; line-height:1; }
.rq-plan-transport-body { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:14px; padding:14px; align-items:start; }
.rq-plan-transport-field { min-width:0; }
.rq-plan-transport-field.span-2 { grid-column:span 2; }
.rq-plan-transport-field.span-3 { grid-column:span 3; }
.rq-plan-transport-field.span-4 { grid-column:span 4; }
.rq-plan-transport-field.span-5 { grid-column:span 5; }
.rq-plan-transport-field.span-6 { grid-column:span 6; }
.rq-plan-transport-field.span-12 { grid-column:1 / -1; }
.rq-plan-transport-remove { border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; border-radius:6px; padding:5px 10px; font-size:11px; font-weight:700; cursor:pointer; transition:all .15s ease; display:inline-flex; align-items:center; gap:4px; }
.rq-plan-transport-remove:hover { background:#fee2e2; border-color:#f87171; }
.rq-plan-transport-hint { display:block; margin-top:4px; color:#94a3b8; font-size:10px; line-height:1.4; }

/* Boton agregar transporte */
.rq-plan-btn-add-transport { border:2px dashed #cbd5e1; background:#f8fafc; color:#334155; border-radius:10px; padding:9px 14px; font-size:12px; font-weight:700; cursor:pointer; transition:all .2s ease; display:inline-flex; align-items:center; gap:6px; }
.rq-plan-btn-add-transport:hover { border-color:#0d9488; background:#ecfeff; color:#0f766e; }

/* Badge de conteo */
.rq-plan-transport-count { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:10px; font-weight:800; padding:0 6px; line-height:1; vertical-align:middle; }

/* Empty state transporte */
.rq-plan-transport-empty { border:2px dashed #e2e8f0; border-radius:12px; padding:24px; text-align:center; color:#94a3b8; font-size:12px; background:#fafbfc; line-height:1.6; }

@keyframes rqTransportSlideIn { from { opacity:0; transform:translateY(-6px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } }
.rq-plan-btn { border:1px solid #cbd5e1; background:#fff; color:#0f172a; border-radius:8px; padding:8px 10px; font-size:12px; font-weight:700; cursor:pointer; }
.rq-plan-btn:hover { background:#f8fafc; }
.rq-plan-btn.primary { border-color:#0f766e; background:#0f766e; color:#fff; }
.rq-plan-btn.danger { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
.modalrq-container { max-width:min(1180px, calc(100vw - 32px)); }
@media (max-width:900px) {
    .rq-plan-group-head, .rq-plan-activity-head, .rq-plan-activity-grid, .rq-plan-supervisors { grid-template-columns:1fr; }
    .rq-plan-transport-body { grid-template-columns:1fr; }
    .rq-plan-transport-field,
    .rq-plan-transport-field.span-2,
    .rq-plan-transport-field.span-3,
    .rq-plan-transport-field.span-4,
    .rq-plan-transport-field.span-5,
    .rq-plan-transport-field.span-6,
    .rq-plan-transport-field.span-12 { grid-column:auto; }
    .rq-plan-group-actions { justify-content:flex-start; }
    .rq-plan-activity-actions { justify-content:flex-start; }
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
    const toggleButton = root.querySelector('[data-toggle-plan-body]');
    const initialScript = document.querySelector('script[data-rq-plan-initial="' + editorId + '"]');
    let plan = initialScript ? JSON.parse(initialScript.textContent || '[]') : [];
    let isCollapsed = false;
    const collapsedGroups = new Set();
    const collapsedActivities = new Set();

    function applyCollapseState() {
        root.classList.toggle('is-collapsed', isCollapsed);
        if (body) {
            body.hidden = isCollapsed;
        }
        if (toggleButton) {
            toggleButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            toggleButton.setAttribute('title', isCollapsed ? 'Mostrar plan operativo' : 'Ocultar plan operativo');
            toggleButton.setAttribute('aria-label', isCollapsed ? 'Mostrar plan operativo' : 'Ocultar plan operativo');
        }
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function applyGroupCollapse(groupEl, collapsed) {
        if (!groupEl) return;

        groupEl.classList.toggle('is-group-collapsed', collapsed);
        const button = groupEl.querySelector('[data-toggle-group]');
        const icon = groupEl.querySelector('[data-group-toggle-icon]');

        if (button) {
            button.classList.toggle('is-collapsed', collapsed);
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('title', collapsed ? 'Mostrar actividades del grupo' : 'Ocultar actividades del grupo');
            button.setAttribute('aria-label', collapsed ? 'Mostrar actividades del grupo' : 'Ocultar actividades del grupo');
        }
        if (icon) {
            icon.innerHTML = collapsed ? '&darr;' : '&uarr;';
        }
    }

    function applyActivityCollapse(activityEl, collapsed) {
        if (!activityEl) return;

        activityEl.classList.toggle('is-activity-collapsed', collapsed);
        const button = activityEl.querySelector('[data-toggle-activity]');
        const icon = activityEl.querySelector('[data-activity-toggle-icon]');

        if (button) {
            button.classList.toggle('is-collapsed', collapsed);
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('title', collapsed ? 'Mostrar detalle de actividad' : 'Ocultar detalle de actividad');
            button.setAttribute('aria-label', collapsed ? 'Mostrar detalle de actividad' : 'Ocultar detalle de actividad');
        }
        if (icon) {
            icon.innerHTML = collapsed ? '&darr;' : '&uarr;';
        }
    }

    function activityCollapseKey(activityEl) {
        const field = activityEl ? activityEl.querySelector('[name$="[client_key]"]') : null;
        const groupEl = activityEl ? activityEl.closest('.rq-plan-group') : null;
        const fallback = (groupEl?.dataset.groupIndex || '0') + ':' + (activityEl?.dataset.activityIndex || '0');

        return field?.value || fallback;
    }

    function paradaFechas() {
        return {
            inicio: '{{ $paradaFechaInicio }}',
            fin: '{{ $paradaFechaFin }}',
        };
    }

    function weekDays(existingTurnos) {
        const turnos = Array.isArray(existingTurnos) ? existingTurnos : [];
        const byExisting = turnos.filter(t => t.fecha || t.dia_label);
        if (byExisting.length) return byExisting;

        const fechas = paradaFechas();
        const startValue = fechas.inicio;
        const endValue = fechas.fin;
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

    function input(name, value, placeholder = '', extraClass = '', fieldKey = '', personalSearch = false, attributes = '') {
        const autocomplete = attributes.indexOf('autocomplete=') >= 0 ? '' : ((fieldKey || personalSearch) ? ' autocomplete="off"' : '');
        return '<input type="text" class="rq-plan-input ' + extraClass + '" name="' + name + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '"' + optionAttr(fieldKey) + personalAttr(personalSearch) + autocomplete + attributes + '>';
    }

    function realInput(name, value, placeholder = '') {
        return '<input type="text" class="rq-plan-input rq-plan-real-input" name="' + name + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '" readonly aria-readonly="true" autocomplete="off" tabindex="-1">';
    }

    function dateInput(name, value, placeholder = '', extraClass = '') {
        return '<input type="date" class="rq-plan-input ' + extraClass + '" name="' + name + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '" autocomplete="off">';
    }

    function selectInput(name, value, options, extraClass = '') {
        const current = String(value || '');
        const optionHtml = options.map(function(option) {
            const selected = current === String(option.value) ? ' selected' : '';
            return '<option value="' + escapeHtml(option.value) + '"' + selected + '>' + escapeHtml(option.label) + '</option>';
        }).join('');

        return '<select class="rq-plan-select ' + extraClass + '" name="' + name + '">' + optionHtml + '</select>';
    }

    function textarea(name, value, placeholder = '', fieldKey = '') {
        return '<textarea class="rq-plan-textarea" name="' + name + '" placeholder="' + escapeHtml(placeholder) + '"' + optionAttr(fieldKey) + '>' + escapeHtml(value) + '</textarea>';
    }

    function transportField(label, content, span = 3, hint = '') {
        return '<div class="rq-plan-field rq-plan-transport-field span-' + span + '"><label>' + escapeHtml(label) + '</label>' + content + (hint ? '<span class="rq-plan-transport-hint">' + escapeHtml(hint) + '</span>' : '') + '</div>';
    }

    function activityTemplate(groupIndex, activityIndex, activity) {
        const key = activity.client_key || activity.id || (Date.now() + '-' + groupIndex + '-' + activityIndex);
        const prefix = 'plan_operativo[' + groupIndex + '][actividades][' + activityIndex + ']';
        const activityCollapsed = collapsedActivities.has(String(key));
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
                '<td>' + input(sp + '[turno_a]', saved.turno_a || '', 'Turno A', '', '', false, ' autocomplete="off" inputmode="numeric"') + '</td>' +
                '<td>' + realInput(sp + '[real_turno_a]', saved.real_turno_a || '', 'Real turno A') + '</td>' +
                '<td>' + input(sp + '[turno_b]', saved.turno_b || '', 'Turno B', '', '', false, ' autocomplete="off" inputmode="numeric"') + '</td>' +
                '<td>' + realInput(sp + '[real_turno_b]', saved.real_turno_b || saved.real || '', 'Real turno B') + '</td>' +
            '</tr>';
        }).join('');

        return '<div class="rq-plan-activity' + (activityCollapsed ? ' is-activity-collapsed' : '') + '" data-activity-index="' + activityIndex + '">' +
            '<input type="hidden" name="' + prefix + '[client_key]" value="' + escapeHtml(key) + '">' +
            '<div class="rq-plan-activity-head">' +
                '<div class="rq-plan-field"><label>SAIT / Punto</label>' + input(prefix + '[sait]', activity.sait || '', 'Ej. SAIT 01', '', 'rq_mina.plan.sait') + '</div>' +
                '<div class="rq-plan-field"><label>Sector</label>' + input(prefix + '[sector]', activity.sector || '', 'Sector', '', 'rq_mina.plan.sector') + '</div>' +
                '<div class="rq-plan-field"><label>Area</label>' + input(prefix + '[area]', activity.area || '', 'Area', '', 'rq_mina.plan.actividad_area') + '</div>' +
                '<div class="rq-plan-field"><label>AIT trabajo</label>' + textarea(prefix + '[ait_trabajo]', activity.ait_trabajo || '', 'Puede contener mas de un AIT', 'rq_mina.plan.ait_trabajo') + '</div>' +
                '<div class="rq-plan-activity-actions">' +
                    '<button type="button" class="rq-plan-btn danger" data-remove-activity>Quitar actividad</button>' +
                    '<button type="button" class="rq-plan-btn rq-plan-activity-toggle' + (activityCollapsed ? ' is-collapsed' : '') + '" data-toggle-activity aria-expanded="' + (activityCollapsed ? 'false' : 'true') + '" title="' + (activityCollapsed ? 'Mostrar detalle de actividad' : 'Ocultar detalle de actividad') + '" aria-label="' + (activityCollapsed ? 'Mostrar detalle de actividad' : 'Ocultar detalle de actividad') + '">' +
                        '<span class="rq-plan-activity-toggle-icon" data-activity-toggle-icon>' + (activityCollapsed ? '&darr;' : '&uarr;') + '</span>' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div data-activity-content>' +
            '<div class="rq-plan-activity-grid" style="margin-top:8px;">' +
                '<div class="rq-plan-field rq-plan-activity-wide"><label>Trabajos relevantes</label>' + textarea(prefix + '[detalle_trabajos_relevantes]', activity.detalle_trabajos_relevantes || '', 'Detalle de trabajos relevantes', 'rq_mina.plan.trabajos_relevantes') + '</div>' +
            '</div>' +
            '<div class="rq-plan-supervisors">' +
                '<div class="rq-plan-field"><label>Sup. campo dia</label>' + input(prefix + '[supervisor_campo_dia]', activity.supervisor_campo_dia || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. campo noche</label>' + input(prefix + '[supervisor_campo_noche]', activity.supervisor_campo_noche || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad dia</label>' + input(prefix + '[supervisor_seguridad_dia]', activity.supervisor_seguridad_dia || '', 'Buscar personal', '', '', true) + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad noche</label>' + input(prefix + '[supervisor_seguridad_noche]', activity.supervisor_seguridad_noche || '', 'Buscar personal', '', '', true) + '</div>' +
            '</div>' +
            '<div class="rq-plan-schedule"><table><thead><tr><th>Dia</th><th>Turno A / Dia</th><th>Real turno A</th><th>Turno B / Noche</th><th>Real turno B</th></tr></thead><tbody>' + scheduleRows + '</tbody></table></div>' +
            '</div>' +
        '</div>';
    }

    function getPlanSaits() {
        const saits = [];
        plan.forEach(function(g) {
            const activities = Array.isArray(g.actividades) ? g.actividades : [];
            activities.forEach(function(a) {
                const saitVal = (a.sait || '').trim();
                if (saitVal !== '' && saits.indexOf(saitVal) === -1) {
                    saits.push(saitVal);
                }
            });
        });
        return saits;
    }

    function saitCheckboxesHtml(prefix, currentValue) {
        const saits = getPlanSaits();
        const selected = (currentValue || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);

        if (saits.length === 0) {
            return '<div class="rq-plan-empty-saits">Agrega SAIT / punto en las actividades para seleccionar alcance.</div>' +
                '<input type="hidden" name="' + prefix + '[alcance]" value="' + escapeHtml(currentValue || '') + '">';
        }

        const chips = saits.map(function(sait) {
            const isChecked = selected.indexOf(sait) !== -1;
            return '<label class="rq-plan-sait-chip' + (isChecked ? ' is-checked' : '') + '">' +
                '<input type="checkbox" class="rq-plan-sait-check" value="' + escapeHtml(sait) + '"' + (isChecked ? ' checked' : '') + ' data-alcance-prefix="' + escapeHtml(prefix) + '">' +
                '<span>' + escapeHtml(sait) + '</span>' +
            '</label>';
        }).join('');

        return '<div class="rq-plan-saits-grid" data-alcance-grid="' + escapeHtml(prefix) + '">' + chips + '</div>' +
            '<input type="hidden" class="rq-plan-alcance-input" name="' + prefix + '[alcance]" value="' + escapeHtml(currentValue || '') + '">';
    }

    function transportTemplate(groupIndex, transportIndex, row) {
        const num = transportIndex + 1;
        const prefix = 'plan_operativo[' + groupIndex + '][transportes][' + transportIndex + ']';
        const fechas = paradaFechas();
        const defaultStart = fechas.inicio;
        const defaultEnd = fechas.fin;

        return '<div class="rq-plan-transport-row">' +
            '<div class="rq-plan-transport-header">' +
                '<span class="rq-plan-transport-number"><span class="rq-plan-transport-number-badge">' + num + '</span> Transporte #' + num + '</span>' +
                '<button type="button" class="rq-plan-transport-remove" data-remove-transport>✕ Quitar</button>' +
            '</div>' +
            '<div class="rq-plan-transport-body">' +
                transportField('Alcance (SAIT)', saitCheckboxesHtml(prefix, row.alcance || ''), 3) +
                transportField('Unidad de carga', input(prefix + '[unidad_carga]', row.unidad_carga || '', 'Unidad de carga', '', 'rq_mina.plan.unidad_carga'), 2) +
                transportField('Transporte solicitado', textarea(prefix + '[unidades_transporte]', row.unidades_transporte || '', 'Van 15, minibus 35, alquilado...', 'rq_mina.plan.unidades_transporte'), 5) +
                transportField('Fecha inicio', dateInput(prefix + '[fecha_inicio]', row.fecha_inicio || defaultStart), 2) +
                transportField('Fecha fin', dateInput(prefix + '[fecha_fin]', row.fecha_fin || defaultEnd), 2) +
            '</div>' +
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
            const groupCollapsed = collapsedGroups.has(String(groupIndex));
            const html = '<div class="rq-plan-group' + (groupCollapsed ? ' is-group-collapsed' : '') + '" data-group-index="' + groupIndex + '">' +
                '<div class="rq-plan-group-head">' +
                    '<div class="rq-plan-group-main">' +
                        '<div class="rq-plan-field"><label>Area</label>' + input(groupPrefix + '[area_operativa]', group.area_operativa || '', 'C1, C2', '', 'rq_mina.plan.area_operativa') + '</div>' +
                        '<div class="rq-plan-field"><label>Modulo</label>' + input(groupPrefix + '[modulo]', group.modulo || '', 'Seca, Humeda', '', 'rq_mina.plan.modulo') + '</div>' +
                        '<div class="rq-plan-field"><label>Grupo / frente</label>' + input(groupPrefix + '[nombre]', group.nombre || ('Grupo ' + (groupIndex + 1)), 'Grupo', '', 'rq_mina.plan.grupo_nombre') + '</div>' +
                        '<div class="rq-plan-field"><label>Observaciones</label>' + input(groupPrefix + '[observaciones]', group.observaciones || '', 'Observaciones', '', 'rq_mina.plan.grupo_observaciones') + '</div>' +
                    '</div>' +
                    '<div class="rq-plan-group-actions">' +
                        '<button type="button" class="rq-plan-btn danger" data-remove-group>Quitar grupo</button>' +
                        '<button type="button" class="rq-plan-btn rq-plan-group-toggle' + (groupCollapsed ? ' is-collapsed' : '') + '" data-toggle-group aria-expanded="' + (groupCollapsed ? 'false' : 'true') + '" title="' + (groupCollapsed ? 'Mostrar actividades del grupo' : 'Ocultar actividades del grupo') + '" aria-label="' + (groupCollapsed ? 'Mostrar actividades del grupo' : 'Ocultar actividades del grupo') + '">' +
                            '<span class="rq-plan-group-toggle-icon" data-group-toggle-icon>' + (groupCollapsed ? '&darr;' : '&uarr;') + '</span>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div data-group-content>' +
                '<div class="rq-plan-section">' +
                    '<div class="rq-plan-section-title"><h4>Lista de actividades</h4><button type="button" class="rq-plan-btn" data-add-activity>Agregar actividad</button></div>' +
                    '<div data-activities>' + activities.map((activity, i) => activityTemplate(groupIndex, i, activity)).join('') + '</div>' +
                '</div>' +
                '<div class="rq-plan-section">' +
                    '<div class="rq-plan-section-title"><h4>🚛 Unidades de carga y transporte <span class="rq-plan-transport-count">' + transports.length + '</span></h4><button type="button" class="rq-plan-btn-add-transport" data-add-transport>+ Agregar transporte</button></div>' +
                    '<div data-transports>' + transports.map((transport, i) => transportTemplate(groupIndex, i, transport)).join('') + '</div>' +
                '</div>' +
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
        applyCollapseState();
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
                        real_turno_a: fieldValue(row, '[real_turno_a]'),
                        turno_b: fieldValue(row, '[turno_b]'),
                        real_turno_b: fieldValue(row, '[real_turno_b]'),
                        real: fieldValue(row, '[real_turno_b]'),
                    });
                });

                group.actividades.push(activity);
            });

            groupEl.querySelectorAll('.rq-plan-transport-row').forEach((transportEl) => {
                group.transportes.push({
                    alcance: fieldValue(transportEl, '[alcance]'),
                    unidad_carga: fieldValue(transportEl, '[unidad_carga]'),
                    unidades_transporte: fieldValue(transportEl, '[unidades_transporte]'),
                    fecha_inicio: fieldValue(transportEl, '[fecha_inicio]'),
                    fecha_fin: fieldValue(transportEl, '[fecha_fin]'),
                });
            });

            next.push(group);
        });

        plan = next;
        return plan;
    }

    root.addEventListener('click', function(event) {
        syncFromDom();
        const toggleTarget = event.target.closest('[data-toggle-plan-body]');
        if (toggleTarget && root.contains(toggleTarget)) {
            isCollapsed = !isCollapsed;
            applyCollapseState();
            return;
        }

        const groupEl = event.target.closest('.rq-plan-group');
        const groupIndex = groupEl ? Number(groupEl.dataset.groupIndex) : -1;

        if (event.target.closest('[data-toggle-group]') && groupIndex >= 0) {
            const key = String(groupIndex);
            const nextCollapsed = !collapsedGroups.has(key);
            if (nextCollapsed) {
                collapsedGroups.add(key);
            } else {
                collapsedGroups.delete(key);
            }
            applyGroupCollapse(groupEl, nextCollapsed);
            return;
        }

        const activityToggle = event.target.closest('[data-toggle-activity]');
        if (activityToggle && groupIndex >= 0) {
            const activityEl = activityToggle.closest('.rq-plan-activity');
            const key = activityCollapseKey(activityEl);
            const nextCollapsed = !collapsedActivities.has(key);
            if (nextCollapsed) {
                collapsedActivities.add(key);
            } else {
                collapsedActivities.delete(key);
            }
            applyActivityCollapse(activityEl, nextCollapsed);
            return;
        }

        if (event.target.matches('[data-add-plan-group]')) {
            plan.push({ area_operativa: '', modulo: '', nombre: 'Grupo ' + (plan.length + 1), observaciones: '', actividades: [{}], transportes: [{}] });
            isCollapsed = false;
            collapsedGroups.clear();
            collapsedActivities.clear();
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
            collapsedActivities.delete(activityCollapseKey(activityEl));
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

    root.addEventListener('change', function(event) {
        const saitCheck = event.target.closest('.rq-plan-sait-check');
        if (saitCheck) {
            const chip = saitCheck.closest('.rq-plan-sait-chip');
            if (chip) {
                chip.classList.toggle('is-checked', saitCheck.checked);
            }
            const prefix = saitCheck.dataset.alcancePrefix;
            if (prefix) {
                const grid = root.querySelector('[data-alcance-grid="' + prefix + '"]');
                const hidden = root.querySelector('.rq-plan-alcance-input[name="' + prefix + '[alcance]"]');
                if (grid && hidden) {
                    const checks = Array.from(grid.querySelectorAll('.rq-plan-sait-check:checked'));
                    hidden.value = checks.map(function(cb) { return cb.value; }).join(',');
                    syncFromDom();
                }
            }
            return;
        }
        const transportEl = event.target.closest('.rq-plan-transport-row');
        if (!transportEl) {
            return;
        }

        const name = event.target.getAttribute('name') || '';
        if (name.endsWith('[fecha_inicio]') || name.endsWith('[fecha_fin]')) {
            syncFromDom();
        }
    });

    window.rqMinaPlanEditors[editorId] = {
        setPlan: function(nextPlan) {
            plan = Array.isArray(nextPlan) ? nextPlan : [];
            collapsedGroups.clear();
            collapsedActivities.clear();
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
            <button type="button" class="rq-plan-btn rq-plan-toggle" data-toggle-plan-body aria-expanded="true" title="Ocultar plan operativo" aria-label="Ocultar plan operativo">
                <span class="rq-plan-toggle-icon">&uarr;</span>
            </button>
        </div>
    </div>
    <div class="rq-plan-body" data-plan-body></div>
</div>

<script type="application/json" data-rq-plan-initial="{{ $editorId }}">@json($planOperativo)</script>
