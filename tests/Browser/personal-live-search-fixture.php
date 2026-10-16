<?php

use App\Models\Personal;
use App\Modules\Personal\Resources\PersonalIndexResource;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

putenv('APP_ENV=testing');
putenv('DB_DATABASE=proserge_app_test');
putenv('DB_URL=');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'proserge_app_test') {
    throw new RuntimeException('Only the isolated test database is permitted.');
}
$request = Request::create('http://synthetic.local/personal');
$app->instance('request', $request);
$request->setLaravelSession($app['session.store']);
session(['user' => ['permissions' => PermissionCatalog::matrixFromSelections(['personal' => ['ver', 'ver_detalle', 'descargar_documentos', 'exportar']])]]);
view()->share('errors', new ViewErrorBag);

// Unsaved models: browser fixtures never create or update workers.
$workers = collect(range(0, 25))->map(function (int $index) use ($request): array {
    $worker = new Personal([
        'id' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
        'dni' => (string) (77012755 + $index),
        'numero_documento' => (string) (77012755 + $index),
        'nombre_completo' => 'TRABAJADOR SINTETICO '.($index + 1),
        'puesto' => 'OPERARIO', 'estado' => 'INACTIVO', 'contrato' => 'FIJO',
    ]);
    $worker->setRelation('minas', collect());

    return PersonalIndexResource::make($worker)->resolve($request);
})->all();

$render = function (array $rows, int $total, int $page = 1, bool $full = false): array {
    $pagination = [
        'total' => $total, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil($total / 25)),
        'perPage' => 25, 'from' => $total ? ($page - 1) * 25 + 1 : null,
        'to' => $total ? ($page - 1) * 25 + count($rows) : null, 'serverSide' => true,
    ];
    $view = view('personal.index', [
        'trabajadores' => $rows, 'paginationMeta' => $pagination,
        'puestoOptions' => ['OPERARIO'], 'contractTypeOptions' => ['FIJO'],
        'minas' => collect(), 'talleres' => collect(), 'oficinas' => collect(),
    ]);

    return ['html' => $full ? $view->render() : $view->fragment('personal-rows'), 'pagination' => $pagination];
};

echo json_encode([
    'initial' => $render(array_slice($workers, 0, 25), 26, 1, true),
    'initialEmpty' => $render([], 0, 1, true),
    'all' => $render(array_slice($workers, 0, 25), 26),
    'hit' => $render([$workers[0]], 1),
    'pair' => $render(array_slice($workers, 0, 2), 2),
    'empty' => $render([], 0),
    'page2' => $render([$workers[25]], 26, 2),
    'export' => view('personal.export', [
        'availableColumns' => ['dni' => 'DNI', 'nombre_completo' => 'Apellidos y Nombres'],
        'recommendedColumns' => ['dni', 'nombre_completo'],
        'config' => \App\Modules\Personal\Support\PersonalExportConfig::fromInput([], ['dni', 'nombre_completo'], true),
        'selectedWorkers' => [],
        'previewTable' => ['headers' => [], 'rows' => []],
    ])->render(),
], JSON_THROW_ON_ERROR);
