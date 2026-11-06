<?php

use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use App\Shared\Support\SearchTerms;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

putenv('APP_ENV=testing');
putenv('DB_DATABASE=proserge_app_test');
putenv('DB_URL=');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'proserge_app_test') {
    throw new RuntimeException('Synthetic render requires the isolated test database.');
}
parse_str($argv[1] ?? '', $query);
$query['tab'] = 'identificacion';
$request = Request::create('http://127.0.0.1:8000/logistica', 'GET', $query);
$request->setLaravelSession($app['session.store']);
$app->instance('request', $request);
$app['url']->setRequest($request);
session()->put('user.permissions', PermissionCatalog::fullAccessMatrix());
view()->share('errors', new ViewErrorBag);
$data = app(LogisticaDashboardService::class)->pageData($query);
$data['eppModule'] = app(EppService::class)->pageData(['q' => 'BROWSER-ISOLATED-NONEXISTENT']);
$fixtures = [
    ['nombre' => 'GUANTES ANTICÓRTE', 'codigo' => 'EPP-GUA-01', 'categoria' => 'EPP'],
    ['nombre' => 'BOTAS DE SEGURIDAD', 'codigo' => 'EPP-BOT-02', 'categoria' => 'EPP'],
    ['nombre' => 'CASCO DE SEGURIDAD', 'codigo' => 'EPP-CAS-03', 'categoria' => 'EPP'],
    ['nombre' => 'DADO DE IMPÁCTO', 'codigo' => 'DADO_DE_IMPACTO', 'categoria' => 'HERRAMIENTA', 'readonly' => true, 'fuente' => 'CATALOGO_PARADA', 'catalogo_id' => '00000000-0000-0000-0000-000000000090'],
    ['nombre' => 'LLAVE MIXTA', 'codigo' => 'LLAVE-MIXTA-02', 'categoria' => 'HERRAMIENTA'],
    ['nombre' => 'PERNO ZINCADO', 'codigo' => 'PERNO-ZINC-01', 'categoria' => 'CONSUMIBLE'],
];
$data['identityCatalogRows'] = collect($fixtures)->map(fn ($row, $index) => [
    'id' => sprintf('00000000-0000-0000-0000-%012d', $index + 1),
    'vida_util' => 30, 'estado' => 'ACTIVO', 'tallas' => [], 'colores' => [], 'otros_atributos' => [],
    ...$row,
])->where('categoria', $query['ident_categoria'] ?? 'EPP')->values();
$data['identityRows'] = $data['identityCatalogRows']
    ->filter(fn ($row) => SearchTerms::matches($row['nombre'].' '.$row['codigo'], $query['ident_q'] ?? ''))->values();
echo view('logistica.index', $data)->render();
