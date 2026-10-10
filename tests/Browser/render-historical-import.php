<?php

use App\Models\EvaluacionesImportLote;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
view()->share('errors', new ViewErrorBag);
$blocked = ($argv[1] ?? '') === 'blocked';
$batch = new EvaluacionesImportLote([
    'id' => '00000000-0000-0000-0000-000000000001', 'hash' => str_repeat('a', 64), 'estado' => 'PREVIEW',
]);
$entry = [
    'source_sheet' => 'EV SUPERVISORES', 'source_row' => 2, 'dni' => '12345678',
    'dni_ref' => '00000000-0000-0000-0000-000000000002',
    'name' => 'PERSONA DE PRUEBA', 'date' => '2025-12-17',
    'payload' => ['resultado_final' => 61, 'calculo_version' => 'HISTORICA'],
    'review_status' => $blocked ? 'blocked' : 'proposed',
];
if ($blocked) {
    $entry['reason'] = 'DNI inexistente o ambiguo en Personal. Corrige la referencia de origen.';
    $entry['identity_suggestions'] = [['id' => '00000000-0000-0000-0000-000000000003', 'nombre_completo' => 'PERSONA DE PRUEBA', 'dni' => '87654321']];
}
$manifest = [
    'proposed' => $blocked ? [] : [$entry], 'blocked' => $blocked ? [$entry] : [],
    'unchanged' => [], 'warnings' => [],
];
$paginator = new LengthAwarePaginator([$entry], 1, 50, 1, [
    'path' => route('evaluaciones.historico.importar'),
]);
echo view('evaluaciones.historical-import', [
    'batch' => $batch, 'manifest' => $manifest, 'filters' => [],
    'status' => $blocked ? 'blocked' : 'proposed', 'paginator' => $paginator,
])->render();
