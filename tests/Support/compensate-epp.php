<?php

use App\Models\EppOperacion;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppCorrectionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || config('database.connections.mysql.database') !== 'proserge_app_test') {
    throw new RuntimeException('Solo se permite la base aislada de pruebas.');
}
[$script, $sourceId, $detailId, $actorId, $uuid, $ready] = $argv;
$operation = EppOperacion::query()->findOrFail($sourceId);
$actor = Usuario::query()->with('rol')->findOrFail($actorId);
touch($ready);
$result = app(EppCorrectionService::class)->correct($operation, ['submission_uuid' => $uuid, 'lines' => [['detalle_origen_id' => $detailId, 'cantidad' => 1]]], $actor, 'Correccion concurrente');
echo json_encode(['id' => $result->id]);
