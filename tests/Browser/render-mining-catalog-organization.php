<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$report = ['schema_ready' => ($argv[1] ?? '') !== 'missing', 'examenes' => [],
    'asociaciones' => [], 'aliases' => [], 'cambios' => [], 'minas' => [['id' => 'mine', 'nombre' => 'Unidad destino']],
    'sugerencias' => [['primero_id' => 'exam-1', 'segundo_id' => 'exam-2', 'reglas_distintas' => []]]];
foreach (['ESPACIO CONFINADO', 'ESPACIOS CONFINADOS', 'Curso <script>no ejecutar</script>'] as $index => $name) {
    $report['examenes'][] = ['id' => 'exam-'.($index + 1), 'nombre' => $name, 'activo' => $index !== 2,
        'minas_nombres' => ['Unidad destino'], 'examenes_trabajador' => 8, 'intentos' => 12, 'cursos_globales' => 0,
        'requiere_nota' => false, 'nota_minima' => null, 'permite_convalidacion' => true,
        'tiene_vigencia' => false, 'vigencia_dias' => null, 'max_intentos' => 2, 'precio' => null, 'moneda' => 'PEN',
        'requisitos' => [(object) ['mina_id' => 'mine', 'activo' => true, 'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true, 'vigencia_dias_override' => null]]];
}
$preview = ['payload' => ['accion' => 'asociar', 'motivo' => 'Variantes confirmadas de prueba',
    'principal_id' => 'exam-1', 'aliases' => []], 'impacto' => array_slice($report['examenes'], 0, 2), 'token' => 'fixture'];
echo view('personal.habilitacion-minera.catalog-organization', ['report' => $report,
    'preview' => $report['schema_ready'] ? $preview : null, 'errors' => new Illuminate\Support\ViewErrorBag()])->render();
