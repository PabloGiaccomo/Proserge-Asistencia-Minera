<?php

use App\Models\EppAlmacen;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Usuario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'proserge_app_test') {
    throw new RuntimeException('Solo base MySQL aislada de pruebas.');
}
// Retire only committed fixtures from EppOperationServiceTest; never remove their ledger.
$warehouses = EppAlmacen::query()->where('nombre', 'like', 'PRUEBA RESERVAS %')->pluck('id');
$operations = EppOperacion::query()->whereIn('almacen_id', $warehouses)->get();
DB::transaction(function () use ($warehouses, $operations): void {
    Personal::query()->whereIn('id', $operations->pluck('personal_id'))->where('nombre_completo', 'PRUEBA OPERACION EPP')->update(['estado' => 'CESADO']);
    Usuario::query()->whereIn('id', $operations->pluck('registrado_por_usuario_id'))->where('email', 'like', '%@test.local')->update(['estado' => 'INACTIVO']);
    EppRegistro::query()->whereIn('id', EppOperacionDetalle::query()->whereIn('operacion_id', $operations->pluck('id'))->select('epp_id'))->where('nombre', 'GUANTE RESERVA')->update(['estado' => 'INACTIVO']);
    EppAlmacen::query()->whereIn('id', $warehouses)->update(['estado' => 'INACTIVO']);
});
echo "Fixtures de concurrencia retirados, sin borrar historial.\n";
