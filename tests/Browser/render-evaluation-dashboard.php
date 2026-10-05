<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);
$summary = [['label'=>'Diaria','color'=>'#138b80','count'=>24,'scored'=>24,'max'=>20,'average'=>16.5],
    ['label'=>'Supervisores','color'=>'#4e79bc','count'=>12,'scored'=>12,'max'=>100,'average'=>84],
    ['label'=>'Residentes','color'=>'#af6b23','count'=>4,'scored'=>4,'max'=>20,'average'=>17]];
$group = fn ($name, $count) => ['name'=>$name,'count'=>$count,'types'=>['Diaria'=>['count'=>$count,'color'=>'#138b80']]];
$type = $argv[1] ?? '';
$details = $type ? (new App\Modules\Evaluaciones\Services\EvaluacionesDetailAnalytics)->build($type, collect([
    ['score'=>$type === 'supervisores' ? 80 : 16,'month'=>'2026-09','detail'=>[
        'desempeno_trabajo'=>4,'orden_limpieza'=>3,'seguridad_trabajo'=>3,'compromiso'=>3,'respuesta_emocional'=>3,'tuvo_incidencia'=>false,
        'respuestas'=>array_fill_keys(array_keys(App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate::WEIGHTS),4),
        'indicadores_kpi'=>1,'costos_servicio'=>0,'indicadores_kpi_items'=>['REPORTE_ASISTENCIA'],'costos_servicio_items'=>['NINGUNO'],
        'eventos_seguridad_respuesta'=>'SI','reportes_calidad_respuesta'=>'NO',
    ]],
])) : null;
echo view('evaluaciones.index', ['activeType'=>'dashboard','availableTypes'=>collect(['desempeno','supervisores','residentes','dashboard']),
    'user'=>new App\Models\Usuario(['id'=>'00000000-0000-0000-0000-000000000001']),
    'selectedDate'=>'2026-09-16','mines'=>collect(), 'dashboardFilters'=>['desde'=>'2026-09-01','hasta'=>'2026-09-16','mina'=>'','cargo'=>'','evaluacion_tipo'=>$type],
    'dashboard'=>['types'=>['desempeno'=>'Diaria','supervisores'=>'Supervisores','residentes'=>'Residentes'],'total'=>40,'people'=>28,'summary'=>$summary,'cargos'=>collect(['SUPERVISOR']),
        'activeFilterCount'=>1,'scoreCoverage'=>100,'scored'=>40,'unscored'=>0,
        'details'=>$details,'pie'=>'#138b80 0% 60%,#4e79bc 60% 90%,#af6b23 90% 100%',
        'months'=>[$group('2026-08',18),$group('2026-09',22)],'byMine'=>[$group('MINA DE PRUEBA',24),$group('Sin mina',4)],
        'byCargo'=>[$group('OPERARIO',24),$group('SUPERVISOR',12),$group('RESIDENTE',4)]]])->render();
