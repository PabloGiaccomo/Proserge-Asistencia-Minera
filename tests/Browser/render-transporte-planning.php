<?php

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rq = 'd74f2045-5306-41be-9f5e-7d021a2087e6';
$planId = '7d77bd1f-663c-41e7-b48e-a8ab94d0070d';
$groupId = '12d64158-9277-42a5-845c-4b08b5db3963';
$driverId = '2dc932e1-3131-438c-9c97-9b0bbbe3abe7';
$serviceId = '4d29f854-95aa-4296-8d60-c9aad665bc02';
$date = '2026-11-04';
$turn = 'A';
$selected = ['rq_mina_id' => $rq, 'rq_mina_plan_id' => $planId, 'fecha' => $date, 'turno' => $turn];
$plan = ['id' => $planId, 'codigo' => 'PLAN-01', 'nombre' => 'Parada de mantenimiento de equipos auxiliares', 'archivado' => false];
$group = ['id' => $groupId, 'servicio' => 'Equipo de montaje y mantenimiento mecánico de chancado', 'paradero' => 'Paradero principal'];
$driver = ['id' => $driverId, 'nombre_completo' => 'Conductor de Prueba con Apellidos Compuestos'];
parse_str($argv[1] ?? '', $query);
$request = Request::create('http://transporte.test/transporte/planificacion', 'GET', $query + $selected);
$request->setLaravelSession($app['session.store']);
$app->instance('request', $request);
$app['url']->setRequest($request);
session()->put('user.permissions', PermissionCatalog::fullAccessMatrix());
view()->share('errors', new ViewErrorBag);

$service = [
    'id' => $serviceId, 'tipo' => 'PERSONAL', 'tramo' => 'IDA', 'estado' => 'BORRADOR',
    'fecha' => $date, 'turno' => $turn, 'placa' => 'SYN-01', 'tipo_vehiculo' => 'Van',
    'conductor' => $driver['nombre_completo'], 'conductor_personal_id' => $driverId, 'capacidad' => 12,
    'alcances' => [[
        'grupo_trabajo_id' => $groupId, 'grupo_man_power' => $group['servicio'],
        'grupo_operativo' => null, 'sait_snapshot' => 'SAIT DE MANTENIMIENTO',
    ]],
    'pasajeros' => [],
    'metricas' => ['ocupacion' => 0, 'capacidad' => 12],
    'capabilities' => ['editar' => true, 'confirmar' => true, 'agregar_pasajeros' => false],
    'faltantes_confirmacion' => [],
];

if (($query['stage'] ?? null) === 'confirmed') {
    $service['estado'] = 'CONFIRMADO';
    $service['capabilities']['confirmar'] = false;
}

echo view('transporte.planificacion', [
    'selected' => $selected,
    'paradas' => [['id' => $rq, 'mina_nombre' => 'Mina de prueba', 'destino_nombre' => 'Operación de chancado']],
    'plans' => [$plan],
    'rq_mina' => ['id' => $rq],
    'plan' => $plan,
    'lista' => ['q' => 'chancado'],
    'conductores' => [$driver],
    'can_create' => true,
    'grupos_man_power' => [$group],
    'servicios' => [$service],
    'editService' => ($query['editar'] ?? null) === $serviceId ? $service : null,
])->render();
