<?php

use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\PersonalContratoSignedBatchService;
use App\Modules\Personal\Services\PersonalIngresoBatchReviewService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 13376
    || config('database.connections.mysql.database') !== 'proserge_app_test'
    || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
    throw new RuntimeException('El worker requiere MySQL aislado 127.0.0.1:13376/proserge_app_test.');
}

[, $action, $actorId, $lotId, $revision, $rowId, $diskRoot, $barrier] = $argv;
$resolvedRoot = realpath($diskRoot);
$temporaryRoot = realpath(sys_get_temp_dir());
if (! $resolvedRoot || ! $temporaryRoot || dirname($resolvedRoot) !== $temporaryRoot
    || ! preg_match('/^proserge-rrhh-batch-[a-f0-9-]{36}$/D', basename($resolvedRoot))) {
    throw new RuntimeException('El worker requiere un directorio temporal propio de la prueba.');
}
config(['filesystems.disks.local.root' => $resolvedRoot]);
Storage::purge('local');

// Notifications are a secondary effect. This test exercises real domain writes and filesystem copies.
$notifications = Mockery::mock(OperationalNotificationService::class);
$notifications->shouldReceive('contratoFirmado')->zeroOrMoreTimes();
$app->instance(OperationalNotificationService::class, $notifications);

$actor = Usuario::query()->findOrFail($actorId);
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
echo json_encode(['connection' => (int) DB::scalar('SELECT CONNECTION_ID()')], JSON_THROW_ON_ERROR).PHP_EOL;

$announced = false;
DB::connection()->beforeExecuting(function (string $query) use (&$announced, $barrier): void {
    $target = match ($barrier) {
        'personal_insert' => str_starts_with(strtolower($query), 'insert into `personal` '),
        'confirmed_hash' => str_starts_with(strtolower($query), 'update `personal_contrato_firmado_lote_archivos` ')
            && str_contains($query, 'confirmed_sha256'),
        'ingreso_lot' => str_contains($query, 'from `personal_ingreso_lotes`') && str_contains(strtolower($query), 'for update'),
        'signed_lot' => str_contains($query, 'from `personal_contrato_firmado_lotes`') && str_contains(strtolower($query), 'for update'),
        default => false,
    };
    if (! $announced && $target) {
        $announced = true;
        echo json_encode(['barrier' => $barrier], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});

try {
    $result = match ($action) {
        'ingreso' => $app->make(PersonalIngresoBatchReviewService::class)->confirm($actor, $lotId, $revision, [$rowId]),
        'signed' => $app->make(PersonalContratoSignedBatchService::class)->confirm($actor, $lotId, $revision, [$rowId]),
    };
} catch (HttpExceptionInterface $exception) {
    $result = [['estado' => 'HTTP_ERROR', 'status' => $exception->getStatusCode()]];
}
echo json_encode(['results' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
