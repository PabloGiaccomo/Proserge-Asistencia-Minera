<?php

namespace Tests\Feature;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PersonalMinaHabilitacionCorrectedFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_vista_principal_muestra_acciones_y_no_formularios_administrativos_visibles(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Acciones')
            ->assertSee('Agregar examen')
            ->assertSee('Editar examen')
            ->assertSee('Configurar ex')
            ->assertSee('Importar Excel master')
            ->assertSee('Recalcular estados')
            ->assertDontSee('Cargar informacion actual')
            ->assertDontSee('Asignar trabajador a mina')
            ->assertDontSee('Catalogo general de examenes mineros');
    }

    public function test_agregar_examen_valida_campos_condicionales_y_maximo_dos_intentos(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'requiere_lugar' => true,
                'empresa_paga' => true,
                'precio' => 120,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-01',
                'tiene_vigencia' => true,
                'vigencia_dias' => 180,
                'max_intentos' => 2,
                'requiere_nota' => true,
                'nota_minima' => 14,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'El lugar es obligatorio cuando el examen se toma en un lugar especifico.');

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'max_intentos' => 3,
            ])
            ->assertSessionHasErrors('max_intentos');

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'requiere_lugar' => true,
                'lugar' => 'Sede autorizada',
                'empresa_paga' => true,
                'precio' => 120,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-01',
                'tiene_vigencia' => true,
                'vigencia_dias' => 180,
                'max_intentos' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('examenes_mineros', [
            'nombre' => 'EVALUACION OPERATIVA',
            'requiere_lugar' => true,
            'empresa_paga' => true,
            'precio' => 120,
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ]);
        $this->assertDatabaseHas('examen_minero_precios', [
            'precio' => 120,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-01',
        ]);
    }

    public function test_historial_de_precios_no_modifica_intentos_anteriores_y_nuevo_intento_usa_precio_vigente(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control de acceso',
            'tipo' => 'Control',
            'empresa_paga' => true,
            'precio' => 100,
            'moneda' => 'PEN',
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ], $actor);
        $service->storeExamPrice($exam, [
            'precio' => 150,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-06',
        ], $actor);

        $first = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($first->examenes->first(), [
            'fecha_programacion' => '2026-06-05',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $second = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($second->examenes->first(), [
            'fecha_programacion' => '2026-06-06',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $attempts = PersonalMinaExamenIntento::query()->orderBy('fecha_programacion')->get();
        $this->assertSame('100.00', $attempts->first()->precio_aplicado);
        $this->assertSame('150.00', $attempts->last()->precio_aplicado);
        $this->assertSame('historial_precio', $attempts->last()->fuente_precio);
    }

    public function test_configurar_examenes_por_mina_en_columnas_y_quitar_examen(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad columna');
        $exam = $this->createExam('Curso de ingreso');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('mine-columns', false)
            ->assertSee($mine->nombre)
            ->assertSee($exam->nombre);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertRedirect();

        $this->assertFalse($requirement->fresh()->activo);
    }

    public function test_editar_examen_genera_historial_de_precio_sin_modificar_intentos_previos(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control editable',
            'tipo' => 'Control',
            'empresa_paga' => true,
            'precio' => 100,
            'moneda' => 'PEN',
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ], $actor);

        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($assignment->examenes->first(), [
            'fecha_programacion' => '2026-06-05',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $oldAttempt = PersonalMinaExamenIntento::query()->firstOrFail();

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.examenes.update', $exam->id), [
                'nombre' => 'Control editable actualizado',
                'tipo' => 'Control',
                'empresa_paga' => true,
                'precio' => 160,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-08',
                'max_intentos' => 2,
                'activo' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('examenes_mineros', [
            'id' => $exam->id,
            'nombre' => 'CONTROL EDITABLE ACTUALIZADO',
            'precio' => 160,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('examen_minero_precios', [
            'examen_id' => $exam->id,
            'precio' => 160,
            'fecha_inicio' => '2026-06-08',
        ]);
        $this->assertSame('100.00', $oldAttempt->fresh()->precio_aplicado);

        $newAssignment = $this->assignmentWithExam($service, $actor, $exam->fresh());
        $service->registerAttempt($newAssignment->examenes->first(), [
            'fecha_programacion' => '2026-06-08',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->assertSame('160.00', PersonalMinaExamenIntento::query()->orderByDesc('fecha_programacion')->firstOrFail()->precio_aplicado);
    }

    public function test_recalcular_corrige_habilitado_sin_examenes_generados(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina sin examenes');
        $assignmentId = (string) Str::uuid();
        DB::table('personal_mina')->insert([
            'id' => $assignmentId,
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_asignacion' => '2026-06-08',
            'fecha_inicio_proceso' => '2026-06-08',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PersonalMinaHabilitacionService::class)->syncCurrentInformation($actor);

        $this->assertGreaterThanOrEqual(1, $result['asignaciones_corregidas']);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, PersonalMina::query()->findOrFail($assignmentId)->estado_habilitacion);
    }

    public function test_asignar_desde_tarjeta_genera_examenes_y_conserva_trabajador_seleccionado(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina tarjeta');
        $exam = $this->createExam('Examen tarjeta');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['trabajador' => $worker->dni, 'worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Asignar')
            ->assertSee($worker->nombre_completo);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.assign', ['worker_id' => $worker->id]), [
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertRedirect();

        $assignment = PersonalMina::query()->where('personal_id', $worker->id)->where('mina_id', $mine->id)->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
        ]);
    }

    public function test_listado_de_habilitacion_se_pagina_por_cantidad_elegida(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $mine = $this->createMine('Mina paginada');

        for ($i = 1; $i <= 16; $i++) {
            $worker = $this->createPersonal();
            DB::table('personal_mina')->insert([
                'id' => (string) Str::uuid(),
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado' => PersonalMina::ESTADO_EN_PROCESO,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
                'fecha_asignacion' => '2026-06-08',
                'fecha_inicio_proceso' => '2026-06-08',
                'activo' => true,
                'created_at' => now()->addSeconds($i),
                'updated_at' => now()->addSeconds($i),
            ]);
        }

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'per_page' => 10]))
            ->assertOk()
            ->assertSee('Asignaciones')
            ->assertSee('Mostrando 1 - 10 de 16 asignaciones');
    }

    public function test_excel_master_preview_no_guarda_y_confirmacion_importa_datos(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $file = $this->buildMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $this->assertDatabaseMissing('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseMissing('minas', ['nombre' => 'OPERACION DINAMICA']);

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(1, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(1, $preview['summary']['minas_nuevas']);
        $this->assertSame(1, $preview['summary']['examenes_nuevos']);

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseHas('minas', ['nombre' => 'OPERACION DINAMICA']);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'EXAMEN DINAMICO']);
        $this->assertDatabaseHas('personal_mina_examenes', ['estado' => PersonalMinaExamen::ESTADO_APROBADO]);
    }

    public function test_excel_master_real_con_hojas_por_mina_importa_evaluaciones_y_multiples_minas(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $file = $this->buildRealMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(2, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame(0, $preview['summary']['filas_con_error']);
        $this->assertNotContains('RESUMEN GRAL', collect($preview['rows'])->pluck('hoja')->all());

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $worker = Personal::query()->where('numero_documento', '77889911')->firstOrFail();
        $marcobre = Mina::query()->where('nombre', 'MARCOBRE')->firstOrFail();
        $cerroVerde = Mina::query()->where('nombre', 'CERRO VERDE')->firstOrFail();

        $this->assertDatabaseHas('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $marcobre->id,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $cerroVerde->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'EXAMEN MEDICO']);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'INDUCCION']);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_vencimiento' => '2027-05-30',
        ]);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'estado' => PersonalMinaExamen::ESTADO_VENCIDO,
            'fecha_vencimiento' => '2026-05-12',
        ]);
    }

    public function test_desaprobacion_bloquea_otras_minas_donde_el_examen_es_requerido(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $exam = $this->createExam('Examen bloqueante', ['permite_reintento' => false, 'max_intentos' => 1]);
        $mineOne = $this->createMine('Operacion uno');
        $mineTwo = $this->createMine('Operacion dos');

        foreach ([$mineOne, $mineTwo] as $mine) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mineOne->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $service->registerAttempt($assignment->examenes->first(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $board = collect($service->mineStatusBoardFor($worker));
        $target = $board->first(fn ($item) => $item['mine']->id === $mineTwo->id);

        $this->assertSame('BLOQUEADA', $target['state']);
        $this->assertStringContainsString('desaprobo un examen requerido', $target['reason']);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.assign'), [
                'personal_id' => $worker->id,
                'mina_id' => $mineTwo->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'No puede asignarse porque desaprobo un examen requerido.');

        $this->assertDatabaseMissing('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $mineTwo->id,
            'activo' => true,
        ]);
    }

    public function test_permisos_y_nombres_propios(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Curso protegido',
                'tipo' => 'Curso',
                'max_intentos' => 1,
            ])
            ->assertForbidden();

        $files = [
            app_path('Models/ExamenMineroPrecio.php'),
            app_path('Modules/Personal/Services/PersonalMinaExcelImportService.php'),
            app_path('Modules/Personal/Services/PersonalMinaHabilitacionService.php'),
            app_path('Modules/Personal/Controllers/PersonalMinaHabilitacionController.php'),
            resource_path('views/personal/habilitacion-minera/index.blade.php'),
            database_path('migrations/2026_06_06_000300_refine_mining_exam_flow.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', file_get_contents($file), $file);
        }
    }

    private function assignmentWithExam(PersonalMinaHabilitacionService $service, Usuario $actor, ExamenMinero $exam): PersonalMina
    {
        $mine = $this->createMine('Mina precio');
        $worker = $this->createPersonal();
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        return $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
    }

    private function buildMasterExcel()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operacion dinamica');
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C2', 'Cargo');
        foreach (['D1', 'E1', 'F1'] as $cell) {
            $sheet->setCellValue($cell, 'Examen dinamico');
        }
        $sheet->setCellValue('D2', 'Fecha de realizacion');
        $sheet->setCellValue('E2', 'Resultado');
        $sheet->setCellValue('F2', 'Observacion');
        $sheet->setCellValue('A3', '77889900');
        $sheet->setCellValue('B3', 'Trabajador Importado');
        $sheet->setCellValue('C3', 'Operario');
        $sheet->setCellValue('D3', '2026-06-06');
        $sheet->setCellValue('E3', 'Aprobado');
        $sheet->setCellValue('F3', 'Carga inicial');

        $path = storage_path('app/testing/master-habilitacion-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildRealMasterExcel()
    {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('RESUMEN GRAL');
        $summary->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'MARCOBRE', 'CERRO VERDE'],
            ['77889911', 'TRABAJADOR MULTIMINA', 'HABILITADO', 'EN PROCESO'],
        ]);

        $marcobre = $spreadsheet->createSheet();
        $marcobre->setTitle('MARCOBRE (2)');
        $marcobre->mergeCells('H1:J1');
        $marcobre->mergeCells('K1:M1');
        $marcobre->setCellValue('H1', 'EXAMEN MEDICO');
        $marcobre->setCellValue('K1', 'INDUCCION');
        $marcobre->fromArray([
            ['N°', 'ESTADO / HABILITACION', 'DNI', 'APELLIDOS Y NOMBRES', 'CARGO', 'CELULAR PARTICULAR', 'CC', 'F PROG.', 'F. VTO', 'ESTADO EMO', 'F PROG.', 'F. VTO', 'ESTADO / INDUCCION'],
            ['1', 'HABILITADO', '77889911', 'TRABAJADOR MULTIMINA', 'OPERARIO', '999111222', '101100001', '2026-05-30', '2027-05-30', 'VIGENTE', '2026-06-01', '2027-06-01', 'VIGENTE'],
        ], null, 'A2');

        $cerro = $spreadsheet->createSheet();
        $cerro->setTitle('CERRO VERDE CERRO');
        $cerro->mergeCells('H1:J1');
        $cerro->setCellValue('H1', 'EXAMEN MEDICO');
        $cerro->fromArray([
            ['N°', 'ESTADO ACRED.', 'DNI', 'APELLIDOS Y NOMBRES', 'CARGO', 'CELULAR PARTICULAR', 'CC', 'F PROG.', 'F. VTO', 'ESTADO EMO'],
            ['1', 'EN PROCESO', '77889911', 'TRABAJADOR MULTIMINA', 'OPERARIO', '999111222', '101100001', '2026-01-12', '2026-05-12', 'VENCIDO'],
        ], null, 'A2');

        $path = storage_path('app/testing/master-real-habilitacion-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-real.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function createExam(string $name, array $overrides = []): ExamenMinero
    {
        $id = (string) Str::uuid();
        DB::table('examenes_mineros')->insert(array_merge([
            'id' => $id,
            'nombre' => mb_strtoupper($name),
            'tipo' => 'General',
            'requiere_lugar' => false,
            'empresa_paga' => false,
            'tiene_vigencia' => false,
            'permite_reintento' => true,
            'max_intentos' => 2,
            'critico' => false,
            'desaprueba_finaliza_proceso' => false,
            'requiere_nota' => false,
            'solo_resultado' => true,
            'permite_convalidacion' => false,
            'activo' => true,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return ExamenMinero::query()->findOrFail($id);
    }

    private function createMine(string $name): Mina
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => mb_strtoupper($name) . ' ' . Str::upper(Str::random(4)),
            'unidad_minera' => $name,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Mina::query()->findOrFail($id);
    }

    private function createPersonal(): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(73000000, 73999999);
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'TRABAJADOR HABILITACION',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Personal::query()->findOrFail($id);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_HABILITACION_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'habilitacion@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
