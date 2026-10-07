<?php

use App\Models\Usuario;
use App\Modules\Epps\Services\EppInventoryConfirmationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'proserge_app_test') {
    throw new RuntimeException('La prueba concurrente solo puede usar proserge_app_test.');
}
$actor = Usuario::query()->with(['rol', 'rolesAdicionales'])->findOrFail($argv[2]);
file_put_contents($argv[4], 'ready');
echo json_encode(app(EppInventoryConfirmationService::class)->confirm($argv[1], $actor, $argv[3]), JSON_THROW_ON_ERROR);
