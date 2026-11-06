<script>
(() => {
    const miningAttemptRecovery = @json($miningAttemptRecovery ?? null);
    function initialize(root) {
        for (const card of root.querySelectorAll('[data-worker-exam-card]')) {
            const exam = assignmentsData.flatMap(item => item.examenes || []).find(item => item.id === card.dataset.workerExamCard);
            if (!exam) continue;
            for (const form of card.querySelectorAll('form')) {
                const result = form.elements.namedItem('resultado');
                const score = form.elements.namedItem('nota');
                if (!score || !result) continue;
                const help = document.createElement('small');
                help.textContent = exam.requiere_nota ? 'Nota requerida para aprobar. Mínimo ' + (exam.nota_minima ?? 'según examen') + '.' : 'Nota opcional.';
                score.after(help);
                const update = () => {
                    score.required = Boolean(exam.requiere_nota && result.value === 'APROBADO');
                    if (score.required && exam.nota_minima !== null) score.min = String(exam.nota_minima);
                    else score.removeAttribute('min');
                };
                result.addEventListener('change', update);
                update();
            }
        }
    }
    window.initializeMiningAttemptForms = initialize;

    function recover() {
        const recovery = miningAttemptRecovery;
        if (!recovery) return;
        const assignment = assignmentsData.find(item => item.id === recovery.assignment_id);
        if (!assignment || !assignment.examenes.some(exam => exam.id === recovery.exam_id)) return;
        if (!canRegisterMining && !canProgramMining) return;
        openWorkerExams(assignment.id, assignment.personal_nombre, assignment.mina_nombre, recovery.exam_id);
        const root = document.getElementById('workerExamModalBody');
        const card = [...root.querySelectorAll('[data-worker-exam-card]')].find(node => node.dataset.workerExamCard === recovery.exam_id);
        const toggle = card?.querySelector('[data-exam-action-toggle="' + recovery.action + '"]');
        if (!toggle) return;
        toggle.click();
        const panel = card.querySelector('[data-exam-action-panel="' + recovery.action + '"]');
        const form = [...panel.querySelectorAll('form')].find(form => recovery.attempt_id
            ? new URL(form.action).pathname.endsWith('/' + recovery.attempt_id + '/completar')
            : form.elements.namedItem('resultado'));
        if (!form) return;
        for (const [key, value] of Object.entries(recovery.fields)) {
            const input = form.elements.namedItem(key);
            if (input && input.type !== 'file' && input.type !== 'hidden') input.value = value;
        }
        form.elements.namedItem('resultado')?.dispatchEvent(new Event('change'));
        const notice = document.createElement('p');
        notice.setAttribute('role', 'alert');
        notice.style.gridColumn = '1/-1';
        notice.textContent = recovery.had_file ? 'Corrige los campos indicados. Vuelve a seleccionar el archivo.' : 'Corrige los campos indicados y vuelve a guardar.';
        form.prepend(notice);
        for (const [key, messages] of Object.entries(recovery.errors)) {
            const input = form.elements.namedItem(key);
            if (!input) continue;
            const error = document.createElement('small');
            error.id = 'attempt-error-' + key;
            error.textContent = messages.join(' ');
            error.style.color = '#a51d2d';
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', error.id);
            input.after(error);
        }
        form.querySelector('[aria-invalid]')?.focus();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', recover, {once: true});
    else recover();
})();
</script>
