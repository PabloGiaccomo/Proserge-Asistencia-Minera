<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);
$rows = [];
for ($i = 0; $i < 45; $i++) {
    $category = ['dni', 'examen', 'identidad', 'archivo', 'historial'][$i % 5];
    $rows[] = ['sheet' => 'notas', 'line' => $i + 2, 'type' => $i % 2 ? 'Programacion' : 'Resultado',
        'dni' => sprintf('%08d', $i), 'name' => 'TRABAJADOR DE PRUEBA '.($i + 1), 'personal' => null,
        'exam' => 'CURSO DE SEGURIDAD '.($i + 1), 'matched_exam' => null, 'date' => '2026-09-14',
        'mine' => 'CERRO VERDE', 'matched_mine' => 'CERRO VERDE',
        'result' => 'APTO', 'score' => null, 'expiry' => '2027-09-14', 'observation' => '',
        'issues' => ['Revision de prueba: confirmar los datos del archivo.'], 'categories' => [$category]];
}
$rows[] = array_replace($rows[0], ['issues'=>[], 'categories'=>[], 'disposition'=>'changes',
    'attempts_used'=>3, 'attempts_limit'=>2, 'attempts_exceeded'=>true,
    'action'=>'Registrar resultado del trabajador sin programacion vinculada']);
$rows[] = array_replace($rows[0], ['issues'=>[], 'categories'=>[], 'disposition'=>'unchanged', 'action'=>'Resultado existente: omitir duplicado']);
echo view('personal.habilitacion-minera.bulk-preview', ['rows' => $rows,
    'token'=>'00000000-0000-4000-8000-000000000001',
    'report' => app(App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class)->report($rows)])->render();
