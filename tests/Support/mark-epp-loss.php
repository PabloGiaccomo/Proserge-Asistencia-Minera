<?php

use App\Models\EppEntrega;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppBalanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'proserge_app_test') {
    throw new RuntimeException('Solo base MySQL aislada de pruebas.');
}
[$script, $deliveryId, $actorId, $submissionUuid, $readyPath] = $argv;
$app['events']->listen(TransactionBeginning::class, function () use ($readyPath): void {
    if (! is_dir(dirname($readyPath))) {
        mkdir(dirname($readyPath), 0777, true);
    }
    touch($readyPath);
});
$result = app(EppBalanceService::class)->markLoss(EppEntrega::query()->findOrFail($deliveryId), 2, 'Perdida concurrente', Usuario::query()->findOrFail($actorId), $submissionUuid);
echo json_encode(['operacion_id' => $result->id, 'submission_uuid' => $result->submission_uuid], JSON_THROW_ON_ERROR);
