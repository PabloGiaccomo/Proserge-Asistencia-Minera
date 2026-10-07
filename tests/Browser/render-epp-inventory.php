<?php

use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppRegistro;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;

putenv('APP_ENV=testing');
putenv('DB_DATABASE=proserge_app_test');
putenv('DB_URL=');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'proserge_app_test') {
    throw new RuntimeException('El render solo puede consultar proserge_app_test.');
}
view()->share('errors', new ViewErrorBag);
$epp = new EppRegistro(['id' => '00000000-0000-0000-0000-000000000001', 'nombre' => 'GUANTES DE SEGURIDAD', 'requiere_talla' => true, 'tallas' => ['M', 'L'], 'requiere_color' => true, 'colores' => ['AZUL', 'ROJO'], 'politica_devolucion' => 'RETORNABLE']);
$warehouse = new EppAlmacen(['id' => '00000000-0000-0000-0000-000000000002', 'nombre' => 'ALMACEN DE PRUEBA', 'estado' => 'ACTIVO']);
$warehouse->setRelation('mina', null);
$existence = new EppExistencia(['id' => '00000000-0000-0000-0000-000000000003', 'atributos_json' => ['TALLA' => 'M', 'COLOR' => 'AZUL'], 'cantidad_disponible' => 8, 'cantidad_reservada' => 0, 'cantidad_cuarentena' => 0, 'conciliado_at' => now()]);
$existence->setRelation('epp', $epp)->setRelation('almacen', $warehouse);
$preview = ($argv[1] ?? '') === 'preview' ? ['token' => '00000000-0000-0000-0000-000000000004', 'action' => 'apertura', 'warehouse_name' => $warehouse->nombre, 'payload' => ['motivo' => 'Conteo fisico de prueba'], 'rows' => [['nombre' => $epp->nombre, 'atributos' => ['TALLA' => 'L', 'COLOR' => 'ROJO'], 'antes' => 'Sin conciliar', 'despues' => 5]]] : null;
echo view('epps.inventory.index', [
    'warehouses' => collect([$warehouse]), 'existences' => new LengthAwarePaginator([$existence], 1, 20),
    'catalog' => collect([$epp]), 'openingProduct' => $epp, 'openingRows' => 2,
    'mines' => collect(), 'allowGlobal' => true, 'canManage' => true, 'canAdjust' => true,
    'preview' => $preview, 'movements' => collect(),
])->render();
