<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);

$type = $argv[1] ?? 'attendance';
$filters = ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-23'];

if ($type === 'absence') {
    $items = collect([
        (object) ['id' => 'f1', 'fecha' => '2026-09-22', 'trabajador_nombre' => 'TRABAJADOR DE PRUEBA UNO', 'trabajador_documento' => '70112233', 'trabajador_puesto' => 'OPERARIO MECANICO', 'destino_nombre' => 'CERRO VERDE', 'destino_tipo' => 'MINA', 'motivo' => 'INASISTENCIA ASISTENCIA', 'descripcion' => 'Generada durante el cierre', 'observaciones' => null, 'asistencia_detalle_id' => 'a1', 'estado' => 'ACTIVA'],
        (object) ['id' => 'f2', 'fecha' => '2026-09-20', 'trabajador_nombre' => 'TRABAJADOR DE PRUEBA DOS', 'trabajador_documento' => '70445566', 'trabajador_puesto' => 'SOLDADOR', 'destino_nombre' => 'MARCOBRE', 'destino_tipo' => 'MINA', 'motivo' => 'INASISTENCIA ASISTENCIA', 'descripcion' => 'Corregida por marcación tardía', 'observaciones' => null, 'asistencia_detalle_id' => 'a2', 'estado' => 'CORREGIDA'],
    ]);
    echo view('faltas.index', [
        'data' => $items,
        'filters' => $filters,
        'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2],
        'dashboard' => [
            'summary' => ['total' => 18, 'active' => 8, 'corrected' => 9, 'annulled' => 1, 'workers' => 6],
            'trend' => [['date' => '2026-09-18', 'label' => '18/09', 'total' => 3, 'active' => 2], ['date' => '2026-09-20', 'label' => '20/09', 'total' => 7, 'active' => 4], ['date' => '2026-09-22', 'label' => '22/09', 'total' => 4, 'active' => 2]],
            'top_workers' => [['name' => 'TRABAJADOR DE PRUEBA UNO', 'document' => '70112233', 'total' => 4], ['name' => 'TRABAJADOR DE PRUEBA DOS', 'document' => '70445566', 'total' => 2]],
            'by_state' => [],
            'by_destination' => [],
        ],
    ])->render();
    exit;
}

$groups = [
    ['id' => 'g1', 'asistencia_id' => 'a1', 'fecha' => '2026-09-22', 'turno' => 'DIA', 'codigo' => 'GRUPO MECÁNICO A', 'servicio' => 'Mantenimiento de planta', 'mina_id' => 'm1', 'mina' => 'CERRO VERDE', 'supervisor_id' => 's1', 'supervisor' => 'SUPERVISOR DE PRUEBA', 'estado_grupo' => 'PROGRAMADO', 'estado_asistencia' => 'REGISTRADO', 'programados' => 24, 'presentes' => 20, 'tardanzas' => 2, 'ausentes' => 2, 'justificados' => 0, 'no_corresponde' => 0, 'pendientes' => 2, 'cobertura' => 83.3, 'faltas_activas' => 0],
    ['id' => 'g2', 'asistencia_id' => 'a2', 'fecha' => '2026-09-21', 'turno' => 'NOCHE', 'codigo' => 'GRUPO SOLDADURA B', 'servicio' => 'Parada de mantenimiento', 'mina_id' => 'm2', 'mina' => 'MARCOBRE', 'supervisor_id' => 's2', 'supervisor' => 'RESPONSABLE TURNO NOCHE', 'estado_grupo' => 'CERRADO', 'estado_asistencia' => 'CERRADO', 'programados' => 18, 'presentes' => 17, 'tardanzas' => 1, 'ausentes' => 1, 'justificados' => 0, 'no_corresponde' => 0, 'pendientes' => 0, 'cobertura' => 94.4, 'faltas_activas' => 1],
];

echo view('asistencia.index', ['dashboard' => [
    'filters' => $filters,
    'summary' => ['groups' => 8, 'closed_groups' => 5, 'open_groups' => 2, 'pending_groups' => 1, 'scheduled' => 142, 'present' => 128, 'late' => 7, 'absent' => 9, 'justified' => 5, 'pending_marks' => 5, 'active_absences' => 8, 'coverage' => 90.1],
    'trend' => [['date' => '2026-09-18', 'label' => '18/09', 'scheduled' => 112, 'present' => 98, 'absent' => 8, 'coverage' => 87.5], ['date' => '2026-09-19', 'label' => '19/09', 'scheduled' => 125, 'present' => 116, 'absent' => 6, 'coverage' => 92.8], ['date' => '2026-09-20', 'label' => '20/09', 'scheduled' => 142, 'present' => 128, 'absent' => 9, 'coverage' => 90.1]],
    'by_mine' => [['id' => 'm1', 'name' => 'CERRO VERDE', 'groups' => 4, 'scheduled' => 82, 'present' => 70, 'absent' => 7, 'pending' => 5, 'coverage' => 85.4], ['id' => 'm2', 'name' => 'MARCOBRE', 'groups' => 4, 'scheduled' => 60, 'present' => 58, 'absent' => 2, 'pending' => 0, 'coverage' => 96.7]],
    'group_states' => [['state' => 'CERRADO', 'total' => 5], ['state' => 'REGISTRADO', 'total' => 2], ['state' => 'PENDIENTE', 'total' => 1]],
    'priority_groups' => [$groups[0]],
    'groups' => $groups,
    'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2],
    'options' => ['mines' => [], 'supervisors' => []],
]])->render();
