<?php

namespace Tests\Feature;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalMinaExamenesMinerosTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_crea_examen_con_vigencia_lugar_precio_y_sin_vigencia(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);

        $exam = $service->storeMiningExam([
            'nombre' => 'Control medico base',
            'tipo' => 'Medico',
            'lugar' => 'Clinica',
            'precio' => 180.50,
            'moneda' => 'PEN',
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
            'permite_reintento' => false,
        ], $actor);

        $this->assertSame('Clinica', $exam->lugar);
        $this->assertSame('180.50', $exam->precio);
        $this->assertTrue($exam->tiene_vigencia);
        $this->assertSame(1, $exam->max_intentos);

        $noValidity = $service->storeMiningExam([
            'nombre' => 'Charla sin vigencia',
            'tiene_vigencia' => false,
        ], $actor);

        $this->assertFalse($noValidity->tiene_vigencia);
        $this->assertNull($noValidity->vigencia_dias);
    }

    public function test_configura_examen_en_mina_y_genera_examenes_al_asignar_trabajador(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        [$service, $actor, $mine, $worker] = $this->context();
        $exam = $this->createExam(['nombre' => 'Induccion inicial']);
        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->assertSame($exam->id, $requirement->examen_id);
        $this->assertCount(1, $assignment->examenes);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
        $this->assertSame(PersonalMinaExamen::ESTADO_PENDIENTE, $assignment->examenes->first()->estado);
    }

    public function test_aprobar_todos_los_examenes_obligatorios_habilita_y_calcula_vencimiento(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $workerExam = $assignment->examenes->first();

        $service->registerAttempt($workerExam, [
            'fecha_realizacion' => '2026-06-06',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertDatabaseHas('personal_mina_examenes', [
            'id' => $workerExam->id,
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_vencimiento' => '2027-06-06',
        ]);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_programa_examen_y_completa_programado_sin_crear_otro_intento(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');

        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'tiene_vigencia' => true,
            'vigencia_dias' => 90,
        ]);
        $exam = $assignment->examenes->first();

        $service->registerAttempt($exam, [
            'fecha_programacion' => '2026-06-12',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
            'observacion' => 'Programacion inicial',
        ], null, $actor);

        $scheduled = PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $exam->id)
            ->firstOrFail();

        $this->assertSame(PersonalMinaExamen::ESTADO_PROGRAMADO, $exam->fresh()->estado);
        $this->assertSame(PersonalMinaExamenIntento::RESULTADO_PENDIENTE, $scheduled->resultado);
        $this->assertSame('2026-06-12', $scheduled->fecha_programacion->toDateString());
        $this->assertSame(1, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->count());

        try {
            $service->registerAttempt($exam->fresh(), [
                'fecha_programacion' => '2026-06-13',
                'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
            ], null, $actor);
            $this->fail('No debio permitir doble programacion pendiente.');
        } catch (ValidationException $exception) {
            $this->assertSame('Este examen ya tiene una programacion pendiente.', collect($exception->errors())->flatten()->first());
        }

        try {
            $service->completeScheduledAttempt($scheduled, [
                'fecha_realizacion' => '2026-06-10',
                'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            ], null, $actor);
            $this->fail('No debio registrar resultado antes de la fecha programada.');
        } catch (ValidationException $exception) {
            $this->assertSame('Todavia no se puede registrar resultado porque la fecha programada no ha pasado.', collect($exception->errors())->flatten()->first());
        }

        Carbon::setTestNow('2026-06-12 09:00:00');

        $service->completeScheduledAttempt($scheduled->fresh(), [
            'fecha_realizacion' => '2026-06-12',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(1, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->count());
        $this->assertDatabaseHas('personal_mina_examen_intentos', [
            'id' => $scheduled->id,
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2026-06-12',
        ]);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'id' => $exam->id,
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_vencimiento' => '2026-09-10',
        ]);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_examen_vencido_vuelve_a_proceso_y_por_vencer_mantiene_habilitado(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $exam = $assignment->examenes->first();

        $service->registerAttempt($exam, [
            'fecha_realizacion' => '2025-01-01',
            'fecha_vencimiento' => '2026-06-01',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $exam->fresh()->estado);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);

        [$service, $actor, $assignmentSoon] = $this->assignmentWithExam([
            'nombre' => 'Curso por vencer',
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $soonExam = $assignmentSoon->examenes->first();
        $service->registerAttempt($soonExam, [
            'fecha_realizacion' => '2025-07-01',
            'fecha_vencimiento' => '2026-06-20',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMinaExamen::ESTADO_POR_VENCER, $soonExam->fresh()->estado);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignmentSoon->fresh()->estado_habilitacion);
    }

    public function test_reintentos_permiten_superar_el_maximo_y_mantienen_no_habilitado_si_desaprueba(): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'permite_reintento' => true,
            'max_intentos' => 2,
        ]);
        $exam = $assignment->examenes->first();

        $service->registerAttempt($exam, ['resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO], null, $actor);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), ['resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO], null, $actor);
        $this->assertSame(PersonalMina::ESTADO_NO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), ['resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO], null, $actor);
        $this->assertSame(3, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->count());
        $this->assertSame(3, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->max('numero_intento'));
        $this->assertSame(PersonalMina::ESTADO_NO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
        ], null, $actor);
        $this->assertSame(4, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->max('numero_intento'));
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        [$service, $actor, $criticalAssignment] = $this->assignmentWithExam([
            'nombre' => 'Examen critico unico',
            'critico' => true,
            'permite_reintento' => false,
        ]);
        $service->registerAttempt($criticalAssignment->examenes->first(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMina::ESTADO_NO_HABILITADO, $criticalAssignment->fresh()->estado_habilitacion);
        $service->registerAttempt($criticalAssignment->examenes->first()->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $this->assertSame(2, PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $criticalAssignment->examenes->first()->id)->count());
    }

    public function test_examen_vencido_reinicia_intentos_operativos_sin_borrar_historial(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');

        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
            'permite_reintento' => true,
            'max_intentos' => 2,
        ]);
        $exam = $assignment->examenes->first();

        $service->registerAttempt($exam, [
            'fecha_realizacion' => '2025-05-01',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $service->registerAttempt($exam->fresh(), [
            'fecha_realizacion' => '2025-05-02',
            'fecha_vencimiento' => '2026-06-01',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $exam->fresh()->estado);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), [
            'fecha_programacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
        ], null, $actor);

        $scheduled = PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $exam->id)
            ->orderByDesc('numero_intento')
            ->firstOrFail();

        $this->assertSame(3, $scheduled->numero_intento);
        $this->assertSame(PersonalMinaExamen::ESTADO_PROGRAMADO, $exam->fresh()->estado);

        $service->completeScheduledAttempt($scheduled, [
            'fecha_realizacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), [
            'fecha_realizacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->assertSame(4, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->max('numero_intento'));
        $this->assertSame(4, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->count());
        $this->assertSame(PersonalMina::ESTADO_NO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), [
            'fecha_realizacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $this->assertSame(5, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->max('numero_intento'));
        $this->assertSame(5, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->count());
        $this->assertSame(PersonalMina::ESTADO_NO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_nota_minima_y_no_aplica(): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'requiere_nota' => true,
            'nota_minima' => 14,
        ]);
        $exam = $assignment->examenes->first();

        $service->registerAttempt($exam, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            'nota' => 12,
        ], null, $actor);
        $this->assertSame(PersonalMinaExamen::ESTADO_DESAPROBADO, $exam->fresh()->estado);

        [$service, $actor, $assignmentNoApply] = $this->assignmentWithExam(['nombre' => 'No aplica permitido']);
        $examNoApply = $assignmentNoApply->examenes->first();

        $service->markExamNotApplicable($examNoApply, [], $actor);
        $this->assertSame(PersonalMinaExamen::ESTADO_NO_APLICA, $examNoApply->fresh()->estado);
        $this->assertNull($examNoApply->fresh()->observacion);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignmentNoApply->fresh()->estado_habilitacion);
    }

    public function test_no_aplica_anula_programacion_y_el_nuevo_ciclo_empieza_en_cero_hasta_no_asistir(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        [$service, $actor, $assignment] = $this->assignmentWithExam(['max_intentos' => 2, 'permite_reintento' => true]);
        $exam = $assignment->examenes->sole();
        $exam->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $service->registerAttempt($exam, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO, 'fecha_realizacion' => '2026-10-09',
        ], null, $actor);
        $service->registerAttempt($exam->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE, 'fecha_programacion' => '2026-10-10',
        ], null, $actor);

        $service->markExamNotApplicable($exam->fresh(), ['observacion' => 'No requerido temporalmente'], $actor);

        $this->assertSame(['DESAPROBADO', 'ANULADO'], $exam->intentos()->orderBy('numero_intento')->pluck('resultado')->all());
        $this->assertSame(0, $service->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $service->registerAttempt($exam->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE, 'fecha_programacion' => '2026-10-10',
        ], null, $actor);
        $scheduled = $exam->intentos()->reorder('numero_intento', 'desc')->firstOrFail();
        $this->assertSame(3, $scheduled->numero_intento, 'La numeracion fisica historica no se reescribe.');
        $this->assertSame(0, $service->operationalAttemptCount($exam->fresh('intentos')), 'Programar no consume intento.');
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);

        $service->completeScheduledAttempt($scheduled, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO, 'fecha_realizacion' => '2026-10-10',
        ], null, $actor);
        $this->assertSame(1, $service->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame(1, $service->operationalAttemptNumber($scheduled->fresh()));
        $this->assertSame(3, $scheduled->fresh()->numero_intento);
    }

    public function test_aprobacion_vencida_reinicia_contador_operativo_sin_borrar_historial(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'tiene_vigencia' => true, 'vigencia_dias' => 30, 'max_intentos' => 2,
        ]);
        $exam = $assignment->examenes->sole();
        $service->registerAttempt($exam, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO, 'fecha_realizacion' => '2026-08-31',
        ], null, $actor);
        $service->registerAttempt($exam->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO, 'fecha_realizacion' => '2026-09-01',
            'fecha_vencimiento' => '2026-10-01',
        ], null, $actor);

        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $exam->fresh()->estado);
        $this->assertSame(0, $service->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame(2, $exam->intentos()->count());
        $service->registerAttempt($exam->fresh(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE, 'fecha_programacion' => '2026-10-10',
        ], null, $actor);
        $this->assertSame(0, $service->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame(3, $exam->intentos()->max('numero_intento'));
    }

    public function test_convalidacion_permitida_y_bloqueos(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        [$service, $actor, $originAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso convalidable',
            'permite_convalidacion' => true,
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $origin = $originAssignment->examenes->first();
        $this->grantMineScope($actor, $originAssignment->mina);
        $service->registerAttempt($origin, [
            'fecha_realizacion' => '2026-05-01',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $targetMine = $this->createMine('Mina destino');
        $targetWorker = $originAssignment->personal;
        $this->grantMineScope($actor, $targetMine);
        $examCatalog = $origin->fresh()->examen;
        $service->storeRequirement([
            'mina_id' => $targetMine->id,
            'examen_id' => $examCatalog->id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
            'fecha_inicio_convalidacion' => '2026-01-01',
            'fecha_fin_convalidacion' => '2026-12-31',
        ]);
        $targetAssignment = $service->assignMine([
            'personal_id' => $targetWorker->id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $targetExam = $targetAssignment->examenes->first();

        $service->convalidateExam($targetExam, $origin->id, ['observacion' => 'Convalidacion valida.'], $actor);
        $this->assertSame(PersonalMinaExamen::ESTADO_CONVALIDADO, $targetExam->fresh()->estado);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $targetAssignment->fresh()->estado_habilitacion);

        $otherWorkerMine = $this->createMine('Mina destino otro trabajador');
        $this->grantMineScope($actor, $otherWorkerMine);
        $service->storeRequirement([
            'mina_id' => $otherWorkerMine->id,
            'examen_id' => $examCatalog->id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $otherWorkerAssignment = $service->assignMine([
            'personal_id' => $this->createPersonal('ACTIVO')->id,
            'mina_id' => $otherWorkerMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        try {
            $service->convalidateExam($otherWorkerAssignment->examenes->first(), $origin->id, [], $actor);
            $this->fail('No debio convalidar el examen de otro trabajador.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'El examen origen debe pertenecer al mismo trabajador y a otro proceso.',
                collect($exception->errors())->flatten()->first()
            );
        }

        try {
            $service->convalidateExam($targetExam->fresh(), $origin->id, [], $actor);
            $this->fail('No debio convalidar una habilitacion que ya no esta en proceso.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Solo se puede convalidar mientras la habilitacion de destino esta en proceso.',
                collect($exception->errors())->flatten()->first()
            );
        }

        [$service, $actor, $blockedAssignment] = $this->assignmentWithExam(['nombre' => 'Convalidacion bloqueada']);
        try {
            $service->convalidateExam($blockedAssignment->examenes->first(), $origin->id, [], $actor);
            $this->fail('No debio convalidar si la mina no lo permite.');
        } catch (ValidationException $exception) {
            $this->assertSame('Este examen no permite convalidacion para esta mina.', collect($exception->errors())->flatten()->first());
        }

        [$service, $actor, $expiredOriginAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso vencido para convalidar',
            'permite_convalidacion' => true,
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $expiredOrigin = $expiredOriginAssignment->examenes->first();
        $this->grantMineScope($actor, $expiredOriginAssignment->mina);
        $service->registerAttempt($expiredOrigin, [
            'fecha_realizacion' => '2024-01-01',
            'fecha_vencimiento' => '2025-01-01',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $expiredTargetMine = $this->createMine('Mina destino vencido');
        $expiredTargetWorker = $expiredOriginAssignment->personal;
        $this->grantMineScope($actor, $expiredTargetMine);
        $service->storeRequirement([
            'mina_id' => $expiredTargetMine->id,
            'examen_id' => $expiredOrigin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $expiredTarget = $service->assignMine([
            'personal_id' => $expiredTargetWorker->id,
            'mina_id' => $expiredTargetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        try {
            $service->convalidateExam($expiredTarget->examenes->first(), $expiredOrigin->id, [], $actor);
            $this->fail('No debio convalidar un examen origen vencido.');
        } catch (ValidationException $exception) {
            $this->assertSame('El examen origen no esta aprobado o vigente.', collect($exception->errors())->flatten()->first());
        }

        [$service, $actor, $failedOriginAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso desaprobado para convalidar',
            'permite_convalidacion' => true,
            'permite_reintento' => false,
            'max_intentos' => 1,
        ]);
        $failedOrigin = $failedOriginAssignment->examenes->first();
        $this->grantMineScope($actor, $failedOriginAssignment->mina);
        $service->registerAttempt($failedOrigin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $failedTargetMine = $this->createMine('Mina destino desaprobado');
        $failedTargetWorker = $failedOriginAssignment->personal;
        $this->grantMineScope($actor, $failedTargetMine);
        $service->storeRequirement([
            'mina_id' => $failedTargetMine->id,
            'examen_id' => $failedOrigin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $failedTarget = $service->assignMine([
            'personal_id' => $failedTargetWorker->id,
            'mina_id' => $failedTargetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        try {
            $service->convalidateExam($failedTarget->examenes->first(), $failedOrigin->id, [], $actor);
            $this->fail('No debio convalidar un examen origen desaprobado.');
        } catch (ValidationException $exception) {
            $this->assertSame('El examen origen no esta aprobado o vigente.', collect($exception->errors())->flatten()->first());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('convalidationScores')]
    public function test_convalida_equivalencias_con_nota_opcional(?float $score, bool $requiresScore, bool $allowed): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'permite_convalidacion' => true,
            'requiere_nota' => $score !== null,
            'nota_minima' => $score !== null ? 10 : null,
        ]);
        $origin = $assignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            'nota' => $score,
        ], null, $actor);
        $origin = $origin->fresh();
        $mine = $this->createMine('Destino equivalente');
        $catalog = $this->createExam([
            'nombre' => 'Otro nombre de examen',
            'permite_convalidacion' => true,
            'requiere_nota' => $requiresScore,
            'nota_minima' => $requiresScore ? 14 : null,
        ]);
        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $catalog->id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
            'examenes_equivalentes' => [$origin->examen_id],
        ]);
        $targetAssignment = $service->assignMine([
            'personal_id' => $assignment->personal_id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $target = $targetAssignment->examenes->firstOrFail();
        $options = $service->convalidationOptions($target);
        $this->assertSame($allowed, $options['available']);
        if (!$allowed) {
            try {
                $service->convalidateExam($target, $origin->id, [], $actor);
                $this->fail('Debe rechazar una nota ausente o insuficiente para el destino.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('nota', $exception->errors());
                $this->assertSame('PENDIENTE', $target->fresh()->estado);
            }
            return;
        }
        $this->assertSame($origin->nombre_snapshot, $options['options'][0]['examen']);
        $this->assertSame($origin->nota_obtenida, $options['options'][0]['nota']);
        $result = $service->convalidateExam($target, $origin->id, [], $actor);
        $this->assertSame('CONVALIDADO', $result->estado);
        $this->assertSame($origin->nota_obtenida, $result->nota_obtenida);
        $this->assertSame($origin->id, $result->examen_origen_convalidado_id);
        $this->assertSame($mine->id, $result->contexto_convalidacion_json['mina_destino_id']);
        $this->assertSame($origin->nombre_snapshot, $result->contexto_convalidacion_json['examen_origen']);
        $requirement->forceFill(['examenes_equivalentes_json' => []])->save();
        $this->assertSame($origin->nombre_snapshot, $result->fresh()->contexto_convalidacion_json['examen_origen']);
    }

    public static function convalidationScores(): array
    {
        return [
            'ambos sin nota' => [null, false, true],
            'nota suficiente' => [16.0, true, true],
            'nota insuficiente' => [12.0, true, false],
            'origen sin nota y destino exige' => [null, true, false],
            'conserva nota aunque destino no exige' => [16.0, false, true],
        ];
    }

    public function test_convalidacion_mismo_examen_conserva_nota_y_rechaza_equivalencia_no_configurada(): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'permite_convalidacion' => true, 'requiere_nota' => true, 'nota_minima' => 14,
        ]);
        $origin = $assignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(), 'nota' => 17,
        ], null, $actor);
        $mine = $this->createMine('Destino mismo examen');
        $other = $this->createExam(['permite_convalidacion' => true]);
        foreach ([$origin->examen_id, $other->id] as $id) {
            $service->storeRequirement([
                'mina_id' => $mine->id, 'examen_id' => $id,
                'permite_convalidacion_mina' => true, 'convalidar_desde_otras_minas' => true,
            ]);
        }
        $destination = $service->assignMine([
            'personal_id' => $assignment->personal_id, 'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $same = $destination->examenes->firstWhere('examen_id', $origin->examen_id);
        $result = $service->convalidateExam($same, $origin->id, [], $actor);
        $this->assertSame('17.00', $result->nota_obtenida);
        $different = $destination->examenes->firstWhere('examen_id', $other->id);
        $this->assertFalse($service->convalidationOptions($different)['available']);
        $this->expectException(ValidationException::class);
        $service->convalidateExam($different, $origin->id, [], $actor);
    }

    public function test_configuracion_de_convalidacion_existente_exige_permiso_y_guarda_origenes(): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam(['permite_convalidacion' => true]);
        $requirement = $assignment->examenes->firstOrFail()->requisitoMina;
        $equivalent = $this->createExam();
        $url = route('personal.habilitacion-minera.requisitos.convalidation', $requirement->id);
        $payload = [
            'permite_convalidacion_mina' => 1,
            'convalidar_desde_otras_minas' => 1,
            'examenes_equivalentes' => [$equivalent->id],
        ];
        $viewer = $this->createUser(['habilitacion_minera' => ['ver']]);
        $this->withSession($this->sessionFor($viewer))->post($url, $payload)->assertForbidden();
        $configurator = $this->createUser(['habilitacion_minera' => ['ver', 'configurar']]);
        $this->withSession($this->sessionFor($configurator))->post($url, $payload)->assertSessionHas('success');
        $this->assertTrue($requirement->fresh()->convalidar_desde_otras_minas);
        $this->assertSame([$equivalent->id], $requirement->fresh()->examenes_equivalentes_json);
    }

    public function test_convalidacion_requiere_permiso_pero_no_scope_de_mina(): void
    {
        [$service, $setupActor, $originAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso para convalidar sin scope',
            'permite_convalidacion' => true,
        ]);
        $origin = $originAssignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
        ], null, $setupActor);

        $targetMine = $this->createMine('Mina sin scope');
        $service->storeRequirement([
            'mina_id' => $targetMine->id,
            'examen_id' => $origin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $targetAssignment = $service->assignMine([
            'personal_id' => $originAssignment->personal_id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $setupActor);
        $targetExam = $targetAssignment->examenes->firstOrFail();

        $withoutPermission = $this->createUser(['habilitacion_minera' => ['ver']]);
        $this->withSession($this->sessionFor($withoutPermission))
            ->post(route('personal.habilitacion-minera.exam.convalidate', $targetExam->id), [
                'examen_origen_id' => $origin->id,
            ])->assertForbidden();
        $this->assertSame(PersonalMinaExamen::ESTADO_PENDIENTE, $targetExam->fresh()->estado);

        $permittedUser = $this->createUser(['habilitacion_minera' => ['ver', 'convalidar']]);
        $this->assertDatabaseMissing('usuario_mina_scope', ['usuario_id' => $permittedUser]);
        $this->withSession($this->sessionFor($permittedUser))
            ->post(route('personal.habilitacion-minera.exam.convalidate', $targetExam->id), [
                'examen_origen_id' => $origin->id,
            ])->assertSessionHasErrors('confirmar');
        $this->assertSame(PersonalMinaExamen::ESTADO_PENDIENTE, $targetExam->fresh()->estado);
        $this->withSession($this->sessionFor($permittedUser))
            ->post(route('personal.habilitacion-minera.exam.convalidate', $targetExam->id), [
                'examen_origen_id' => $origin->id,
                'confirmar' => 1,
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(PersonalMinaExamen::ESTADO_CONVALIDADO, $targetExam->fresh()->estado);
    }

    public function test_opciones_de_convalidacion_solo_muestran_origen_vigente_del_mismo_examen_y_trabajador(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [$service, $actor, $originAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso con origen elegible',
            'permite_convalidacion' => true,
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $origin = $originAssignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2026-09-01',
        ], null, $actor);

        $targetMine = $this->createMine('Mina convalidacion disponible');
        $service->storeRequirement([
            'mina_id' => $targetMine->id,
            'examen_id' => $origin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $target = $service->assignMine([
            'personal_id' => $originAssignment->personal_id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->examenes->firstOrFail();

        $withoutPermission = $this->createUser(['habilitacion_minera' => ['ver']]);
        $this->withSession($this->sessionFor($withoutPermission))
            ->get(route('personal.habilitacion-minera.exam.convalidation-options', $target->id))
            ->assertForbidden();

        $permitted = $this->createUser(['habilitacion_minera' => ['ver', 'convalidar']]);
        $this->withSession($this->sessionFor($permitted))
            ->get(route('personal.habilitacion-minera.exam.convalidation-options', $target->id))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.id', $origin->id)
            ->assertJsonPath('options.0.mina', $originAssignment->mina->nombre);

        $origin->forceFill(['fecha_vencimiento' => '2026-10-04'])->save();
        $this->withSession($this->sessionFor($permitted))
            ->get(route('personal.habilitacion-minera.exam.convalidation-options', $target->id))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonCount(0, 'options');
        $origin->forceFill(['fecha_vencimiento' => '2027-09-01'])->save();

        $this->withSession($this->sessionFor($permitted))
            ->post(route('personal.habilitacion-minera.exam.convalidate', $target->id), [
                'examen_origen_id' => $origin->id,
                'confirmar' => 1,
            ])->assertSessionHas('success');

        $this->withSession($this->sessionFor($permitted))
            ->get(route('personal.habilitacion-minera.exam.convalidation-options', $target->id))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonCount(0, 'options');
    }

    public function test_convalidacion_no_reemplaza_resultado_ya_resuelto(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [$service, $actor, $originAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso no reemplazable',
            'permite_convalidacion' => true,
        ]);
        $origin = $originAssignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2026-09-01',
        ], null, $actor);

        $targetMine = $this->createMine('Mina con examen resuelto');
        $service->storeRequirement([
            'mina_id' => $targetMine->id,
            'examen_id' => $origin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $otherExam = $this->createExam(['nombre' => 'Otro requisito pendiente']);
        $service->storeRequirement(['mina_id' => $targetMine->id, 'examen_id' => $otherExam->id]);
        $targetAssignment = $service->assignMine([
            'personal_id' => $originAssignment->personal_id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $target = $targetAssignment->examenes->firstWhere('examen_id', $origin->examen_id);
        $service->registerAttempt($target, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2026-09-02',
        ], null, $actor);

        $this->expectException(ValidationException::class);
        try {
            $service->convalidateExam($target->fresh(), $origin->id, [], $actor);
        } finally {
            $this->assertSame(PersonalMinaExamen::ESTADO_APROBADO, $target->fresh()->estado);
            $this->assertNull($target->fresh()->examen_origen_convalidado_id);
        }
    }

    public function test_vista_del_trabajador_prepara_boton_y_opciones_de_convalidacion(): void
    {
        [$service, $actor, $assignment] = $this->assignmentWithExam([
            'nombre' => 'Curso para boton de convalidacion',
            'permite_convalidacion' => true,
        ]);
        $userId = $this->createUser(['habilitacion_minera' => ['ver', 'convalidar']]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $assignment->personal_id]))
            ->assertOk()
            ->assertSee('data-exam-action-toggle="convalidation"', false)
            ->assertSee('convalidationOptionsUrlTemplate', false)
            ->assertSee('can_convalidate', false);
    }

    public function test_convalidacion_rechaza_destino_inactivo_aunque_conserve_historial(): void
    {
        [$service, $actor, $originAssignment] = $this->assignmentWithExam([
            'nombre' => 'Curso con destino retirado',
            'permite_convalidacion' => true,
        ]);
        $origin = $originAssignment->examenes->firstOrFail();
        $service->registerAttempt($origin, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
        ], null, $actor);
        $targetMine = $this->createMine('Mina destino retirado');
        $requirement = $service->storeRequirement([
            'mina_id' => $targetMine->id,
            'examen_id' => $origin->examen_id,
            'permite_convalidacion_mina' => true,
            'convalidar_desde_otras_minas' => true,
        ]);
        $assignment = $service->assignMine([
            'personal_id' => $originAssignment->personal_id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $target = $assignment->examenes->firstOrFail();

        $requirement->forceFill(['activo' => false])->save();
        $this->assertFalse($service->convalidationOptions($target)['available']);
        try {
            $service->convalidateExam($target, $origin->id, [], $actor);
            $this->fail('No debe convalidar un requisito retirado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('convalidacion', $exception->errors());
        }

        $requirement->forceFill(['activo' => true])->save();
        $assignment->forceFill(['activo' => false])->save();
        $this->assertFalse($service->convalidationOptions($target)['available']);
    }

    public function test_archivo_de_intento_y_descarga_por_ruta(): void
    {
        Storage::fake('local');
        [$service, $actor, $assignment] = $this->assignmentWithExam();
        $exam = $assignment->examenes->first();
        $file = UploadedFile::fake()->create('resultado.pdf', 12, 'application/pdf');

        $service->registerAttempt($exam, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2026-06-06',
        ], $file, $actor);

        $attempt = PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $exam->id)->firstOrFail();
        Storage::disk('local')->assertExists($attempt->archivo_path);

        $userId = $this->createUser(['habilitacion_minera' => ['ver']]);
        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.attempt.download', $attempt->id))
            ->assertOk();
    }

    public function test_rutas_respetan_permiso_registrar_explicito_y_no_cambian_estado_laboral_ni_contrato(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['habilitacion_minera' => ['ver', 'registrar']]);
        [$service, $actor, $assignment] = $this->assignmentWithExam();
        $exam = $assignment->examenes->first();
        $contractId = $this->insertContract($assignment->personal, '2026-01-01', '2026-12-31', true)->id;

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $exam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $exam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
                'fecha_realizacion' => '2026-06-06',
            ])
            ->assertRedirect();

        $this->assertSame('ACTIVO', $assignment->personal->fresh()->estado);
        $this->assertSame(PersonalContrato::ESTADO_ACTIVO, PersonalContrato::query()->findOrFail($contractId)->estado);
    }

    public function test_no_se_agregan_nombres_propios_en_archivos_de_la_etapa(): void
    {
        $files = [
            app_path('Models/ExamenMinero.php'),
            app_path('Models/MinaRequisito.php'),
            app_path('Models/PersonalMinaExamen.php'),
            app_path('Models/PersonalMinaExamenIntento.php'),
            app_path('Modules/Personal/Services/PersonalMinaHabilitacionService.php'),
            app_path('Modules/Personal/Controllers/PersonalMinaHabilitacionController.php'),
            resource_path('views/personal/habilitacion-minera/index.blade.php'),
            database_path('migrations/2026_06_06_000200_create_mining_exam_flow_tables.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', $content, $file);
        }
    }

    private function context(): array
    {
        $service = app(PersonalMinaHabilitacionService::class);
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('ACTIVO');

        return [$service, $actor, $mine, $worker];
    }

    private function assignmentWithExam(array $examPayload = []): array
    {
        [$service, $actor, $mine, $worker] = $this->context();
        $exam = $this->createExam($examPayload);
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
            'critico' => $exam->critico,
            'reprogramable' => $exam->permite_reintento,
            'permite_convalidacion_mina' => $exam->permite_convalidacion,
            'convalidar_desde_otras_minas' => $exam->permite_convalidacion,
        ]);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        return [$service, $actor, $assignment];
    }

    private function createExam(array $overrides = []): ExamenMinero
    {
        $id = (string) Str::uuid();
        DB::table('examenes_mineros')->insert(array_merge([
            'id' => $id,
            'nombre' => ($overrides['nombre'] ?? 'Examen minero') . ' ' . Str::upper(Str::random(5)),
            'descripcion' => null,
            'tipo' => 'General',
            'lugar' => 'Sede',
            'precio' => 100,
            'moneda' => 'PEN',
            'tiene_vigencia' => $overrides['tiene_vigencia'] ?? false,
            'vigencia_dias' => $overrides['vigencia_dias'] ?? null,
            'permite_reintento' => $overrides['permite_reintento'] ?? true,
            'max_intentos' => $overrides['max_intentos'] ?? (($overrides['permite_reintento'] ?? true) ? 2 : 1),
            'critico' => $overrides['critico'] ?? false,
            'desaprueba_finaliza_proceso' => $overrides['desaprueba_finaliza_proceso'] ?? false,
            'requiere_nota' => $overrides['requiere_nota'] ?? false,
            'nota_minima' => $overrides['nota_minima'] ?? null,
            'solo_resultado' => !($overrides['requiere_nota'] ?? false),
            'permite_convalidacion' => $overrides['permite_convalidacion'] ?? false,
            'activo' => true,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], collect($overrides)->except('nombre')->all()));

        return ExamenMinero::query()->findOrFail($id);
    }

    private function createMine(string $name = 'Mina examenes'): Mina
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name . ' ' . Str::upper(Str::random(4)),
            'unidad_minera' => $name,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Mina::query()->findOrFail($id);
    }

    private function grantMineScope(Usuario $user, Mina $mine): void
    {
        DB::table('usuario_mina_scope')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'usuario_id' => $user->id,
            'mina_id' => $mine->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPersonal(string $estado): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(72000000, 72999999);
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'EXAMEN MINERO',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'examen@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $inicio, string $fin, bool $signed): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/' . Str::uuid() . '.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        $id = (string) Str::uuid();
        DB::table('personal_contratos')->insert([
            'id' => $id,
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'puesto' => $personal->puesto,
            'tipo_contrato' => 'FIJO',
            'activado_at' => $inicio . ' 08:00:00',
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-firmado.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PersonalContrato::query()->findOrFail($id);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_EXAMEN_' . Str::upper(Str::random(6)),
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
                'email' => 'examen@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
