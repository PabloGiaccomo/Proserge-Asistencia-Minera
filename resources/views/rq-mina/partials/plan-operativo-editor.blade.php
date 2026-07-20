@php
    $editorId = $editorId ?? 'rqPlanOperativoEditor';
    $planOperativo = $planOperativo ?? [];
    $weekNumber = $weekNumber ?? null;
    $weekYear = $weekYear ?? null;
    $paradaFechaInicio = $paradaFechaInicio ?? '';
    $paradaFechaFin = $paradaFechaFin ?? '';
@endphp

@once

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
        return ['Lun','Mar','Mie','Jue','Vie','Sab','Dom'].map((label, index) => ({
            fecha: '',
            dia_label: label,
            orden: index + 1,
            key: label.toLowerCase(),
        }));
    }

    function weekDayKey(value) {
        const normalized = String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .slice(0, 3);
        const keys = {
            lun: 'lun',
            mar: 'mar',
            mie: 'mie',
            jue: 'jue',
            vie: 'vie',
            sab: 'sab',
            dom: 'dom',
        };

        return keys[normalized] || '';
    }

    function optionAttr(fieldKey) {
        return fieldKey ? ' data-rq-option-field="' + escapeHtml(fieldKey) + '"' : '';
    }

    function personalAttr(enabled, type = 'personal') {
        if (!enabled) return '';

        const searchType = typeof enabled === 'string' ? enabled : type;
        return ' data-rq-personal-search="1" data-rq-personal-type="' + escapeHtml(searchType) + '"';
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
        const byDay = {};
        const byIndex = {};
        (activity.turnos || []).forEach((turno, index) => {
            const key = weekDayKey(turno.dia_label || '');
            if (key && !byDay[key]) {
                byDay[key] = turno;
            }
            byIndex[index] = turno;
        });
        const scheduleRows = days.map((day, turnIndex) => {
            const saved = byDay[day.key] || byIndex[turnIndex] || day;
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
                '<div class="rq-plan-field"><label>Sup. campo dia</label>' + input(prefix + '[supervisor_campo_dia]', activity.supervisor_campo_dia || '', 'Buscar supervisor', '', '', 'supervisor') + '</div>' +
                '<div class="rq-plan-field"><label>Sup. campo noche</label>' + input(prefix + '[supervisor_campo_noche]', activity.supervisor_campo_noche || '', 'Buscar supervisor', '', '', 'supervisor') + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad dia</label>' + input(prefix + '[supervisor_seguridad_dia]', activity.supervisor_seguridad_dia || '', 'Buscar supervisor', '', '', 'supervisor') + '</div>' +
                '<div class="rq-plan-field"><label>Sup. seguridad noche</label>' + input(prefix + '[supervisor_seguridad_noche]', activity.supervisor_seguridad_noche || '', 'Buscar supervisor', '', '', 'supervisor') + '</div>' +
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
