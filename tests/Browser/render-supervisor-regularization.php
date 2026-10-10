<?php

use App\Models\EvaluacionesImportLote;
use App\Models\EvaluacionSupervisor;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
view()->share('errors', new ViewErrorBag);
config()->set('evaluaciones.historical_import_enabled', false);
$user = new Usuario(['id' => '00000000-0000-0000-0000-000000000001', 'email' => 'revisor@example.test']);
$user->setRelation('rol', new Rol(['nombre' => 'REVISOR', 'permisos' => []]));
$user->setRelation('roles', collect());
$responses = array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4);
$responses['A2'] = 0;
$item = new EvaluacionSupervisor(['id' => '00000000-0000-0000-0000-000000000002', 'fecha' => '2025-12-15',
    'estado' => 'POR_REGULARIZAR', 'calculo_version' => 'HISTORICA', 'resultado_final' => 61, 'respuestas' => $responses,
    'import_lote_id' => '00000000-0000-0000-0000-000000000003', 'source_payload' => [31 => 61, '_regularizacion' => [
        ['accion' => 'PENDIENTE', 'motivo' => 'Falta confirmar la nota A2 con el evaluador original.', 'actor' => 'REVISOR DE PRUEBA',
            'usuario_id' => $user->id, 'fecha' => '2026-10-06T10:00:00-05:00'],
    ]]]);
$item->setRelation('evaluado', new Personal(['nombre_completo' => 'SUPERVISOR HISTORICO DE PRUEBA', 'dni' => '12345678', 'puesto' => 'SUPERVISOR']));
$item->setRelation('evaluador', null);
$item->setRelation('usuarioEvaluador', null);
$item->setRelation('mina', null);
$history = new LengthAwarePaginator([$item], 1, 20, 1);
echo view('evaluaciones.index', ['user' => $user, 'activeType' => 'supervisores', 'selectedDate' => '2026-10-06',
    'availableTypes' => collect(['supervisores', 'dashboard']), 'canEvaluate' => ['supervisores' => true],
    'evaluatorName' => 'REVISOR DE PRUEBA', 'mines' => collect(), 'supervisorHistory' => $history,
    'supervisorHistoryFilters' => [], 'canRegularizeSupervisor' => true,
    'regularizingSupervisor' => $item, 'supervisorTemplate' => SupervisorEvaluationTemplate::ITEMS,
    'supervisorSectionTitles' => SupervisorEvaluationTemplate::SECTION_TITLES,
    'historicalReports' => collect([new EvaluacionesImportLote(['id' => $item->import_lote_id])]),
])->render();
