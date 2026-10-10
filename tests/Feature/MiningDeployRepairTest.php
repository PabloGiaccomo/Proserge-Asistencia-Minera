<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\EmoAppointmentService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningDeployRepairTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->assertSame('proserge_app_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 23:30:00', 'America/Lima'));
        $this->app->instance(OperationalNotificationService::class,
            \Mockery::mock(OperationalNotificationService::class)->shouldIgnoreMissing());
    }

    public function test_migration_classifies_only_exact_normalized_name_and_audits_once_without_actor(): void
    {
        $exact = $this->exam('  exámen   MÉDICO  ', ['categoria_operativa' => 'OTRO', 'activo' => false]);
        $similar = $this->exam('EXAMEN MEDICO OCUPACIONAL', ['categoria_operativa' => 'OTRO']);
        $explicit = $this->exam('EXAMEN MEDICO', ['categoria_operativa' => 'CURSO']);
        $this->migrateRepair();
        $this->migrateRepair();
        $this->assertSame('EMO', $exact->fresh()->categoria_operativa);
        $this->assertSame('OTRO', $similar->fresh()->categoria_operativa);
        $this->assertSame('CURSO', $explicit->fresh()->categoria_operativa);
        $history = $exact->categoriaHistorial()->sole();
        $this->assertNull($history->usuario_id);
        $this->assertSame('OTRO', $history->categoria_anterior);
        $this->assertSame('EMO', $history->categoria_nueva);
        $this->assertStringContainsString('SISTEMA', $history->motivo);
    }

    #[DataProvider('medicalCases')]
    public function test_migration_repairs_only_unambiguous_medical_outcomes_with_lima_validity(string $text, ?string $expiration, string $expected): void
    {
        $exam = $this->workerExam($this->exam('EXAMEN MEDICO', ['categoria_operativa' => 'OTRO']));
        $exam->update(['estado' => 'OBSERVADO', 'resultado' => 'APROBADO', 'fecha_vencimiento' => $expiration]);
        $attempt = $this->attempt($exam, 'APROBADO', ['observacion' => $text, 'fecha_realizacion' => '2026-10-01']);
        $this->migrateRepair();
        $this->assertSame($expected, $exam->fresh()->estado);
        $this->assertSame($text, $attempt->fresh()->observacion);
        if ($expected !== 'OBSERVADO') {
            $this->assertStringContainsString('APTO CON', $exam->fresh()->observacion);
            if (str_contains($text, ':')) {
                $this->assertStringContainsString('Usar lentes', $exam->fresh()->observacion);
            }
        }
    }

    public static function medicalCases(): array
    {
        return [
            ['APTO CON  RESTRICCIONES', null, 'APROBADO'],
            ['APTO CON OBSERVACIONES: Usar lentes', '2026-12-01', 'VIGENTE'],
            ['APTO CON RESTRICCIÓN: Usar lentes', '2026-10-10', 'POR_VENCER'],
            ['APTO CON OBSERVACION', '2026-10-09', 'VENCIDO'],
            ['Requiere control medico', null, 'OBSERVADO'],
            ['NO APTO', null, 'OBSERVADO'],
        ];
    }

    public function test_migration_restores_twelve_of_twelve_and_habilitation_with_idempotent_system_audit(): void
    {
        $catalog = $this->exam('EXAMEN MEDICO', ['categoria_operativa' => 'OTRO']);
        $exam = $this->workerExam($catalog);
        $assignment = $exam->asignacion;
        for ($i = 0; $i < 11; $i++) {
            $course = $this->exam('Curso prueba '.Str::uuid());
            $requirement = $this->requirement($assignment->mina, $course);
            PersonalMinaExamen::create(['id' => (string) Str::uuid(), 'personal_mina_id' => $assignment->id,
                'mina_requisito_id' => $requirement->id, 'examen_id' => $course->id,
                'nombre_snapshot' => $course->nombre, 'estado' => 'NO_APLICA', 'resultado' => 'NO_APLICA']);
        }
        $exam->update(['estado' => 'OBSERVADO', 'resultado' => 'APROBADO']);
        $this->attempt($exam, 'APROBADO', ['observacion' => 'APTO CON  RESTRICCIONES']);
        $mining = app(PersonalMinaHabilitacionService::class);
        $this->assertSame(11, $mining->assignmentExamSummary($assignment->fresh())['resueltos']);
        $this->migrateRepair();
        $this->assertSame(12, $mining->assignmentExamSummary($assignment->fresh())['resueltos']);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $count = DB::table('personal_mina_historial')->where('personal_mina_id', $assignment->id)->count();
        $this->migrateRepair();
        $this->assertSame($count, DB::table('personal_mina_historial')->where('personal_mina_id', $assignment->id)->count());
        $audit = DB::table('personal_mina_historial')->where('personal_mina_id', $assignment->id)->where('observacion', 'like', '%Reparacion EMO legacy%')->sole();
        $this->assertNull($audit->usuario_id);
        $this->assertStringContainsString('SISTEMA', $audit->observacion);
    }

    public function test_migration_annuls_legacy_pending_keeps_history_and_does_not_move_boundary_on_rerun(): void
    {
        $exam = $this->workerExam();
        $exam->update(['estado' => 'NO_APLICA', 'resultado' => 'NO_APLICA']);
        $attempt = $this->attempt($exam);
        $this->migrateRepair();
        $this->assertSame('ANULADO', $attempt->fresh()->resultado);
        $this->assertSame(1, $attempt->fresh()->numero_intento);
        $boundary = $exam->fresh()->getRawOriginal('intentos_ciclo_desde');
        $this->assertNotNull($boundary);
        $this->assertSame(2, $exam->fresh()->intentos_ciclo_numero_desde);
        $this->assertSame(0, app(PersonalMinaHabilitacionService::class)->operationalAttemptCount($exam->fresh()));
        $count = DB::table('personal_mina_historial')->where('personal_mina_id', $exam->personal_mina_id)->count();
        $this->travelTo(now()->addDays(2));
        $this->migrateRepair();
        $this->assertSame($boundary, $exam->fresh()->getRawOriginal('intentos_ciclo_desde'));
        $this->assertSame($count, DB::table('personal_mina_historial')->where('personal_mina_id', $exam->personal_mina_id)->count());
    }

    #[DataProvider('schedulingCases')]
    public function test_direct_scheduling_closes_legacy_pending_without_backfill(string $category, ?string $legacyDate): void
    {
        $actor = $this->actor();
        $exam = $this->workerExam($this->exam('Prueba '.Str::uuid(), ['categoria_operativa' => $category]));
        $exam->update(['estado' => 'NO_APLICA', 'resultado' => 'NO_APLICA']);
        $old = $this->attempt($exam, 'PENDIENTE', ['fecha_programacion' => $legacyDate]);
        $clinic = ClinicaEmo::create(['nombre' => 'Clinica prueba', 'nombre_normalizado' => (string) Str::uuid()]);
        app(PersonalMinaHabilitacionService::class)->registerAttempt($exam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-11', 'clinica_id' => $clinic->id,
        ], null, $actor);
        $this->assertSame('ANULADO', $old->fresh()->resultado);
        $this->assertSame([1, 2], $exam->intentos()->pluck('numero_intento')->all());
        $this->assertSame('PENDIENTE', $exam->intentos()->where('numero_intento', 2)->sole()->resultado);
        $this->assertSame('PROGRAMADO', $exam->fresh()->estado);
        $this->assertSame(0, app(PersonalMinaHabilitacionService::class)->operationalAttemptCount($exam->fresh()));
    }

    public static function schedulingCases(): array
    {
        return [['CURSO', null], ['CURSO', '2026-10-10'], ['EMO', null], ['EMO', '2026-10-10']];
    }

    public function test_shared_appointment_keeps_other_mine_when_reopening_legacy_no_aplica(): void
    {
        $actor = $this->actor();
        $catalog = $this->exam('EMO prueba '.Str::uuid(), ['categoria_operativa' => 'EMO']);
        $first = $this->workerExam($catalog);
        $second = $this->workerExam($catalog, $first->asignacion->personal);
        $clinic = ClinicaEmo::create(['nombre' => 'Clinica prueba', 'nombre_normalizado' => (string) Str::uuid()]);
        $data = new ScheduleEmoData(CarbonImmutable::parse('2026-10-11', 'America/Lima'), $clinic->id);
        $service = app(EmoAppointmentService::class);
        $appointment = $service->schedule($first, $data, $actor);
        $service->schedule($second, $data, $actor);
        $old = $first->intentos()->sole();
        $other = $second->intentos()->sole();
        $first->update(['estado' => 'NO_APLICA', 'resultado' => 'NO_APLICA']);
        $service->schedule($first, $data, $actor);
        $this->assertSame('ANULADO', $old->fresh()->resultado);
        $this->assertSame('PENDIENTE', $other->fresh()->resultado);
        $this->assertSame($appointment->id, $other->fresh()->emo_cita_id);
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame(2, $first->intentos()->count());
    }

    public function test_repair_skips_ambiguous_attempt(): void
    {
        $catalog = $this->exam('EXAMEN MEDICO', ['categoria_operativa' => 'EMO']);
        $ambiguous = $this->workerExam($catalog);
        $ambiguous->update(['estado' => 'OBSERVADO', 'resultado' => 'APROBADO']);
        $this->attempt($ambiguous, 'APROBADO', ['observacion' => 'APTO CON RESTRICCION']);
        $this->attempt($ambiguous, 'DESAPROBADO', ['numero_intento' => 2, 'observacion' => 'NO APTO']);
        $this->migrateRepair();
        $this->assertSame('OBSERVADO', $ambiguous->fresh()->estado);
    }

    public function test_repair_preserves_real_exam_detail_when_attempt_label_has_no_detail(): void
    {
        $catalog = $this->exam('EXAMEN MEDICO', ['categoria_operativa' => 'EMO']);
        $detail = $this->workerExam($catalog);
        $detail->update(['estado' => 'OBSERVADO', 'resultado' => 'APROBADO', 'observacion' => 'APTO CON RESTRICCIONES: Usar lentes']);
        $this->attempt($detail, 'APROBADO', ['observacion' => 'APTO CON RESTRICCION']);
        $this->migrateRepair();
        $this->assertStringContainsString('Usar lentes', $detail->fresh()->observacion);
    }

    public function test_historical_missing_tables_and_columns_are_tolerated_without_destructive_down(): void
    {
        $primary = DB::getDefaultConnection();
        config(['database.connections.deploy_variant' => array_replace(config('database.connections.'.$primary),
            ['prefix' => 'deploy_test_'.Str::lower(Str::random(8)).'_', 'prefix_indexes' => false])]);
        DB::setDefaultConnection('deploy_variant');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
            $this->migrateRepair();
            Schema::create('examenes_mineros', fn (Blueprint $table) => $table->string('id')->primary());
            DB::table('examenes_mineros')->insert(['id' => 'conservar']);
            $this->migrateRepair();
            $this->migration()->down();
            $this->assertSame('conservar', DB::table('examenes_mineros')->value('id'));
        } finally {
            Schema::dropIfExists('examenes_mineros');
            DB::setDefaultConnection($primary);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('deploy_variant');
        }
    }

    public function test_historical_missing_appointment_audit_does_not_partially_annul_linked_attempt(): void
    {
        $exam = $this->workerExam();
        $exam->update(['estado' => 'NO_APLICA', 'resultado' => 'NO_APLICA']);
        $attempt = $this->attempt($exam, 'PENDIENTE', ['emo_cita_id' => null]);
        $tables = ['personal', 'personal_mina', 'personal_mina_examenes', 'personal_mina_examen_intentos',
            'examenes_mineros', 'mina_requisitos', 'personal_mina_historial'];
        $rows = [];
        // Copy only this fictitious worker/exam; LIKE does not copy foreign keys.
        $rows['personal'] = DB::table('personal')->where('id', $exam->asignacion->personal_id)->first();
        $rows['personal_mina'] = DB::table('personal_mina')->where('id', $exam->personal_mina_id)->first();
        $rows['personal_mina_examenes'] = DB::table('personal_mina_examenes')->where('id', $exam->id)->first();
        $rows['personal_mina_examen_intentos'] = DB::table('personal_mina_examen_intentos')->where('id', $attempt->id)->first();
        $rows['examenes_mineros'] = DB::table('examenes_mineros')->where('id', $exam->examen_id)->first();
        $rows['mina_requisitos'] = DB::table('mina_requisitos')->where('id', $exam->mina_requisito_id)->first();
        $rows['personal_mina_historial'] = null;
        $rows['personal_mina_examen_intentos']->emo_cita_id = (string) Str::uuid();
        $primary = DB::getDefaultConnection();
        $prefix = 'deploy_test_'.Str::lower(Str::random(8)).'_';
        config(['database.connections.deploy_variant' => array_replace(config('database.connections.'.$primary),
            ['prefix' => $prefix, 'prefix_indexes' => false])]);
        DB::setDefaultConnection('deploy_variant');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
            foreach ($tables as $table) {
                DB::statement('CREATE TABLE `'.$prefix.$table.'` LIKE `'.$table.'`');
                if ($rows[$table]) {
                    DB::table($table)->insert((array) $rows[$table]);
                }
            }
            $this->migrateRepair();
            $this->assertSame('PENDIENTE', DB::table('personal_mina_examen_intentos')->where('id', $attempt->id)->value('resultado'));
            $this->assertNull(DB::table('personal_mina_examenes')->where('id', $exam->id)->value('intentos_ciclo_desde'));
            Schema::create('personal_emo_citas', fn (Blueprint $table) => $table->char('id', 36)->primary());
            $this->migrateRepair();
            $this->assertSame('PENDIENTE', DB::table('personal_mina_examen_intentos')->where('id', $attempt->id)->value('resultado'));
        } finally {
            Schema::dropIfExists('personal_emo_citas');
            foreach (array_reverse($tables) as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($primary);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('deploy_variant');
        }
    }

    private function migration(): object
    {
        $path = database_path('migrations/2026_10_10_000200_repair_legacy_mining_emo_and_not_applicable.php');
        $this->assertFileExists($path, 'La reparacion debe viajar en migrate --force.');

        return require $path;
    }

    private function migrateRepair(): void
    {
        $this->migration()->up();
    }

    private function workerExam(?ExamenMinero $catalog = null, ?Personal $worker = null): PersonalMinaExamen
    {
        $catalog ??= $this->exam('Curso prueba '.Str::uuid(), ['categoria_operativa' => 'CURSO']);
        $document = (string) random_int(76000000, 76999999);
        $worker ??= Personal::create(['id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
            'tipo_documento' => 'DNI', 'nombre_completo' => 'PRUEBA REPARACION', 'puesto' => 'Operario', 'ocupacion' => 'Operario',
            'estado' => 'EN_PROCESO', 'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid()]);
        $mine = $this->mine();
        $this->requirement($mine, $catalog);

        return app(PersonalMinaHabilitacionService::class)->assignMine([
            'personal_id' => $worker->id, 'mina_id' => $mine->id,
        ], $this->actor(), false)->examenes->sole();
    }

    private function attempt(PersonalMinaExamen $exam, string $result = 'PENDIENTE', array $extra = []): PersonalMinaExamenIntento
    {
        return PersonalMinaExamenIntento::create($extra + ['id' => (string) Str::uuid(),
            'personal_mina_examen_id' => $exam->id, 'numero_intento' => 1, 'resultado' => $result]);
    }
}
