<?php

use App\Models\TransporteServicio;
use App\Models\Usuario;
use App\Modules\Transporte\Services\TransportePlanningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 13376
    || config('database.connections.mysql.database') !== 'proserge_app_test'
    || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
    throw new RuntimeException('El worker requiere el MySQL aislado de Transporte.');
}

[, $actorId, $serviceId, $action, $subjectId] = $argv;
$actor = Usuario::query()->findOrFail($actorId);
$service = TransporteServicio::query()->findOrFail($serviceId);
$planning = app(TransportePlanningService::class);
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');

echo json_encode(['connection' => (int) DB::scalar('SELECT CONNECTION_ID()')], JSON_THROW_ON_ERROR).PHP_EOL;

$lockAnnounced = false;
DB::connection()->beforeExecuting(function (string $query) use (&$lockAnnounced): void {
    if (!$lockAnnounced && str_contains($query, 'transporte_servicios') && str_contains(strtolower($query), 'for update')) {
        $lockAnnounced = true;
        echo json_encode(['service_lock' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});

$result = match ($action) {
    'assign' => $planning->addPasajeros($actor, $service, ['grupo_trabajo_detalle_id' => $subjectId]),
    'relocate' => $planning->reubicarPasajero($actor, $service, $subjectId, $argv[5], 'Traslado concurrente'),
};

echo json_encode(['ok' => $result['ok'], 'code' => $result['code'] ?? null], JSON_THROW_ON_ERROR).PHP_EOL;
