<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);

$options = collect([
    ['id' => '09000000-0000-4000-8000-000000000001', 'servicio' => 'Mantenimiento', 'turno' => 'DIA', 'horario' => '07:00'],
    ['id' => '09000000-0000-4000-8000-000000000002', 'servicio' => 'Soldadura', 'turno' => 'NOCHE', 'horario' => '19:00'],
])->map(fn ($group) => [...$group, 'mina' => 'Mina de prueba', 'area' => 'Operaciones', 'sait' => null]);
$selected = $options->firstWhere('id', $argv[1] ?? null);
$detail = '09000000-0000-4000-8000-000000000003';

echo view('mi-asistencia.mobile', [
    'user' => (object) ['email' => 'responsable@example.test', 'personal' => (object) ['nombre_completo' => 'Responsable de prueba']],
    'grupo' => $selected ? [
        ...$selected,
        'estado' => 'Abierta',
        'total' => 2,
        'puede_registrar' => true,
        'puede_marcar' => true,
        'detalle_propio_id' => $detail,
        'marca_propia' => ['id' => $detail, 'estado' => 'PENDIENTE', 'hora' => null],
        'sin_responsable' => false,
        'mensaje' => null,
    ] : null,
    'grupos' => $options,
    'fecha' => '2026-08-19',
    'turno' => '',
    'selectionUnavailable' => false,
])->render();
