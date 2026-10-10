<?php

use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Modules\Personal\Support\ScheduledControlRow;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\LengthAwarePaginator;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Pure in-memory fixture: no database connections or real personal data.
$day = CarbonImmutable::parse('2026-10-08');
$filters = ScheduledControlFilters::fromValidated([], $day);
session()->put('user.permissions', PermissionCatalog::matrixFromSelections(['habilitacion_minera' => ['ver_programados', 'exportar', 'programar', 'registrar', 'configurar']]));
$rows = [
    new ScheduledControlRow('00000000-0000-4000-8000-000000000001', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 7, 'worker-fixture', '00000001', 'Trabajador ficticio con nombre largo', 'Tecnico de prueba', 'exam-fixture', 'EMO de prueba', ['mine-a', 'mine-b'], ['Mina ficticia A', 'Mina ficticia B'], ['Mina A: intento 1', 'Mina B: intento 2'], 'PROGRAMADO', null, null, null, 'PROGRAMADA'),
    new ScheduledControlRow('legacy-emo-attempt:00000000-0000-4000-8000-000000000003', 'EMO', $day, null, 'Historico sin clinica', null, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO historico', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'PROGRAMADO', null, null, null, 'PROGRAMADO'),
    new ScheduledControlRow('completed-emo-fixture', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 2, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO resultado restringido', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_RESTRICCION, 'Evitar alturas', $day, 'COMPLETADA'),
    new ScheduledControlRow('apto-emo-fixture', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 3, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO apto', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO, null, $day, 'COMPLETADA'),
    new ScheduledControlRow('no-apto-emo-fixture', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 4, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO no apto', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::NO_APTO, null, $day, 'COMPLETADA'),
    new ScheduledControlRow('observed-emo-fixture', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 5, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO resultado observado', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_OBSERVACION, 'Control anual', $day, 'COMPLETADA'),
    new ScheduledControlRow('cancelled-emo-fixture', 'EMO', $day, '00000000-0000-4000-8000-000000000002', 'Clinica ficticia', 8, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO anulado', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ANULADO', null, null, null, 'ANULADA'),
    new ScheduledControlRow('course-fixture', 'CURSO', $day, null, null, null, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'course-exam', 'Curso de prueba', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'NO_ASISTIO', null, null, null, 'NO_ASISTIO'),
];
if (($argv[1] ?? '') === 'empty') {
    $rows = [];
}
$data = [
    'activeMineView' => 'scheduled', 'scheduledControlFilters' => $filters,
    'scheduledControl' => new LengthAwarePaginator($rows, count($rows), 25, 1),
    'scheduledControlOptions' => ['exams' => [['id' => 'exam-fixture', 'name' => 'EMO de prueba']], 'mines' => [['id' => 'mine-a', 'name' => 'Mina ficticia A']], 'clinics' => [['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Clinica ficticia']]],
    'emoClinics' => collect([(object) ['id' => '00000000-0000-4000-8000-000000000002', 'nombre' => 'Clinica ficticia', 'activo' => true]]),
    'allExams' => collect(), 'currentQuery' => [],
];
echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="mine-page" style="padding:16px">';
echo view('personal.habilitacion-minera._scheduled-control', $data)->render();
echo view('personal.habilitacion-minera._emo-dialogs', $data)->render();
echo '</main></body></html>';
