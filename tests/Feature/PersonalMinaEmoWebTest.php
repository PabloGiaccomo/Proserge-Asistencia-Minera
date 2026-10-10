<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWebPermission;
use App\Models\ClinicaEmo;
use App\Models\Personal;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\EmoAppointmentService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduleEmoData;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class PersonalMinaEmoWebTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'America/Lima'));
    }

    public static function protectedActions(): array
    {
        $actions = [
            'clinics.store' => ['clinicas-emo', 'configurar', ['nombre' => 'Nueva clinica']],
            'clinics.update' => ['clinicas-emo/ID', 'configurar', ['nombre' => 'Nuevo nombre']],
            'clinics.deactivate' => ['clinicas-emo/ID/desactivar', 'configurar', []],
            'exams.category' => ['examenes/ID/categoria-operativa', 'configurar', ['categoria_operativa' => 'EMO', 'motivo' => 'Clasificar']],
            'emo.schedule' => ['examenes-trabajador/ID/programar-emo', 'programar', ['clinica_id' => 'CLINIC', 'fecha_programacion' => '2026-10-07']],
            'emo.complete' => ['emo-citas/ID/completar', 'registrar', ['revision' => 1, 'resultado_emo' => 'APTO', 'fecha_realizacion' => '2026-10-07']],
            'emo.cancel' => ['emo-citas/ID/anular', 'programar', ['revision' => 1, 'motivo' => 'Cambio']],
            'emo.reprogram' => ['emo-citas/ID/reprogramar', 'programar', ['revision' => 1, 'clinica_id' => 'CLINIC', 'fecha_programacion' => '2026-10-08', 'motivo' => 'Cambio']],
            'emo.regularize' => ['intentos/ID/regularizar-clinica', 'programar', ['clinica_id' => 'CLINIC']],
        ];
        $cases = [];
        foreach ($actions as $name => [$path, $permission, $data]) {
            foreach ([false, true] as $withoutMiddleware) {
                $cases[$name.($withoutMiddleware ? ' backend' : ' middleware')] = [$path, $permission, $data, $withoutMiddleware];
            }
        }

        return $cases;
    }

    public function test_worker_exam_context_reads_appointments_in_a_single_batch(): void
    {
        $actor = $this->permissionActor(['ver', 'programar', 'registrar']);
        $exam = $this->workerExam($actor);
        $clinic = $this->clinicFixture();
        foreach (['2026-10-06', '2026-10-07'] as $date) {
            $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
                CarbonImmutable::parse($date, 'America/Lima'), $clinic->id,
            ), $actor);
            app(EmoAppointmentService::class)->complete($appointment, new CompleteEmoData(
                CarbonImmutable::parse($date, 'America/Lima'), EmoOutcome::APTO,
            ), $actor);
        }
        $worker = $exam->asignacion->personal;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $assignments = app(PersonalMinaHabilitacionService::class)->listAssignments([
                'trabajador' => $worker->dni,
            ]);
            $attempts = $assignments->getCollection()->sole()->examenes->sole()->intentos;
            $this->assertCount(2, $attempts);
            foreach ($attempts as $attempt) {
                $this->assertSame($clinic->id, $attempt->emoCita->clinica_id);
            }
            $appointmentQueries = collect(DB::getQueryLog())->filter(
                fn ($query) => str_contains($query['query'], 'from `personal_emo_citas`'),
            );
            $this->assertCount(1, $appointmentQueries, 'El contexto del modal debe cargar citas por lote, sin una consulta por intento.');
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('protectedActions')]
    public function test_each_action_rejects_its_missing_permission_even_without_permission_middleware(string $path, string $permission, array $data, bool $withoutMiddleware): void
    {
        if ($withoutMiddleware) {
            $this->withoutMiddleware(EnsureWebPermission::class);
        }
        $clinic = $this->clinicFixture();
        $data = array_map(fn ($value) => $value === 'CLINIC' ? $clinic->id : $value, $data);
        $user = $this->permissionActor(array_values(array_diff(['ver', 'editar', 'configurar', 'programar', 'registrar', 'ver_programados'], [$permission])));
        $this->withSession($this->catalogSession($user))
            ->postJson($this->url(str_replace('ID', (string) Str::uuid(), $path)), $data)->assertForbidden();
    }

    public function test_anonymous_clinic_write_redirects_to_login(): void
    {
        $this->post($this->url('clinicas-emo'), ['nombre' => 'Nueva'])->assertRedirect(route('login'));
    }

    public static function formRequestPermissionCases(): array
    {
        return [
            'clinic request' => ['clinicas-emo', []],
            'category request' => ['examenes/ID/categoria-operativa', []],
            'schedule request' => ['examenes-trabajador/ID/programar-emo', []],
            'schedule request regularization route' => ['intentos/ID/regularizar-clinica', []],
            'complete request' => ['emo-citas/ID/completar', []],
            'reprogram request cancel route' => ['emo-citas/ID/anular', []],
            'reprogram request reprogram route' => ['emo-citas/ID/reprogramar', []],
        ];
    }

    #[DataProvider('formRequestPermissionCases')]
    public function test_form_requests_authorize_before_validating_invalid_payload(string $path, array $invalidPayload): void
    {
        $this->withoutMiddleware(EnsureWebPermission::class);
        $actor = $this->permissionActor([]);

        $this->withSession($this->catalogSession($actor))
            ->postJson($this->url(str_replace('ID', (string) Str::uuid(), $path)), $invalidPayload)
            ->assertForbidden();
    }

    public function test_clinic_lifecycle_preserves_snapshot_and_actor_and_rejects_normalized_duplicates(): void
    {
        $actor = $this->permissionActor(['configurar']);
        $name = 'Clínica '.Str::random(8);
        $this->withSession($this->catalogSession($actor))->post($this->url('clinicas-emo'), ['nombre' => '  '.$name.'  '])->assertRedirect();
        $clinic = ClinicaEmo::where('nombre', $name)->sole();
        $this->assertTrue($clinic->activo);
        $this->assertSame($actor->id, $clinic->created_by_usuario_id);
        $this->withSession($this->catalogSession($actor))->postJson($this->url('clinicas-emo'), ['nombre' => Str::ascii(mb_strtoupper($name))])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $appointment = $this->appointment($actor, $clinic);
        $this->post($this->url('clinicas-emo/'.$clinic->id), ['nombre' => 'Renombrada '.Str::random(6), 'activo' => false])->assertRedirect();
        $this->assertTrue($clinic->fresh()->activo, 'Renombrar no debe desactivar implicitamente.');
        $this->post($this->url('clinicas-emo/'.$clinic->id.'/desactivar'))->assertRedirect();
        $this->assertFalse($clinic->fresh()->activo);
        $this->assertSame($actor->id, $clinic->fresh()->updated_by_usuario_id);
        $this->assertSame($name, $appointment->fresh()->clinica_nombre_snapshot);
    }

    public function test_renaming_clinic_to_an_existing_normalized_name_is_rejected(): void
    {
        $actor = $this->permissionActor(['configurar']);
        $first = $this->clinicFixture();
        $second = $this->clinicFixture();
        $this->withSession($this->catalogSession($actor))->postJson($this->url('clinicas-emo/'.$second->id), ['nombre' => mb_strtolower($first->nombre)])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->assertSame($second->nombre, $second->fresh()->nombre);
    }

    public function test_category_action_requires_configure_while_regular_edit_cannot_change_category(): void
    {
        $editor = $this->permissionActor(['editar']);
        $exam = $this->exam('Examen '.Str::uuid(), ['categoria_operativa' => 'OTRO']);
        $this->withSession($this->catalogSession($editor))->postJson($this->url('examenes/'.$exam->id.'/categoria-operativa'), ['categoria_operativa' => 'EMO', 'motivo' => 'Inventario'])->assertForbidden();
        $this->post($this->url('examenes/'.$exam->id), ['nombre' => $exam->nombre, 'tipo' => 'General', 'max_intentos' => 2, 'categoria_operativa' => 'EMO'])->assertRedirect();
        $this->assertSame('OTRO', $exam->fresh()->categoria_operativa);
        $this->assertSame(0, $exam->categoriaHistorial()->count());
    }

    public function test_category_action_stores_previous_new_reason_and_actor(): void
    {
        $actor = $this->permissionActor(['configurar']);
        $exam = $this->exam('Inventario '.Str::uuid(), ['categoria_operativa' => 'OTRO']);
        foreach (['EMO', 'CURSO', 'OTRO'] as $category) {
            $previous = $exam->fresh()->categoria_operativa;
            $this->withSession($this->catalogSession($actor))->post($this->url('examenes/'.$exam->id.'/categoria-operativa'), ['categoria_operativa' => $category, 'motivo' => 'Inventario confirmado'])->assertRedirect();
            $this->assertSame($category, $exam->fresh()->categoria_operativa);
            $this->assertDatabaseHas('examen_minero_categoria_historial', ['examen_id' => $exam->id, 'categoria_anterior' => $previous, 'categoria_nueva' => $category, 'motivo' => 'Inventario confirmado', 'usuario_id' => $actor->id]);
        }
        $this->assertSame(3, $exam->categoriaHistorial()->count());
    }

    public static function invalidCategories(): array
    {
        return [[[], ['categoria_operativa', 'motivo']], [['categoria_operativa' => 'MEDICO', 'motivo' => 'Inventario'], ['categoria_operativa']], [['categoria_operativa' => 'EMO', 'motivo' => '   '], ['motivo']]];
    }

    #[DataProvider('invalidCategories')]
    public function test_category_requires_valid_value_and_reason(array $data, array $errors): void
    {
        $actor = $this->permissionActor(['configurar']);
        $exam = $this->exam('Inventario '.Str::uuid(), ['categoria_operativa' => 'OTRO']);
        $this->withSession($this->catalogSession($actor))->postJson($this->url('examenes/'.$exam->id.'/categoria-operativa'), $data)->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame('OTRO', $exam->fresh()->categoria_operativa);
        $this->assertSame(0, $exam->categoriaHistorial()->count());
    }

    public function test_manual_schedule_is_idempotent_and_invalid_clinic_or_date_leaves_no_writes(): void
    {
        $actor = $this->permissionActor(['programar']);
        $exam = $this->workerExam($actor);
        $clinic = $this->clinicFixture();
        $inactive = $this->clinicFixture(false);
        $this->withSession($this->catalogSession($actor));
        foreach ([['clinica_id' => $inactive->id, 'fecha_programacion' => '2026-10-07'], ['clinica_id' => $clinic->id, 'fecha_programacion' => '2026-02-30'], ['fecha_programacion' => '2026-10-07']] as $data) {
            $this->postJson($this->url('examenes-trabajador/'.$exam->id.'/programar-emo'), $data)->assertUnprocessable();
        }
        $this->assertSame(0, $exam->intentos()->count());
        $url = $this->url('examenes-trabajador/'.$exam->id.'/programar-emo').'?vista=scheduled&scheduled_from=2026-10-01&scheduled_limit=50';
        $data = ['clinica_id' => $clinic->id, 'fecha_programacion' => '2026-10-07'];
        $this->post($url, $data)->assertRedirect(route('personal.habilitacion-minera.index', ['vista' => 'scheduled', 'scheduled_from' => '2026-10-01', 'scheduled_limit' => '50']));
        $this->post($url, $data)->assertRedirect();
        $this->assertSame(1, $exam->intentos()->count());
        $this->assertSame('MANUAL', $exam->intentos()->sole()->emoCita->origen);
    }

    public function test_complete_requires_detail_and_dates_and_rejects_duplicate_submission(): void
    {
        $actor = $this->permissionActor(['registrar']);
        $appointment = $this->appointment($actor);
        $url = $this->url('emo-citas/'.$appointment->id.'/completar');
        $this->withSession($this->catalogSession($actor));
        $data = ['revision' => $appointment->revision, 'fecha_realizacion' => '2026-10-07', 'resultado_emo' => 'APTO_CON_RESTRICCION'];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('detalle_resultado_emo');
        $this->postJson($url, array_replace($data, ['resultado_emo' => 'APTO', 'fecha_realizacion' => '2026-02-30']))->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $this->postJson($url, array_replace($data, ['resultado_emo' => 'APTO', 'fecha_realizacion' => '2026-10-08']))->assertUnprocessable()->assertJsonValidationErrors('fecha_realizacion');
        $data['detalle_resultado_emo'] = 'Usar lentes';
        $this->post($url, $data)->assertRedirect();
        $this->assertSame('APTO_CON_RESTRICCION', $appointment->fresh()->resultado_emo);
        $this->assertSame('Usar lentes', $appointment->fresh()->detalle_resultado_emo);
        $this->assertSame($actor->id, $appointment->fresh()->usuario_resultado_id);
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame(1, $appointment->historial()->where('evento', 'COMPLETADA')->count());
    }

    public function test_complete_rejects_disallowed_evidence_type_without_writing_file_or_result(): void
    {
        Storage::fake('local');
        $actor = $this->permissionActor(['registrar']);
        $appointment = $this->appointment($actor);

        $this->withSession($this->catalogSession($actor))->postJson(
            $this->url('emo-citas/'.$appointment->id.'/completar'),
            [
                'revision' => $appointment->revision,
                'fecha_realizacion' => '2026-10-07',
                'resultado_emo' => 'APTO',
                'archivo' => UploadedFile::fake()->create('evidencia.exe', 5, 'application/x-msdownload'),
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('archivo');

        $this->assertSame(PersonalEmoCita::ESTADO_PROGRAMADA, $appointment->fresh()->estado);
        $this->assertNull($appointment->fresh()->resultado_emo);
        $this->assertTrue($appointment->intentos()->get()->every(
            fn (PersonalMinaExamenIntento $attempt): bool => $attempt->resultado === 'PENDIENTE' && $attempt->archivo_path === null,
        ));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function legacyEmoCompletionCases(): array
    {
        return [
            'stale cancelled and reactivated appointment' => [true, false, false],
            'linked appointment after category change with forbidden upload' => [true, true, true],
            'unlinked category EMO with forbidden upload' => [false, false, true],
        ];
    }

    #[DataProvider('legacyEmoCompletionCases')]
    public function test_legacy_completion_rejects_emo_without_changing_state_audit_or_files(bool $linked, bool $reclassify, bool $upload): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('existing-evidence.txt', 'Preservar evidencia');
        $actor = $this->permissionActor(['registrar', 'programar']);
        $exam = $this->workerExam($actor);
        $this->withSession($this->catalogSession($actor));
        $appointment = null;
        if ($linked) {
            $clinic = $this->clinicFixture();
            $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
                CarbonImmutable::parse('2026-10-07', 'America/Lima'), $clinic->id,
            ), $actor);
            $observedRevision = $appointment->revision;
            $this->post($this->url('emo-citas/'.$appointment->id.'/anular'), ['revision' => $observedRevision, 'motivo' => 'Cambio operativo'])->assertRedirect();
            $this->post($this->url('emo-citas/'.$appointment->id.'/reprogramar'), [
                'revision' => $appointment->fresh()->revision, 'motivo' => 'Retomar cita',
                'clinica_id' => $clinic->id, 'fecha_programacion' => '2026-10-07',
            ])->assertRedirect();
            $this->assertGreaterThan($observedRevision, $appointment->fresh()->revision);
            $attempt = $appointment->intentos()->sole();
            if ($reclassify) {
                $exam->examen->update(['categoria_operativa' => 'CURSO']);
            }
        } else {
            $attempt = PersonalMinaExamenIntento::create([
                'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
                'numero_intento' => 1, 'fecha_programacion' => '2026-10-07',
                'resultado' => 'PENDIENTE', 'usuario_registro_id' => $actor->id,
            ]);
        }
        $before = [
            'appointment' => $appointment?->fresh()->getAttributes(),
            'attempt' => $attempt->fresh()->getAttributes(),
            'exam' => $exam->fresh()->getAttributes(),
            'assignment' => $exam->asignacion->fresh()->getAttributes(),
            'appointmentAudit' => DB::table('personal_emo_cita_historial')->count(),
            'miningAudit' => DB::table('personal_mina_historial')->count(),
        ];
        $payload = ['fecha_realizacion' => '2026-10-07', 'resultado' => 'APTO'];
        if ($upload) {
            $payload['archivo'] = UploadedFile::fake()->create('evidencia.exe', 5, 'application/x-msdownload');
        }

        $this->postJson($this->url('intentos/'.$attempt->id.'/completar'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('cita');

        $this->assertSame($before, [
            'appointment' => $appointment?->fresh()->getAttributes(),
            'attempt' => $attempt->fresh()->getAttributes(),
            'exam' => $exam->fresh()->getAttributes(),
            'assignment' => $exam->asignacion->fresh()->getAttributes(),
            'appointmentAudit' => DB::table('personal_emo_cita_historial')->count(),
            'miningAudit' => DB::table('personal_mina_historial')->count(),
        ]);
        $this->assertSame(['existing-evidence.txt'], Storage::disk('local')->allFiles());
        $this->assertSame('Preservar evidencia', Storage::disk('local')->get('existing-evidence.txt'));
    }

    public function test_legacy_http_completion_still_completes_a_scheduled_course(): void
    {
        $actor = $this->permissionActor(['registrar']);
        $exam = $this->workerExam($actor);
        $exam->examen->update(['categoria_operativa' => 'CURSO']);
        app(PersonalMinaHabilitacionService::class)->registerAttempt($exam, [
            'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
        ], null, $actor);
        $attempt = $exam->intentos()->sole();

        $this->withSession($this->catalogSession($actor))->post($this->url('intentos/'.$attempt->id.'/completar'), [
            'fecha_realizacion' => '2026-10-07', 'resultado' => 'APROBADO',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('APROBADO', $attempt->fresh()->resultado);
        $this->assertSame('2026-10-07', $attempt->fresh()->fecha_realizacion->toDateString());
    }

    public function test_cancel_and_reprogram_require_revision_and_reason_and_reject_stale_form(): void
    {
        $actor = $this->permissionActor(['programar', 'ver', 'ver_programados']);
        $appointment = $this->appointment($actor);
        $this->withSession($this->catalogSession($actor));
        $cancel = $this->url('emo-citas/'.$appointment->id.'/anular');
        $reprogram = $this->url('emo-citas/'.$appointment->id.'/reprogramar');
        $this->postJson($cancel, [])->assertUnprocessable()->assertJsonValidationErrors(['revision', 'motivo']);
        $oldRevision = $appointment->revision;
        $this->post($cancel, ['revision' => $oldRevision, 'motivo' => 'Cambio de disponibilidad'])->assertRedirect();
        $this->assertSame('ANULADA', $appointment->fresh()->estado);
        $this->assertSame($oldRevision + 1, $appointment->fresh()->revision);
        $controlUrl = $this->url('').'?vista=scheduled&scheduled_worker='.$appointment->personal->dni;
        $this->get($controlUrl)->assertOk()
            ->assertSee('data-emo-action="reprogram"', false)
            ->assertSee('data-emo-revision="'.($oldRevision + 1).'"', false)
            ->assertDontSee('data-emo-action="cancel"', false);
        $data = ['revision' => $appointment->fresh()->revision, 'motivo' => 'Nuevo dia', 'clinica_id' => $appointment->clinica_id, 'fecha_programacion' => '2026-10-07'];
        $this->postJson($reprogram, array_diff_key($data, ['revision' => true, 'motivo' => true]))->assertUnprocessable()->assertJsonValidationErrors(['revision', 'motivo']);
        $this->post($reprogram, $data)->assertRedirect();
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame($oldRevision + 2, $appointment->fresh()->revision);
        $this->get($controlUrl)->assertOk()
            ->assertSee('data-emo-action="cancel"', false)
            ->assertSee('data-emo-revision="'.($oldRevision + 2).'"', false)
            ->assertDontSee('data-emo-action="reprogram"', false);
        $this->postJson($cancel, ['revision' => $oldRevision, 'motivo' => 'Formulario anterior'])->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->postJson($reprogram, $data)->assertUnprocessable()->assertJsonValidationErrors('cita');
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
    }

    public function test_regularization_links_legacy_without_overwriting_attempt(): void
    {
        $actor = $this->permissionActor(['programar']);
        $exam = $this->workerExam($actor);
        $attempt = PersonalMinaExamenIntento::create(['id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id, 'numero_intento' => 1, 'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE', 'observacion' => 'Legado', 'usuario_registro_id' => $actor->id]);
        $clinic = $this->clinicFixture();
        $this->withSession($this->catalogSession($actor))->post($this->url('intentos/'.$attempt->id.'/regularizar-clinica'), ['clinica_id' => $clinic->id])->assertRedirect();
        $this->assertSame('Legado', $attempt->fresh()->observacion);
        $this->assertSame('PENDIENTE', $attempt->fresh()->resultado);
        $this->assertSame('REGULARIZACION', $attempt->fresh()->emoCita->origen);
    }

    public function test_control_requires_view_scheduled_even_when_permission_middleware_is_disabled(): void
    {
        $this->withoutMiddleware(EnsureWebPermission::class);
        $actor = $this->permissionActor(['ver']);
        $this->withSession($this->catalogSession($actor))->getJson($this->url('').'?vista=scheduled')->assertForbidden();
    }

    public function test_invalid_form_redirect_keeps_filters_old_input_and_modal_context(): void
    {
        $actor = $this->permissionActor(['programar']);
        $url = $this->url('examenes-trabajador/'.Str::uuid().'/programar-emo').'?vista=scheduled&scheduled_worker=Prueba';
        $this->withSession($this->catalogSession($actor))->post($url, ['fecha_programacion' => 'invalid'])->assertRedirect(route('personal.habilitacion-minera.index', ['vista' => 'scheduled', 'scheduled_worker' => 'Prueba']))->assertSessionHasErrors(['fecha_programacion', 'clinica_id'])->assertSessionHas('habilitacion_mina_emo_modal', 'schedule')->assertSessionHasInput('fecha_programacion', 'invalid');
    }

    public function test_control_route_requires_scheduled_permission_with_and_without_middleware(): void
    {
        $actor = $this->permissionActor(['ver']);
        $this->withSession($this->catalogSession($actor))->getJson($this->url('control-asistencia'))->assertForbidden();
        $this->withoutMiddleware(EnsureWebPermission::class);
        $this->withSession($this->catalogSession($actor))->getJson($this->url('control-asistencia'))->assertForbidden();
    }

    public function test_dedicated_control_route_accepts_direct_scheduled_permission_without_general_view(): void
    {
        $actor = $this->permissionActor(['ver_programados']);

        $this->withSession($this->catalogSession($actor))
            ->get($this->url('control-asistencia'))
            ->assertOk()
            ->assertViewHas('scheduledControl');
    }

    public function test_control_uses_sql_paginator_limit_dto_and_options_without_calling_legacy_query(): void
    {
        $actor = $this->permissionActor(['ver_programados']);
        $appointment = $this->appointment($actor);
        $mining = \Mockery::mock(PersonalMinaHabilitacionService::class, [app(OperationalNotificationService::class)])->makePartial();
        $mining->shouldNotReceive('scheduledExamAttempts');
        $this->app->instance(PersonalMinaHabilitacionService::class, $mining);
        $response = $this->withSession($this->catalogSession($actor))->get($this->url('control-asistencia').'?scheduled_limit=50&scheduled_from=2026-10-07&scheduled_to=2026-10-07&scheduled_worker='.$appointment->personal->dni);
        $response->assertOk()->assertViewHas('scheduledControl', function ($page) use ($appointment): bool {
            return $page instanceof LengthAwarePaginator
                && $page->perPage() === 50 && $page->total() === 1
                && $page->items()[0]->rowKey === $appointment->id;
        })->assertViewHas('scheduledControlFilters', fn ($filters) => $filters->limit === 50)
            ->assertViewHas('scheduledControlOptions', fn ($options) => isset($options['clinics'], $options['attendanceStates']))
            ->assertViewHas('scheduledExams', fn ($rows) => $rows->isEmpty());
    }

    public function test_service_validation_error_preserves_form_context_and_existing_appointment(): void
    {
        $actor = $this->permissionActor(['programar']);
        $exam = $this->workerExam($actor);
        $first = $this->clinicFixture();
        $second = $this->clinicFixture();
        $this->withSession($this->catalogSession($actor))->post($this->url('examenes-trabajador/'.$exam->id.'/programar-emo'), ['clinica_id' => $first->id, 'fecha_programacion' => '2026-10-07'])->assertRedirect();
        $url = $this->url('examenes-trabajador/'.$exam->id.'/programar-emo').'?vista=scheduled&scheduled_limit=50';
        $this->post($url, ['clinica_id' => $second->id, 'fecha_programacion' => '2026-10-07'])->assertRedirect(route('personal.habilitacion-minera.index', ['vista' => 'scheduled', 'scheduled_limit' => '50']))
            ->assertSessionHasErrors('clinica_id')->assertSessionHas('habilitacion_mina_emo_modal', 'schedule')->assertSessionHasInput('clinica_id', $second->id);
        $this->assertSame(1, $exam->intentos()->count());
        $this->assertSame($first->id, $exam->intentos()->sole()->emoCita->clinica_id);
    }

    private function permissionActor(array $permissions): Usuario
    {
        $actor = $this->actor(false);
        $actor->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['habilitacion_minera' => $permissions])]);

        return $actor;
    }

    private function clinicFixture(bool $active = true): ClinicaEmo
    {
        $name = 'Clinica '.Str::random(12);

        return ClinicaEmo::create(['nombre' => $name, 'nombre_normalizado' => mb_strtoupper($name), 'activo' => $active]);
    }

    private function workerExam(Usuario $actor): PersonalMinaExamen
    {
        $document = (string) random_int(75000000, 75999999);
        $worker = Personal::create(['id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document, 'tipo_documento' => 'DNI', 'nombre_completo' => 'Prueba EMO '.Str::random(8), 'puesto' => 'Operario', 'ocupacion' => 'Operario', 'estado' => 'ACTIVO', 'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid(), 'es_supervisor' => false]);
        $catalog = $this->exam('EMO '.Str::uuid(), ['categoria_operativa' => 'EMO']);
        $mine = $this->mine();
        $this->requirement($mine, $catalog);

        return app(PersonalMinaHabilitacionService::class)->assignMine(['personal_id' => $worker->id, 'mina_id' => $mine->id], $actor, false)->examenes->sole();
    }

    private function appointment(Usuario $actor, ?ClinicaEmo $clinic = null): PersonalEmoCita
    {
        $clinic ??= $this->clinicFixture();

        return app(EmoAppointmentService::class)->schedule($this->workerExam($actor), new ScheduleEmoData(CarbonImmutable::parse('2026-10-07', 'America/Lima'), $clinic->id), $actor);
    }

    private function url(string $path): string
    {
        return '/personal/habilitacion-minera'.($path !== '' ? '/'.$path : '');
    }
}
