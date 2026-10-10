<?php

use App\Models\Rol;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ViewErrorBag;

putenv('APP_ENV=testing');
putenv('DB_DATABASE=proserge_app_test');
putenv('DB_URL=');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'proserge_app_test') {
    throw new RuntimeException('Synthetic render requires test database.');
}
$app['request']->setLaravelSession($app['session.store']);
session()->put('user.permissions', PermissionCatalog::fullAccessMatrix());
view()->share('errors', new ViewErrorBag);
if (in_array($argv[1] ?? '', ['notification-read', 'notification-write'], true)) {
    session()->put('user.permissions', PermissionCatalog::matrixFromSelections([
        'notificaciones' => ($argv[1] === 'notification-write' ? ['ver', 'actualizar'] : ['ver']),
    ]));
    echo view('partials.header', ['notificationsEnabled' => true, 'headerUnreadCount' => 0, 'headerNotifications' => collect()])->render();
    exit;
}
echo view('seguridad.roles.edit', [
    'rol' => new Rol(['id' => '00000000-0000-0000-0000-000000000099', 'nombre' => 'ROL DE PRUEBA', 'estado' => 'ACTIVO',
        'permisos' => PermissionCatalog::matrixFromSelections(['evaluaciones' => ['ver', 'dashboards', 'evaluar_desempeno']])]),
    'mode' => 'edit', 'modules' => PermissionCatalog::availableModules(['notificaciones']),
    'actions' => PermissionCatalog::availableActions(['notificaciones']),
    'moduleActions' => PermissionCatalog::availableModuleActions(['notificaciones']),
    'notificationModules' => [], 'notificationActions' => [], 'notificationModuleActions' => [],
    'notificationTypes' => collect(), 'notificationRolePreferences' => collect(),
])->render();
