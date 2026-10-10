(() => {
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const runtime = () => {
        const source = document.getElementById('miningEmoRuntime');
        return source ? JSON.parse(source.textContent) : null;
    };
    const field = (label, control) => `<label>${escape(label)}${control}</label>`;
    const outcomePresentation = {
        APTO: { label: 'Apto', color: 'ok' },
        NO_APTO: { label: 'No apto', color: 'danger' },
        APTO_CON_RESTRICCION: { label: 'Apto con restriccion', color: 'warn', detail: 'Restriccion' },
        APTO_CON_OBSERVACION: { label: 'Apto con observacion', color: 'orange', detail: 'Observacion' },
    };
    const clinicField = (data, selected = '') => field('Clinica EMO (obligatoria)', `<select name="clinica_id" required><option value="">Seleccione clinica activa</option>${data.clinics.filter(clinic => clinic.active || clinic.id === selected).map(clinic => `<option value="${escape(clinic.id)}" ${clinic.id === selected ? 'selected' : ''}>${escape(clinic.name)}${clinic.active ? '' : ' (inactiva: seleccione otra clinica)'}</option>`).join('')}</select>`);
    const dateField = (name, label, value) => field(label, `<input type="date" name="${name}" value="${escape(value)}" required>`);
    const token = data => `<input type="hidden" name="_token" value="${escape(data.csrf)}">`;
    function form(action, context, old = {}) {
        const data = runtime();
        if (!data || !data.routes[action]) return '';
        const date = old.fecha_programacion ?? context.date ?? '';
        const clinic = old.clinica_id ?? context.clinic ?? '';
        const url = new URL(data.routes[action].replace('__ID__', context.id), window.location.href);
        // The worker/control panels are replaced by AJAX; command routes keep the current filters.
        url.search = window.location.search;
        let html = `<form method="POST" enctype="multipart/form-data" action="${escape(url.href)}" class="mining-emo-form" data-emo-form="${action}">${token(data)}`;
        if (['complete', 'cancel', 'reprogram'].includes(action)) html += `<input type="hidden" name="revision" value="${escape(old.revision ?? context.revision)}">`;
        if (['schedule', 'reprogram'].includes(action)) html += dateField('fecha_programacion', 'Fecha de programacion', date);
        if (['schedule', 'reprogram', 'regularize'].includes(action)) html += clinicField(data, clinic);
        if (action === 'complete') {
            const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
            html += dateField('fecha_realizacion', 'Fecha de realizacion', old.fecha_realizacion ?? today);
            const outcomes = Object.fromEntries(Object.entries(outcomePresentation).map(([id, presentation]) => [id, presentation.label]));
            html += field('Resultado EMO', `<select name="resultado_emo" data-emo-outcome required>${Object.entries(outcomes).map(([id, label]) => `<option value="${id}" ${old.resultado_emo === id ? 'selected' : ''}>${label}</option>`).join('')}</select>`);
            html += field('Detalle del resultado (obligatorio con restriccion u observacion)', `<textarea name="detalle_resultado_emo" maxlength="5000">${escape(old.detalle_resultado_emo)}</textarea>`);
            html += field('Nota (opcional)', `<input type="number" name="nota" step="0.01" value="${escape(old.nota)}">`);
            html += field('Vencimiento manual (opcional)', `<input type="date" name="fecha_vencimiento" value="${escape(old.fecha_vencimiento)}">`);
            html += field('Archivo (seleccionelo de nuevo ante un error)', '<input type="file" name="archivo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">');
        }
        if (['cancel', 'reprogram'].includes(action)) html += field('Motivo (obligatorio)', `<textarea name="motivo" required maxlength="5000">${escape(old.motivo)}</textarea>`);
        if (['schedule', 'complete'].includes(action)) html += field('Observacion (opcional)', `<textarea name="observacion" maxlength="5000">${escape(old.observacion)}</textarea>`);
        html += `<button type="submit" class="btn btn-primary">${({ schedule: 'Guardar programacion', complete: 'Registrar resultado', cancel: 'Anular cita', reprogram: 'Guardar reprogramacion', regularize: 'Regularizar clinica' })[action]}</button></form>`;
        return html;
    }
    function updateDetail(formElement) {
        const outcome = formElement?.querySelector('[data-emo-outcome]');
        const detail = formElement?.querySelector('[name=detalle_resultado_emo]');
        if (outcome && detail) detail.required = ['APTO_CON_RESTRICCION', 'APTO_CON_OBSERVACION'].includes(outcome.value);
    }
    window.renderMiningEmoSchedule = exam => exam.categoria_operativa === 'EMO' ? form('schedule', { id: exam.id }) : null;
    window.renderMiningEmoOutcomeBadge = (outcome, detail = '') => {
        const presentation = outcomePresentation[outcome];
        if (!presentation) return '';
        const text = String(detail || '').trim();
        const tooltip = presentation.detail
            ? (text ? `${presentation.detail}: ${text}` : `Detalle de ${presentation.detail.toLowerCase()} no registrado`)
            : '';
        return `<span class="mine-state-chip ${presentation.color} mining-emo-outcome" data-emo-outcome="${escape(outcome)}"${tooltip ? ` title="${escape(tooltip)}" data-emo-tooltip="${escape(tooltip)}" tabindex="0"` : ''} aria-label="${escape(presentation.label + (tooltip ? `. ${tooltip}` : ''))}">${escape(presentation.label)}</span>`;
    };
    window.miningProgrammedAttempts = exam => (exam.intentos || []).filter(attempt => attempt.fecha_programacion
        && (attempt.resultado === 'PENDIENTE' || (exam.categoria_operativa === 'EMO' && attempt.emo_cita?.estado === 'ANULADA')));
    window.miningExamCanSchedule = (exam, hasPermission) => Boolean(hasPermission)
        && !['APROBADO', 'VIGENTE', 'CONVALIDADO'].includes(exam.estado)
        && (exam.estado === 'NO_APLICA' || !window.miningProgrammedAttempts(exam).some(attempt => attempt.resultado === 'PENDIENTE'));
    window.renderMiningEmoProgrammed = (exam, attempt) => {
        if (exam.categoria_operativa !== 'EMO') return null;
        const data = runtime();
        const appointment = attempt.emo_cita;
        if (!appointment) return `<p>Historico sin clinica. Regularice la clinica antes de registrar resultado.</p>${data?.permissions.programar ? `<button type="button" class="btn btn-outline" data-emo-action="regularize" data-emo-id="${escape(attempt.id)}">Regularizar clinica</button>` : ''}`;
        const context = `data-emo-id="${escape(appointment.id)}" data-emo-revision="${escape(appointment.revision)}" data-emo-date="${escape(appointment.fecha_programacion)}" data-emo-clinic="${escape(appointment.clinica_id)}" data-emo-clinic-name="${escape(appointment.clinica_nombre)}"`;
        let html = `<p>Clinica: ${escape(appointment.clinica_nombre)} · Fecha: ${escape(appointment.fecha_programacion)}</p>`;
        if (appointment.estado === 'PROGRAMADA') {
            if (data?.permissions.registrar) html += `<button type="button" class="btn btn-primary" data-emo-action="complete" ${context}>Registrar resultado EMO</button>`;
            if (data?.permissions.programar) html += `<button type="button" class="btn btn-outline" data-emo-action="cancel" ${context}>Anular cita</button><p class="mine-muted">Anule la cita para reprogramarla.</p>`;
        } else if (appointment.estado === 'ANULADA' && data?.permissions.programar) {
            html += `<button type="button" class="btn btn-outline" data-emo-action="reprogram" ${context}>Reprogramar</button>`;
        }
        return html;
    };
    window.openMiningEmoOperation = (action, context, old = {}, errors = []) => {
        const dialog = document.getElementById('modal-emo-operation');
        if (!dialog) return;
        document.getElementById('miningEmoTitle').textContent = ({ schedule: 'Programar EMO', complete: 'Registrar resultado EMO', reprogram: 'Reprogramar cita EMO', cancel: 'Anular cita EMO', regularize: 'Regularizar EMO historico' })[action] || 'Cita EMO';
        document.getElementById('miningEmoBody').innerHTML = `${context.worker ? `<p>${escape(context.worker)}</p>` : ''}${context.date ? `<p>Cita existente: ${escape(context.date)} · ${escape(context.clinicName || 'Historico sin clinica')}</p>` : ''}${errors.length ? `<div role="alert" class="alert alert-danger">${errors.map(escape).join('<br>')}</div>` : ''}${form(action, context, old)}`;
        updateDetail(dialog);
        dialog.showModal();
    };
    document.addEventListener('click', event => {
        const close = event.target.closest('[data-emo-close]');
        if (close) close.closest('dialog').close();
        const action = event.target.closest('[data-emo-action]');
        if (action) window.openMiningEmoOperation(action.dataset.emoAction, { id: action.dataset.emoId, revision: action.dataset.emoRevision, date: action.dataset.emoDate, clinic: action.dataset.emoClinic, clinicName: action.dataset.emoClinicName, worker: action.dataset.emoWorker });
        const management = event.target.closest('[data-emo-management]');
        if (management) document.getElementById(management.dataset.emoManagement)?.showModal();
    });
    document.addEventListener('change', event => {
        if (event.target.matches('[data-emo-outcome]')) updateDetail(event.target.form);
        if (event.target.matches('[data-emo-category-exam]')) {
            event.target.form.action = event.target.value;
            event.target.form.querySelector('[name=categoria_operativa]').value = event.target.selectedOptions[0]?.dataset.category || 'OTRO';
        }
    });
    function init() {
        const data = runtime();
        if (!data) return;
        const category = document.querySelector('[data-emo-category-exam]');
        if (category?.value) category.form.action = category.value;
        if (data.reopen === 'clinics' || data.reopen === 'category') document.getElementById(data.reopen === 'clinics' ? 'modal-emo-clinics' : 'modal-emo-category')?.showModal();
        else if (data.reopen) {
            const id = data.context.appointmentId || data.context.workerExamId || data.context.attemptId;
            if (id) window.openMiningEmoOperation(data.reopen, { id, revision: data.old.revision }, data.old, data.errors);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
