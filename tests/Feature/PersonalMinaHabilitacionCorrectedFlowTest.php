<?php

namespace Tests\Feature;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
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
            ->assertSee('Analizando Excel master')
            ->assertSee('data-defer-loading-submit="true"', false)
            ->assertSee('data-persistent-modal="true"', false)
            ->assertSee('mineExcelImportModalOpen')
            ->assertDontSee('Recalcular estados')
            ->assertDontSee('modal-recalcular')
            ->assertDontSee('Cargar informacion actual')
            ->assertDontSee('Asignar trabajador a mina')
            ->assertDontSee('Catalogo general de examenes mineros');
    }

    public function test_menu_acciones_respeta_permisos_granulares_de_habilitacion_minera(): void
    {
        $userId = $this->createUser([
            'habilitacion_minera' => ['ver', 'crear', 'ver_historial_precios'],
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Acciones')
            ->assertSee('Agregar examen')
            ->assertSee('Historial de precios')
            ->assertDontSee('Editar examen')
            ->assertDontSee("openDialog('modal-configuracion')", false)
            ->assertDontSee('Importar Excel master')
            ->assertDontSee('Agregar precio');
    }

    public function test_backend_distingue_permiso_programar_de_registrar_resultado(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('Examen permisos'));
        $workerExam = $assignment->examenes->first();

        $programmerId = $this->createUser(['habilitacion_minera' => ['ver', 'programar']]);
        $registrarId = $this->createUser(['habilitacion_minera' => ['ver', 'registrar']]);

        $this->withSession($this->sessionFor($programmerId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
                'fecha_programacion' => '2026-07-20',
            ])
            ->assertRedirect();

        $this->withSession($this->sessionFor($programmerId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
                'fecha_realizacion' => '2026-07-20',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($registrarId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
                'fecha_programacion' => '2026-07-21',
            ])
            ->assertForbidden();
    }

    public function test_vista_no_abre_importar_excel_al_entrar_con_preview_guardada(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $preview = $this->fakeImportPreview();

        $this->withSession(array_merge($this->sessionFor($userId), [
            'habilitacion_mina_import_preview' => $preview,
        ]))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Vista previa generada')
            ->assertSee('10/06/2026 12:52:31 hora Perú')
            ->assertSee('Importando Excel master')
            ->assertSee('data-inline-loading="#mineExcelConfirmLoading"', false)
            ->assertDontSee("window.sessionStorage?.setItem('mineExcelImportModalOpen', '1');", false);
    }

    public function test_vista_abre_importar_excel_solo_con_flag_temporal(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $preview = $this->fakeImportPreview();

        $this->withSession(array_merge($this->sessionFor($userId), [
            'habilitacion_mina_import_preview' => $preview,
            'habilitacion_mina_import_modal_open' => true,
        ]))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee("window.sessionStorage?.setItem('mineExcelImportModalOpen', '1');", false);
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

    public function test_historial_de_precios_no_modifica_intentos_anteriores_y_el_intento_usa_fecha_de_registro(): void
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
        $this->assertSame('150.00', $attempts->first()->precio_aplicado);
        $this->assertSame('150.00', $attempts->last()->precio_aplicado);
        $this->assertSame('historial_precio', $attempts->last()->fuente_precio);
        $this->assertSame('2026-06-06', $attempts->first()->fecha_precio_aplicado->toDateString());
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
            ->assertSee('mine-config-matrix-wrap', false)
            ->assertSee('height: min(62vh, 680px);', false)
            ->assertSee('min-height: 232px;', false)
            ->assertSee('overflow: hidden;', false)
            ->assertSee('overflow-y: hidden;', false)
            ->assertSee($mine->nombre)
            ->assertSee($exam->nombre);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertRedirect();

        $this->assertFalse($requirement->fresh()->activo);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertDontSee('data-requirement-id="' . $requirement->id . '"', false);
    }

    public function test_quitar_examen_de_mina_desde_modal_responde_json_sin_recargar(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad ajax');
        $exam = $this->createExam('Curso ajax');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->postJson(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertOk()
            ->assertJsonPath('message', 'Examen quitado de la mina correctamente.')
            ->assertJsonPath('requirement_id', $requirement->id)
            ->assertJsonPath('deactivated_requirement_ids.0', $requirement->id)
            ->assertJsonPath('mina_id', $mine->id)
            ->assertJsonPath('active_count', 0);

        $this->assertFalse($requirement->fresh()->activo);
    }

    public function test_quitar_examen_de_mina_desactiva_duplicados_activos_equivalentes(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad duplicada');
        $exam = $this->createExam('Bloqueo duplicado');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $duplicate = MinaRequisito::query()->create([
            'id' => (string) Str::uuid(),
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'nombre' => $exam->nombre,
            'obligatorio' => true,
            'critico' => false,
            'reprogramable' => true,
            'activo' => true,
            'orden' => 2,
            'permite_no_aplica' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->postJson(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertOk()
            ->assertJsonPath('active_count', 0)
            ->assertJsonFragment(['deactivated_requirement_ids' => [$requirement->id, $duplicate->id]]);

        $this->assertFalse($requirement->fresh()->activo);
        $this->assertFalse($duplicate->fresh()->activo);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertDontSee('data-requirement-id="' . $requirement->id . '"', false)
            ->assertDontSee('data-requirement-id="' . $duplicate->id . '"', false);
    }

    public function test_configurar_requisito_genera_y_recalcula_automaticamente_sin_boton_manual(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina automatica');
        $exam = $this->createExam('Examen automatico');
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->assertDatabaseMissing('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.store'), [
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $requirement = MinaRequisito::query()
            ->where('mina_id', $mine->id)
            ->where('examen_id', $exam->id)
            ->firstOrFail();
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
            'mina_requisito_id' => $requirement->id,
            'estado' => PersonalMinaExamen::ESTADO_PENDIENTE,
        ]);

        $workerExam = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $exam->id)
            ->firstOrFail();
        $service->registerAttempt($workerExam, [
            'fecha_realizacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($requirement->fresh()->activo);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
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

    public function test_selector_de_trabajador_queda_limpio_y_filtros_operativos_van_en_matriz(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Buscar por nombre, DNI o cargo')
            ->assertSee('id="matrixFilterForm"', false)
            ->assertSee('form="matrixFilterForm"', false);

        $html = $response->getContent();
        $workerForm = Str::betweenFirst($html, 'id="workerSearchForm"', '</form>');
        $matrixForm = Str::betweenFirst($html, 'id="matrixFilterForm"', '</form>');

        $this->assertStringNotContainsString('name="mina_id"', $workerForm);
        $this->assertStringNotContainsString('name="estado_habilitacion"', $workerForm);
        $this->assertStringNotContainsString('name="estado_laboral"', $workerForm);
        $this->assertStringNotContainsString('name="estado_examen"', $workerForm);
        $this->assertStringContainsString('name="mina_id"', $matrixForm);
        $this->assertStringNotContainsString('name="estado_habilitacion"', $matrixForm);
        $this->assertStringNotContainsString('name="estado_laboral"', $matrixForm);
        $this->assertStringContainsString('name="estado_examen"', $matrixForm);
    }

    public function test_selector_de_trabajador_muestra_total_real_y_no_solo_limite_visible(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $prefix = 'CONTADOR VISUAL ' . Str::upper(Str::random(5));

        for ($i = 1; $i <= 12; $i++) {
            $this->createPersonalWithDocument((string) (78000000 + $i), [
                'nombre_completo' => $prefix . ' ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'trabajador' => $prefix,
                'worker_limit' => 10,
            ]))
            ->assertOk()
            ->assertSee('Mostrando 1-10 de 12')
            ->assertSee('mine-page-buttons', false)
            ->assertSee('worker_page=2', false);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'trabajador' => $prefix,
                'worker_limit' => 10,
                'worker_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Mostrando 11-12 de 12')
            ->assertSee($prefix . ' 11')
            ->assertSee($prefix . ' 12')
            ->assertDontSee($prefix . ' 01');
    }

    public function test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignedWorker = $this->createPersonal();
        $unassignedWorker = $this->createPersonal();
        $mine = $this->createMine('Mina acciones');
        $exam = $this->createExam('Examen acciones');
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->assignMine([
            'personal_id' => $assignedWorker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_limit' => 80]))
            ->assertOk()
            ->assertSee('data-testid="worker-assign-' . $assignedWorker->id . '"', false)
            ->assertSee('data-testid="worker-manage-' . $assignedWorker->id . '"', false)
            ->assertSee('data-testid="worker-assign-' . $unassignedWorker->id . '"', false);

        $this->assertStringNotContainsString(
            'data-testid="worker-manage-' . $unassignedWorker->id . '"',
            $response->getContent()
        );
    }

    public function test_gestionar_examenes_del_trabajador_permite_abrir_o_generar_desde_el_modal(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mineWithExams = $this->createMine('Mina gestion con examenes');
        $mineWithoutGenerated = $this->createMine('Mina gestion sin generados');
        $hiddenByFilterMine = $this->createMine('Mina filtro distinta');
        $exam = $this->createExam('Examen gestion modal');

        $service->storeRequirement([
            'mina_id' => $mineWithExams->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->storeRequirement([
            'mina_id' => $mineWithoutGenerated->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignmentWithExams = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mineWithExams->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $assignmentWithoutGeneratedId = (string) Str::uuid();
        DB::table('personal_mina')->insert([
            'id' => $assignmentWithoutGeneratedId,
            'personal_id' => $worker->id,
            'mina_id' => $mineWithoutGenerated->id,
            'estado' => PersonalMina::ESTADO_EN_PROCESO,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'fecha_asignacion' => '2026-06-11',
            'fecha_inicio_proceso' => '2026-06-11',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', [
                'worker_id' => $worker->id,
                'mina_id' => $hiddenByFilterMine->id,
            ]))
            ->assertOk()
            ->assertSee('Abrir gestion')
            ->assertSee('Generar examenes')
            ->assertSee('Programar examen')
            ->assertSee('Ver programados')
            ->assertSee('Registrar examen realizado')
            ->assertSee($assignmentWithExams->id)
            ->assertSee($assignmentWithoutGeneratedId)
            ->assertSee('/personal/habilitacion-minera/asignaciones/' . $assignmentWithoutGeneratedId . '/generar-examenes', false)
            ->assertSee('generate_exams_url', false);
    }

    public function test_minas_disponibles_muestran_asignado_pendiente_y_evitan_reasignacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina pendiente inicio');
        $exam = $this->createExam('Examen pendiente inicio');
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('data-testid="mine-worker-mine-board"', false)
            ->assertSee('data-visual-state="ASIGNADO_PENDIENTE_INICIO"', false)
            ->assertSee('Programar examenes')
            ->assertSee('Sin examenes iniciados')
            ->assertSee('Ya asignada')
            ->assertSee('openWorkerExams', false);
    }

    public function test_desasignar_mina_desde_habilitacion_la_deja_disponible_para_el_trabajador(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina desasignable');
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Desasignar')
            ->assertSee(route('personal.habilitacion-minera.deactivate', ['assignmentId' => $assignment->id]), false);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.deactivate', ['assignmentId' => $assignment->id, 'worker_id' => $worker->id]), [
                'observacion' => 'Ya no corresponde a esta mina.',
            ])
            ->assertRedirect(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]));

        $this->assertDatabaseHas('personal_mina', [
            'id' => $assignment->id,
            'activo' => false,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Disponible para asignar')
            ->assertDontSee('Ya asignada');
    }

    public function test_matriz_diferencia_desaprobado_con_reintento_de_desaprobado_definitivo(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina reintento visual');
        $exam = $this->createExam('Examen reintento visual', [
            'permite_reintento' => true,
            'max_intentos' => 2,
        ]);
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $service->registerAttempt($assignment->examenes->first(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id]))
            ->assertOk()
            ->assertSee('mine-exam-cell orange', false)
            ->assertSee('Registrar siguiente intento');
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
            ->assertSee('Mostrar')
            ->assertSee('trabajadores')
            ->assertDontSee('Asignaciones por página')
            ->assertSee('Mostrando 1 - 10 de 16 trabajadores');
    }

    public function test_matriz_operativa_por_mina_muestra_examenes_dinamicos_y_estados_visuales(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina matriz');
        $exam = $this->createExam('Examen matriz');

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $service->registerAttempt($assignment->examenes->first(), [
            'fecha_programacion' => '2026-06-08',
            'fecha_realizacion' => '2026-06-08',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id]))
            ->assertOk()
            ->assertSee('Matriz operativa')
            ->assertSee('data-testid="mine-operational-matrix"', false)
            ->assertSee('Estado habilitacion')
            ->assertSee('Accion siguiente')
            ->assertSee($exam->nombre)
            ->assertSee('Intento 1/2')
            ->assertSee('mine-exam-cell ok', false)
            ->assertSee('data-visual-state="' . PersonalMina::ESTADO_HABILITADO . '"', false);
    }

    public function test_filtro_de_estado_examen_limita_trabajadores_y_columnas_de_la_matriz(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina filtro examen');
        $approvedExam = $this->createExam('Examen aprobado filtro');
        $pendingExam = $this->createExam('Examen pendiente filtro');

        foreach ([$approvedExam, $pendingExam] as $exam) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $approvedExam->id)
            ->update(['estado' => PersonalMinaExamen::ESTADO_APROBADO]);

        PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $pendingExam->id)
            ->update(['estado' => PersonalMinaExamen::ESTADO_PENDIENTE]);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', [
                'mina_id' => $mine->id,
                'estado_examen' => PersonalMinaExamen::ESTADO_APROBADO,
            ]))
            ->assertOk()
            ->assertSee('name="estado_examen"', false)
            ->assertDontSee('name="estado_habilitacion"', false)
            ->assertDontSee('name="estado_laboral"', false);

        $matrix = Str::betweenFirst($response->getContent(), 'data-testid="mine-operational-matrix"', '</table>');

        $this->assertStringContainsString($approvedExam->nombre, $matrix);
        $this->assertStringNotContainsString($pendingExam->nombre, $matrix);
    }

    public function test_proximos_vencimientos_muestra_solo_minas_asignadas_y_abre_gestion(): void
    {
        Carbon::setTestNow('2026-06-10 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonalWithDocument('73187777', [
            'nombre_completo' => 'TRABAJADOR POR VENCER',
        ]);
        $assignedMine = $this->createMine('Mina vencimiento asignada');
        $unassignedMine = $this->createMine('Mina vencimiento no asignada');
        $exam = $this->createExam('Examen por vencer', [
            'tiene_vigencia' => true,
            'vigencia_dias' => 180,
        ]);

        foreach ([$assignedMine, $unassignedMine] as $mine) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $assignedMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->load('examenes');

        $workerExam = $assignment->examenes->first();
        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_realizacion' => '2026-01-10',
            'fecha_vencimiento' => '2026-06-25',
        ])->save();

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Seleccionar trabajador')
            ->assertSee('Matriz operativa')
            ->assertSee('Proximos vencimientos')
            ->assertSee('data-mine-view-tab="expiring"', false)
            ->assertSee('TRABAJADOR POR VENCER')
            ->assertSee('EXAMEN POR VENCER')
            ->assertSee('25/06/2026')
            ->assertSee('openWorkerExams', false);

        $expiringTable = Str::betweenFirst($response->getContent(), 'data-testid="mine-upcoming-expirations"', '</table>');

        $this->assertStringContainsString($assignedMine->nombre, $expiringTable);
        $this->assertStringNotContainsString($unassignedMine->nombre, $expiringTable);
    }

    public function test_examenes_programados_muestran_intentos_pendientes_y_abren_gestion(): void
    {
        Carbon::setTestNow('2026-06-10 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonalWithDocument('73187778', [
            'nombre_completo' => 'TRABAJADOR PROGRAMADO',
        ]);
        $mine = $this->createMine('Mina programada');
        $exam = $this->createExam('Examen programado visible');

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->load('examenes');

        $workerExam = $assignment->examenes->first();
        $service->registerAttempt($workerExam, [
            'fecha_programacion' => '2026-06-19',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
        ], null, $actor);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['vista' => 'scheduled']))
            ->assertOk()
            ->assertSee('Examenes programados')
            ->assertSee('data-mine-view-tab="scheduled"', false)
            ->assertSee('data-testid="mine-scheduled-exams"', false)
            ->assertSee('TRABAJADOR PROGRAMADO')
            ->assertSee('EXAMEN PROGRAMADO VISIBLE')
            ->assertSee('19/06/2026')
            ->assertSee('Ver programados')
            ->assertSee('openWorkerExams', false);

        $scheduledTable = Str::betweenFirst($response->getContent(), 'data-testid="mine-scheduled-exams"', '</table>');

        $this->assertStringContainsString($mine->nombre, $scheduledTable);
        $this->assertStringContainsString((string) $assignment->id, $scheduledTable);
        $this->assertStringContainsString((string) $workerExam->id, $scheduledTable);
    }

    public function test_vista_no_muestra_habilitado_visual_sin_examenes_configurados_o_generados(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina visual');
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
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

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id]))
            ->assertOk()
            ->assertSee('Sin examenes configurados para esta mina')
            ->assertSee('data-visual-state="' . PersonalMina::ESTADO_EN_PROCESO . '"', false)
            ->assertSee('En proceso');
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
            ->assertSessionHas('habilitacion_mina_import_preview')
            ->assertSessionHas('habilitacion_mina_import_modal_open');

        $this->assertDatabaseMissing('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseMissing('minas', ['nombre' => 'OPERACION DINAMICA']);

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(1, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(1, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(0, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(0, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame(1, $preview['summary']['minas_nuevas']);
        $this->assertSame(1, $preview['summary']['examenes_nuevos']);
        $this->assertSame(1, $preview['summary']['precios_detectados_omitidos']);
        $this->assertSame('OMITIR_TRABAJADOR_NO_ENCONTRADO', $preview['rows'][0]['accion_importacion']);

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'trabajadores_no_encontrados: 1'))
            ->assertSessionHas('habilitacion_mina_import_completed')
            ->assertSessionMissing('habilitacion_mina_import_preview');

        $this->assertDatabaseMissing('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseHas('minas', ['nombre' => 'OPERACION DINAMICA']);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'EXAMEN DINAMICO']);
        $this->assertDatabaseMissing('personal_mina_examenes', [
            'nombre_snapshot' => 'EXAMEN DINAMICO',
            'estado' => PersonalMinaExamen::ESTADO_APROBADO,
        ]);
    }

    public function test_excel_master_actualiza_solo_habilitacion_de_trabajador_existente_sin_pisar_datos_internos(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $worker = $this->createPersonalWithDocument('77889900', [
            'nombre_completo' => 'NOMBRE INTERNO',
            'puesto' => 'Cargo interno',
            'ocupacion' => 'Ocupacion interna',
            'contrato' => 'INDETERMINADO',
            'estado' => 'ACTIVO',
        ]);
        $file = $this->buildMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(0, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(1, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(1, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame('ACTUALIZAR_HABILITACION_EXISTENTE', $preview['rows'][0]['accion_importacion']);

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $worker->refresh();
        $this->assertSame('NOMBRE INTERNO', $worker->nombre_completo);
        $this->assertSame('Cargo interno', $worker->puesto);
        $this->assertSame('Ocupacion interna', $worker->ocupacion);
        $this->assertSame('INDETERMINADO', $worker->contrato);
        $this->assertSame('ACTIVO', $worker->estado);
        $this->assertSame(1, Personal::query()->where('numero_documento', '77889900')->count());

        $mine = Mina::query()->where('nombre', 'OPERACION DINAMICA')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $worker->id)
            ->where('mina_id', $mine->id)
            ->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'estado' => PersonalMinaExamen::ESTADO_APROBADO,
            'observacion' => 'Carga inicial',
        ]);

        $exam = ExamenMinero::query()->where('nombre', 'EXAMEN DINAMICO')->firstOrFail();
        $this->assertDatabaseMissing('examen_minero_precios', ['examen_id' => $exam->id]);
    }

    public function test_excel_master_real_con_hojas_por_mina_importa_evaluaciones_y_multiples_minas(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $this->createPersonalWithDocument('77889911', [
            'nombre_completo' => 'TRABAJADOR MULTIMINA',
            'puesto' => 'Cargo interno multiple',
            'ocupacion' => 'Ocupacion interna multiple',
        ]);
        $file = $this->buildRealMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(2, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
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
            'estado_habilitacion' => PersonalMina::ESTADO_NO_HABILITADO,
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

    public function test_excel_master_normaliza_dni_de_siete_digitos_y_actualiza_habilitacion_existente(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $workerId = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $workerId,
            'dni' => '09344260',
            'tipo_documento' => 'DNI',
            'numero_documento' => '09344260',
            'nombre_completo' => 'TRABAJADOR CON CERO',
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
        $file = $this->buildMasterExcelWithSevenDigitDni();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(0, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(1, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(1, $preview['summary']['dni_7_digitos_corregidos']);
        $this->assertSame(0, $preview['summary']['conflictos']);
        $this->assertSame('09344260', $preview['rows'][0]['documento']);
        $this->assertTrue($preview['rows'][0]['documento_corregido_con_cero']);
        $this->assertNotContains('EXAMEN MEDICO / OBS', collect($preview['unmapped'])->pluck('columna')->all());
        $this->assertNotContains('N°', collect($preview['unmapped'])->pluck('columna')->all());
        $this->assertNotContains('OCUPACION', collect($preview['unmapped'])->pluck('columna')->all());

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Personal::query()->where('numero_documento', '09344260')->count());
        $this->assertDatabaseMissing('personal', ['numero_documento' => '9344260']);
        $worker = Personal::query()->findOrFail($workerId);
        $this->assertSame('TRABAJADOR CON CERO', $worker->nombre_completo);
        $this->assertSame('Operario', $worker->puesto);
        $this->assertSame('Operario', $worker->ocupacion);
        $this->assertSame('FIJO', $worker->contrato);
        $this->assertSame('ACTIVO', $worker->estado);

        $mine = Mina::query()->where('nombre', 'BOROO')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $workerId)
            ->where('mina_id', $mine->id)
            ->firstOrFail();

        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'EXAMEN MEDICO',
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_programacion' => '2026-06-01',
            'fecha_vencimiento' => '2027-06-01',
            'lugar_snapshot' => 'CLINICA SAN MARTIN',
            'observacion' => 'Apto importado',
        ]);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'BLOQUEO',
            'estado' => PersonalMinaExamen::ESTADO_NO_APLICA,
            'observacion' => null,
        ]);
    }

    public function test_excel_master_programar_emo_queda_en_proceso_y_accion_pendiente(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $worker = $this->createPersonalWithDocument('77889922');
        $file = $this->buildMasterExcelProgramarEmo();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = session('habilitacion_mina_import_preview');
        $this->assertSame(PersonalMinaExamen::ESTADO_PROGRAMADO, data_get($preview, 'rows.0.examenes.0.preview.estado_mapeado'));
        $this->assertSame('PROGRAMAR_EXAMEN', data_get($preview, 'rows.0.examenes.0.preview.accion_pendiente'));

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $mine = Mina::query()->where('nombre', 'OPERACION PROGRAMACION')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $worker->id)
            ->where('mina_id', $mine->id)
            ->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'EXAMEN MEDICO',
            'estado' => PersonalMinaExamen::ESTADO_PROGRAMADO,
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

    public function test_recalcular_corrige_habilitado_con_requisitos_pero_sin_examenes_generados(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina configurada sin generados');
        $exam = $this->createExam('Examen pendiente de generar');

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

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

        $result = $service->syncCurrentInformation($actor);
        $assignment = PersonalMina::query()->with('examenes')->findOrFail($assignmentId);

        $this->assertGreaterThanOrEqual(1, $result['examenes_generados']);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertCount(1, $assignment->examenes);
    }

    public function test_snapshot_de_precio_caida_a_programacion_y_realizacion_si_no_hay_fecha_registro(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control precio prioridad',
            'empresa_paga' => true,
            'precio' => 80,
            'moneda' => 'PEN',
            'precio_desde' => '2026-01-01',
            'max_intentos' => 2,
        ], $actor);
        $service->storeExamPrice($exam, [
            'precio' => 120,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-05',
        ], $actor);
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->first();

        $bySchedule = $service->resolveAttemptPriceSnapshot($workerExam, null, '2026-06-05', '2026-06-01');
        $byDone = $service->resolveAttemptPriceSnapshot($workerExam, null, null, '2026-06-05');

        $this->assertSame('2026-06-05', $bySchedule['fecha']);
        $this->assertSame('120.00', (string) $bySchedule['precio']);
        $this->assertSame('2026-06-05', $byDone['fecha']);
        $this->assertSame('120.00', (string) $byDone['precio']);
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
        $this->assertDoesNotMatchRegularExpression(
            '/marcobre|cerro verde|chinalco|cuajone|toquepala|orcopampa|boroo/i',
            file_get_contents(app_path('Modules/Personal/Services/PersonalMinaExcelImportService.php')),
            'El importador no debe depender de nombres de minas quemados.'
        );
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
        foreach (['D1', 'E1', 'F1', 'G1'] as $cell) {
            $sheet->setCellValue($cell, 'Examen dinamico');
        }
        $sheet->setCellValue('D2', 'Fecha de realizacion');
        $sheet->setCellValue('E2', 'Resultado');
        $sheet->setCellValue('F2', 'Observacion');
        $sheet->setCellValue('G2', 'Precio');
        $sheet->setCellValue('A3', '77889900');
        $sheet->setCellValue('B3', 'Trabajador Importado');
        $sheet->setCellValue('C3', 'Operario');
        $sheet->setCellValue('D3', '2026-06-06');
        $sheet->setCellValue('E3', 'Aprobado');
        $sheet->setCellValue('F3', 'Carga inicial');
        $sheet->setCellValue('G3', 450);

        $path = storage_path('app/testing/master-habilitacion-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildMasterExcelProgramarEmo()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operacion programacion');
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C2', 'Cargo');
        foreach (['D1', 'E1'] as $cell) {
            $sheet->setCellValue($cell, 'Examen medico');
        }
        $sheet->setCellValue('D2', 'Estado EMO');
        $sheet->setCellValue('E2', 'Observacion');
        $sheet->setCellValue('A3', '77889922');
        $sheet->setCellValue('B3', 'Trabajador Programado');
        $sheet->setCellValue('C3', 'Operario');
        $sheet->setCellValue('D3', 'PROGRAMAR EMO');
        $sheet->setCellValue('E3', 'Pendiente de cita');

        $path = storage_path('app/testing/master-programar-emo-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-programar-emo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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

    private function buildMasterExcelWithSevenDigitDni()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOROO');
        $sheet->mergeCells('R1:V1');
        $sheet->mergeCells('W1:Y1');
        $sheet->setCellValue('R1', 'EXAMEN MEDICO');
        $sheet->setCellValue('W1', 'BLOQUEO');
        $sheet->fromArray([
            [
                'N°',
                'ESTADO ACRED.',
                'ESTADO',
                'RESPONSABLE',
                'TIPO CONTRATO',
                'OCUPACION',
                'CC',
                'DNI',
                'APELLIDOS Y NOMBRES',
                'CARGO',
                'CARGO CONTEO',
                'CELULAR PARTICULAR',
                'RESIDENCIA',
                'FECHA FIN',
                'ESTADO DE CONTRATO',
                'OBSR',
                'PASO',
                'F PROG.',
                'F. VTO',
                'ESTADO EMO',
                'OBS',
                'CLINICA',
                'F PROG.',
                'F. VTO',
                'ESTADO BLOQUEO',
            ],
            [
                '1',
                'HABILITADO',
                '',
                '',
                'INTER',
                'O',
                '101100022',
                '9344260',
                'NOMBRE DIFERENTE EN EXCEL',
                'OPERARIO',
                'OPERARIO',
                '999888777',
                'AREQUIPA',
                '',
                '',
                '',
                '',
                '2026-06-01',
                '2027-06-01',
                'VIGENTE',
                'Apto importado',
                'CLINICA SAN MARTIN',
                'NO APLICA',
                'NO APLICA',
                'NO APLICA',
            ],
        ], null, 'A2');

        $path = storage_path('app/testing/master-dni-cero-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-dni-cero.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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

        return $this->createPersonalWithDocument($document);
    }

    private function createPersonalWithDocument(string $document, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert(array_merge([
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
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function fakeImportPreview(): array
    {
        return [
            'token' => 'preview-test-token',
            'generated_at' => '2026-06-10T17:52:31+00:00',
            'summary' => [
                'filas_leidas' => 1,
                'trabajadores_existentes' => 1,
            ],
            'rows' => [],
            'errors' => [],
            'unmapped' => [],
            'conflicts' => [],
        ];
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
