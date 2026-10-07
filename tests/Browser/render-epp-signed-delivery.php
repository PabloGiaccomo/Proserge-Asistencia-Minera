<?php

use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Contracts\Console\Kernel;
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
$id = fn (int $number) => sprintf('00000000-0000-0000-0000-%012d', $number);
$warehouse = new EppAlmacen(['id' => $id(2), 'nombre' => 'ALMACEN DE PRUEBA', 'estado' => 'ACTIVO']);
$warehouse->setRelation('mina', null);
$worker = new Personal(['id' => $id(3), 'nombre_completo' => 'TRABAJADOR DE PRUEBA MOVIL', 'dni' => '00000001']);
$product = new EppRegistro(['id' => $id(4), 'nombre' => 'GUANTES DE SEGURIDAD', 'requiere_talla' => true, 'tallas' => ['M', 'L'], 'control_inventario' => true, 'politica_devolucion' => 'RETORNABLE']);
$substitute = new EppRegistro(['id' => $id(5), 'nombre' => 'GUANTES DE REEMPLAZO', 'requiere_talla' => true, 'tallas' => ['M', 'L'], 'control_inventario' => true, 'politica_devolucion' => 'RETORNABLE']);
$operation = new EppOperacion(['id' => $id(1), 'personal_id' => $worker->id, 'almacen_id' => $warehouse->id, 'tipo' => 'ENTREGA', 'estado' => 'LISTO_PARA_FIRMA', 'submission_uuid' => $id(6), 'version' => 2, 'resumen_sha256' => str_repeat('a', 64), 'reserva_expires_at' => now()->addMinutes(10), 'registrado_por_usuario_id' => $id(7)]);
$operation->setRelation('personal', $worker)->setRelation('almacen', $warehouse);
$line = ['epp_id' => $substitute->id, 'requested_epp_id' => $product->id, 'processed_quantity' => 5, 'pending_quantity' => 5, 'requested_quantity' => 10, 'return_policy' => 'RETORNABLE', 'attributes' => ['TALLA' => 'M'], 'reason' => 'Articulo equivalente revisado', 'snapshot' => ['real' => ['nombre' => $substitute->nombre], 'solicitado' => ['nombre' => $product->nombre], 'disponibilidad' => 'CONCILIADO']];
$payload = ['worker' => ['id' => $worker->id, 'name' => $worker->nombre_completo, 'document' => $worker->dni], 'warehouse' => ['name' => $warehouse->nombre], 'details' => [$line], 'pending' => [$line], 'rejected' => []];
$mode = $argv[1] ?? 'review';
if (str_starts_with($mode, 'personal-')) {
    $data = ['data' => PersonalFichaCatalog::emptyData(), 'sections' => [], 'documentRequirements' => [], 'declarationCheckboxes' => [], 'puestoOptions' => [], 'archivos' => collect(), 'firmaBase64' => null, 'huellaDataUrl' => null];
    echo ($mode === 'personal-public'
        ? view('ficha-public.show', $data + ['token' => 'test-token', 'mode' => 'edit', 'ficha' => null, 'link' => null, 'verifyFields' => [], 'familiares' => collect()])
        : view('ficha-public.ingreso', $data + ['authorized' => true, 'familiares' => []]))->render();
} elseif ($mode === 'form') {
    $existence = new EppExistencia(['epp_id' => $product->id, 'atributos_json' => ['TALLA' => 'M'], 'cantidad_disponible' => 5, 'conciliado_at' => now()]);
    echo view('epps.operations.form', ['operation' => null, 'warehouses' => collect([$warehouse]), 'warehouse' => $warehouse, 'worker' => $worker, 'catalog' => collect([$product, $substitute]), 'existences' => collect([$existence]), 'lines' => [['epp_id' => $product->id, 'cantidad_solicitada' => 10, 'atributos' => ['TALLA' => 'M'], 'aceptar_parcial' => true]], 'submissionUuid' => $id(6)])->render();
} elseif ($mode === 'show') {
    $operation->estado = 'RECHAZADO';
    echo view('epps.operations.show', compact('operation', 'payload'))->render();
} else {
    $approver = new Usuario(['id' => $id(8), 'email' => 'aprobador@test.local']);
    $approver->setRelation('personal', new Personal(['nombre_completo' => 'APROBADOR DE PRUEBA']));
    $witnessUser = new Usuario(['id' => $id(9)]);
    $witnessUser->setRelation('personal', new Personal(['nombre_completo' => 'TESTIGO DE PRUEBA', 'dni' => '00000002']));
    echo view('epps.operations.review', ['operation' => $operation, 'payload' => $payload, 'witness' => $mode === 'witness', 'approver' => $approver, 'operator' => null, 'canWitness' => $mode === 'witness', 'witnesses' => collect([$witnessUser])])->render();
}
