<?php

use App\Models\EppOperacion;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppOperationConfirmationService;
use App\Modules\Epps\Support\EppSignaturePayload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'proserge_app_test') {
    throw new RuntimeException('Solo base MySQL aislada de pruebas.');
}
[$script, $operationId, $actorId, $readyPath, $signatureRoot] = $argv;
config(['filesystems.disks.local.root' => $signatureRoot]);
Storage::forgetDisk('local');
$app['events']->listen(TransactionBeginning::class, function () use ($readyPath): void {
    if (! is_dir(dirname($readyPath))) {
        mkdir(dirname($readyPath), 0777, true);
    }
    touch($readyPath);
});
$payload = EppSignaturePayload::fromArray(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR));
$result = app(EppOperationConfirmationService::class)->confirm(EppOperacion::query()->findOrFail($operationId), $payload, Usuario::query()->findOrFail($actorId));
echo json_encode(['estado' => $result->estado, 'aceptacion_id' => $result->aceptacion->id], JSON_THROW_ON_ERROR);
