<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);
$source = file_get_contents(__DIR__.'/../../resources/views/personal/habilitacion-minera/index.blade.php');
$begin = strpos($source, 'function parseDisplayDate(');
$end = strpos($source, 'function initMineExamDashboard(');
$functions = substr($source, $begin, $end - $begin);
$handlerStart = strpos($source, "document.addEventListener('click', function(event) {\n    if (!event.target.closest('.mine-actions-menu'))");
$handlerEnd = strpos($source, 'function bindMineDialogBackdrop(');
$handler = substr($source, $handlerStart, $handlerEnd - $handlerStart);
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body>';
echo '<style>.mine-exam-panel {display:none}.mine-exam-panel.is-open {display:block}label {display:block;margin:.75rem 0}button {white-space:normal}input {max-width:100%}</style>';
echo '<dialog id="modal-worker-exams" style="width:min(700px,90vw);box-sizing:border-box"><strong id="workerExamModalTitle"></strong><div id="workerExamModalBody"></div></dialog>';
echo '<script>
const assignmentsData = [{id:"assignment",personal_nombre:"Persona de prueba",mina_nombre:"Mina de prueba",
    examenes:[{id:"exam",nombre:"Curso de prueba",requiere_nota:true,nota_minima:14,estado:"PENDIENTE",categoria_operativa:"CURSO",
        intentos:[{id:"attempt",resultado:"PENDIENTE",fecha_programacion:"01/01/2026"}]}]}];
const canRegisterMining = true;
const canProgramMining = true;
const canConvalidateMining = false;
const attemptsUrlTemplate = "http://localhost/examenes/__EXAM__/intentos";
const completeAttemptUrlTemplate = "http://localhost/intentos/__ATTEMPT__/completar";
const noAplicaUrlTemplate = "http://localhost/examenes/__EXAM__/no-aplica";
const attemptResultLabels = {APROBADO:"Aprobado",DESAPROBADO:"Desaprobado",NO_APLICA:"No aplica"};
const examStateLabels = {PENDIENTE:"Pendiente"};
const csrfToken = "sintetico";
const escHtml = value => String(value ?? "").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;");
const escAttr = value => escHtml(value).replaceAll("\"","&quot;");
const closeActionsMenu = () => {};
window.miningProgrammedAttempts = exam => exam.intentos.filter(attempt => attempt.resultado === "PENDIENTE");
window.miningExamCanSchedule = () => true;
'.$handler.$functions.'
</script>';
echo view('personal.habilitacion-minera._attempt-recovery-script', [
    'miningAttemptRecovery' => [
        'assignment_id' => 'assignment', 'exam_id' => 'exam',
        'attempt_id' => ($argv[1] ?? '') === 'scheduled' ? 'attempt' : null,
        'action' => $argv[1] ?? 'performed', 'had_file' => true,
        'fields' => ['fecha_realizacion' => '2026-11-03', 'resultado' => 'APROBADO', 'nota' => '', 'observacion' => 'Texto de prueba <seguro>'],
        'errors' => ['nota' => ['Ingresa la nota de este examen.']],
    ],
])->render();
echo '</body></html>';
