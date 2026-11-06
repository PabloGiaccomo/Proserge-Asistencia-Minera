<?php

use App\Models\Rol;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

// Synthetic view fixtures. Database access is disabled.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'usuario_browser_database_disabled']);
$request = Request::create('http://127.0.0.1:8477/usuarios/crear');
$request->setLaravelSession($app['session.store']);
$app->instance('request', $request);
$app['url']->setRequest($request);
session()->put('user', ['id' => 'synthetic-operator', 'name' => 'Operador de prueba', 'permissions' => PermissionCatalog::fullAccessMatrix()]);
view()->share('errors', new ViewErrorBag);
view()->share('notificationsEnabled', false);
$role = new Rol(['id' => '70000000-0000-4000-8000-000000000001', 'nombre' => 'SUPERVISOR DE PRUEBA', 'estado' => 'ACTIVO']);
$roles = collect([$role]);
$scenario = $argv[1] ?? 'individual';

if ($scenario === 'resultado') {
    echo view('seguridad.usuarios.lotes.show', ['resultado' => [
        'id' => '70000000-0000-4000-8000-000000000002',
        'vence' => '06/11/2026 16:15',
        'filas' => [
            ['trabajador' => 'TRABAJADOR DE PRUEBA UNO', 'usuario' => 'uno@example.test', 'contrasena' => 'Clave-sintetica-uno'],
            ['trabajador' => 'TRABAJADOR DE PRUEBA DOS', 'usuario' => 'dos@example.test', 'contrasena' => 'Clave-sintetica-dos'],
        ],
    ]])->render();
} elseif ($scenario === 'lote') {
    echo view('seguridad.usuarios.lotes.create', [
        'roles' => $roles,
        'loteId' => '70000000-0000-4000-8000-000000000002',
        'seleccion' => [],
        'lotesRecientes' => [],
        'maxFilas' => 25,
        'hasEstadoColumn' => true,
    ])->render();
} else {
    echo view('seguridad.usuarios.create', [
        'roles' => $roles,
        'roleBuckets' => ['areas' => collect(), 'cargos' => collect()],
        'trabajadores' => [],
        'trabajadorSeleccionado' => null,
        'emailSugerido' => null,
        'search' => '',
        'hasEstadoColumn' => true,
    ])->render();
}
