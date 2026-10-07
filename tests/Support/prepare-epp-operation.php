<?php

use App\Models\EppOperacion;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppOperationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'proserge_app_test') {
    throw new RuntimeException('Solo base MySQL aislada de pruebas.');
}
[$script, $operationId, $actorId, $readyPath] = $argv;
if (! is_dir(dirname($readyPath))) {
    mkdir(dirname($readyPath), 0777, true);
}
touch($readyPath);
if (($argv[4] ?? '') === 'expire') {
    app(EppOperationService::class)->expireReservations(now());
    echo json_encode(['estado' => EppOperacion::query()->findOrFail($operationId)->estado], JSON_THROW_ON_ERROR);
    exit;
}
$result = app(EppOperationService::class)->prepareForSignature(EppOperacion::query()->findOrFail($operationId), Usuario::query()->findOrFail($actorId));
echo json_encode(['estado' => $result->estado], JSON_THROW_ON_ERROR);
