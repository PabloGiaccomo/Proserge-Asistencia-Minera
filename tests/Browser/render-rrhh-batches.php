<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);

$base = '09000000-0000-4000-8000-00000000000';
$fixture = match ($argv[1] ?? '') {
    'contratos' => ['personal.contratos.carga-firmados', ['lote' => null, 'maxBytes' => 1024 * 1024, 'canPreviewPdf' => true]],
    'revision' => ['personal.fichas.ingresos-lote', [
        'batch' => ['id' => $base.'1', 'revision' => str_repeat('c', 64), 'vencido' => false, 'filas' => [
            ['id' => $base.'2', 'ingreso_id' => $base.'4', 'trabajador' => 'Trabajador de prueba 1', 'proposito' => 'ALTA',
                'fecha_inicio_contrato' => '2026-11-04', 'fecha_fin_contrato' => null, 'estado' => 'LISTA', 'mensaje' => null],
            ['id' => $base.'3', 'ingreso_id' => $base.'5', 'trabajador' => 'Trabajador de prueba 2', 'proposito' => 'ACTUALIZACION',
                'fecha_inicio_contrato' => null, 'fecha_fin_contrato' => null, 'estado' => 'LISTA', 'mensaje' => null],
        ]],
    ]],
    'bandeja' => ['personal.fichas.partials.ingresos-batch-review', []],
};

echo view(...$fixture)->render();
