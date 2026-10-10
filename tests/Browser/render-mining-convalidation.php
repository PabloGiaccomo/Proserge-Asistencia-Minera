<?php

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$origin = new ExamenMinero(['id' => '00000000-0000-4000-8000-000000000001', 'nombre' => 'Curso de origen sin nota', 'activo' => true]);
$target = new ExamenMinero(['id' => '00000000-0000-4000-8000-000000000002', 'nombre' => 'Examen destino', 'permite_convalidacion' => true]);
$mine = new Mina(['id' => '00000000-0000-4000-8000-000000000003', 'nombre' => 'Mina destino de prueba']);
$requirement = new MinaRequisito([
    'id' => '00000000-0000-4000-8000-000000000004', 'mina_id' => $mine->id,
    'examen_id' => $target->id, 'permite_convalidacion_mina' => true,
    'convalidar_desde_otras_minas' => true, 'examenes_equivalentes_json' => [$origin->id],
]);
$requirement->setRelation('examen', $target);
echo view('personal.habilitacion-minera._convalidation-rules', [
    'requirementsByMine' => collect([$mine->id => collect([$requirement])]),
    'mines' => collect([$mine]), 'exams' => collect([$origin, $target]), 'currentQuery' => [],
])->render();
echo '<dialog id="workerDialog" class="mine-dialog is-wide"><div class="mine-dialog-header"><strong>Exámenes del trabajador en Mina destino</strong></div><div class="mine-dialog-body"><div data-worker-exam-card="worker-exam"><div id="convalidationPanel"></div></div></div></dialog>';
