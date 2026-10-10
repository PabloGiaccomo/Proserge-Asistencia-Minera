<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMineroCategoriaHistorial;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalEmoCitaHistorial;
use App\Models\PersonalEmoExportAudit;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningEmoSchemaTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    protected function connectionsToTransact(): array
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->assertSame('proserge_app_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        return [null];
    }

    public function test_schema_exposes_appointment_history_and_minimal_audits(): void
    {
        foreach ([
            'clinicas_emo' => ['id', 'nombre', 'nombre_normalizado', 'activo', 'created_by_usuario_id', 'updated_by_usuario_id', 'created_at', 'updated_at'],
            'personal_emo_citas' => ['id', 'personal_id', 'clinica_id', 'clinica_nombre_snapshot', 'fecha_programacion', 'fecha_realizacion', 'estado', 'revision', 'resultado_emo', 'detalle_resultado_emo', 'origen', 'usuario_programacion_id', 'usuario_resultado_id', 'usuario_actualizacion_id', 'created_at', 'updated_at'],
            'personal_emo_cita_historial' => ['id', 'emo_cita_id', 'evento', 'valores_anteriores_json', 'valores_nuevos_json', 'usuario_id', 'origen', 'fecha_cambio', 'created_at', 'updated_at'],
            'personal_emo_export_audits' => ['id', 'usuario_id', 'filtros_json', 'filtros_hash', 'cantidad_filas', 'exportado_at', 'created_at', 'updated_at'],
            'examen_minero_categoria_historial' => ['id', 'examen_id', 'categoria_anterior', 'categoria_nueva', 'usuario_id', 'motivo', 'fecha_cambio', 'created_at', 'updated_at'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), 'Missing table: '.$table);
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing($table));
        }

        $this->assertTrue(Schema::hasColumn('examenes_mineros', 'categoria_operativa'));
        foreach (['personal_mina_examen_intentos', 'personal_curso_registros'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'emo_cita_id'));
            $this->assertFalse(Schema::hasColumn($table, 'resultado_emo'));
            $this->assertFalse(Schema::hasColumn($table, 'detalle_resultado_emo'));
        }
        $this->assertTrue(Schema::hasIndex('personal_emo_citas', ['fecha_programacion', 'estado']));
        $this->assertTrue(Schema::hasIndex('personal_emo_citas', ['personal_id', 'fecha_programacion', 'estado']));
    }

    public function test_clinic_name_cannot_be_duplicated_even_when_inactive(): void
    {
        $this->assertTrue(class_exists(ClinicaEmo::class), 'Missing clinic model');
        $name = 'clinica '.Str::random(8);
        ClinicaEmo::query()->create(['nombre' => 'Clinica de prueba', 'nombre_normalizado' => $name]);

        $this->expectException(QueryException::class);
        ClinicaEmo::query()->create(['nombre' => 'Otra clinica', 'nombre_normalizado' => $name, 'activo' => false]);
    }

    public function test_appointment_revision_defaults_to_one_and_preserves_persisted_integer_updates(): void
    {
        $appointment = $this->appointment()->fresh();
        $this->assertSame(1, $appointment->revision);
        $appointment->update(['revision' => '2']);
        $this->assertSame(2, $appointment->fresh()->revision);
        $this->assertSame('integer', $appointment->getCasts()['revision']);

        $this->expectException(QueryException::class);
        $appointment->update(['revision' => null]);
    }

    public function test_daily_appointment_identity_cannot_be_bypassed_by_anulled_state(): void
    {
        $this->assertTrue(class_exists(PersonalEmoCita::class), 'Missing appointment model');
        $appointment = $this->appointment();
        $appointment->update(['estado' => 'ANULADA']);

        $this->expectException(QueryException::class);
        PersonalEmoCita::query()->create([
            'personal_id' => $appointment->personal_id, 'clinica_id' => $appointment->clinica_id,
            'clinica_nombre_snapshot' => 'Otra clinica', 'fecha_programacion' => '2026-10-24', 'origen' => 'MANUAL',
        ]);
    }

    public function test_appointment_relations_preserve_exact_outcome_snapshot_and_nullable_legacy_links(): void
    {
        $this->assertTrue(class_exists(PersonalEmoCita::class), 'Missing appointment model');
        $appointment = $this->appointment();
        $exam = $this->exam('EMO '.Str::random(8), ['categoria_operativa' => 'EMO']);
        $mine = $this->mine();
        $assignment = PersonalMina::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $appointment->personal_id,
            'mina_id' => $mine->id, 'estado_habilitacion' => 'EN_PROCESO', 'activo' => true,
        ]);
        $workerExam = PersonalMinaExamen::query()->create([
            'id' => (string) Str::uuid(), 'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id, 'nombre_snapshot' => $exam->nombre,
        ]);
        $attempt = PersonalMinaExamenIntento::query()->create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $workerExam->id,
            'numero_intento' => 1, 'emo_cita_id' => $appointment->id,
        ]);
        $legacy = PersonalMinaExamenIntento::query()->create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $workerExam->id,
            'numero_intento' => 2, 'observacion' => 'Historico conservado',
        ]);
        $course = PersonalCursoRegistro::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $appointment->personal_id,
            'examen_id' => $exam->id, 'resultado' => 'PENDIENTE', 'catalogo_snapshot' => [],
            'usuario_registro_id' => $this->actor()->id, 'origen' => 'MANUAL', 'emo_cita_id' => $appointment->id,
        ]);
        $history = PersonalEmoCitaHistorial::query()->create([
            'emo_cita_id' => $appointment->id, 'evento' => 'CREACION',
            'valores_nuevos_json' => ['estado' => 'PROGRAMADA'], 'origen' => 'MANUAL', 'fecha_cambio' => '2026-10-07 10:00:00',
        ]);
        $appointment->update([
            'estado' => 'COMPLETADA', 'fecha_realizacion' => '2026-10-24',
            'resultado_emo' => 'APTO_CON_OBSERVACION', 'detalle_resultado_emo' => 'Detalle conservado',
        ]);
        $appointment->clinica->update(['nombre' => 'Nombre nuevo']);
        $appointment->refresh();

        $this->assertTrue(Str::isUuid($appointment->id));
        $this->assertTrue(Str::isUuid($history->id));
        $this->assertSame('Clinica de prueba', $appointment->clinica_nombre_snapshot);
        $this->assertSame('2026-10-24', $appointment->fecha_programacion->format('Y-m-d'));
        $this->assertSame('2026-10-24', $appointment->fecha_realizacion->format('Y-m-d'));
        $this->assertSame('APTO_CON_OBSERVACION', $appointment->resultado_emo);
        $this->assertSame('Detalle conservado', $appointment->detalle_resultado_emo);
        $this->assertSame($appointment->personal_id, $appointment->personal->id);
        $this->assertSame([$attempt->id], $appointment->intentos->modelKeys());
        $this->assertSame([$course->id], $appointment->cursos->modelKeys());
        $this->assertSame([$history->id], $appointment->historial->modelKeys());
        $this->assertSame($appointment->id, $attempt->emoCita->id);
        $this->assertSame($appointment->id, $course->emoCita->id);
        $this->assertNull($legacy->fresh()->emo_cita_id);
        $this->assertNull($legacy->emoCita);
        $this->assertNull($history->fresh()->usuario_id);
        $this->assertNull($history->valores_anteriores_json);
        $this->assertSame(['estado' => 'PROGRAMADA'], $history->fresh()->valores_nuevos_json);
        $this->assertSame('2026-10-07 10:00:00', $history->fecha_cambio->format('Y-m-d H:i:s'));

        $this->expectException(QueryException::class);
        PersonalMinaExamenIntento::query()->create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $workerExam->id,
            'numero_intento' => 3, 'emo_cita_id' => $appointment->id,
        ]);
    }

    public function test_export_audit_stores_only_grouped_count_filters_actor_hash_and_timestamp(): void
    {
        $this->assertTrue(class_exists(PersonalEmoExportAudit::class), 'Missing export audit model');
        $actor = $this->actor();
        $filters = ['scheduled_from' => '2026-10-01', 'scheduled_to' => '2026-10-31'];
        $audit = PersonalEmoExportAudit::query()->create([
            'usuario_id' => $actor->id, 'filtros_json' => $filters,
            'filtros_hash' => str_repeat('a', 64), 'cantidad_filas' => 125, 'exportado_at' => '2026-10-07 10:00:00',
        ])->fresh();

        $this->assertTrue(Str::isUuid($audit->id));
        $this->assertSame($filters, $audit->filtros_json);
        $this->assertSame(125, $audit->cantidad_filas);
        $this->assertSame(str_repeat('a', 64), $audit->filtros_hash);
        $this->assertSame($actor->id, $audit->usuario->id);
        $this->assertSame('2026-10-07 10:00:00', $audit->exportado_at->format('Y-m-d H:i:s'));
        $this->assertFalse(Schema::hasColumn('personal_emo_export_audits', 'resultado_emo'));
        $this->assertFalse(Schema::hasColumn('personal_emo_export_audits', 'filas_json'));
    }

    public function test_category_history_keeps_previous_and_new_values_without_medical_data(): void
    {
        $this->assertTrue(class_exists(ExamenMineroCategoriaHistorial::class), 'Missing category history model');
        $exam = $this->exam('Catalogo '.Str::random(8), ['categoria_operativa' => 'CURSO']);
        $actor = $this->actor();
        $history = ExamenMineroCategoriaHistorial::query()->create([
            'examen_id' => $exam->id, 'categoria_anterior' => 'OTRO', 'categoria_nueva' => 'CURSO',
            'usuario_id' => $actor->id, 'motivo' => 'Clasificacion confirmada', 'fecha_cambio' => '2026-10-07 10:00:00',
        ])->fresh();

        $this->assertSame('CURSO', $exam->fresh()->categoria_operativa);
        $this->assertSame('OTRO', $history->categoria_anterior);
        $this->assertSame('CURSO', $history->categoria_nueva);
        $this->assertSame('Clasificacion confirmada', $history->motivo);
        $this->assertSame($actor->id, $history->usuario->id);
        $this->assertSame($exam->id, $history->examen->id);
        $this->assertSame([$history->id], $exam->categoriaHistorial->modelKeys());
        $this->assertSame('2026-10-07 10:00:00', $history->fecha_cambio->format('Y-m-d H:i:s'));
    }

    public function test_clean_additive_schema_initializes_existing_exams_as_otro_without_reinterpreting_history(): void
    {
        $this->assertMigrationVariant(false);
    }

    public function test_preexisting_columns_are_preserved_and_missing_indexes_and_foreign_keys_are_added_idempotently(): void
    {
        $this->assertMigrationVariant(true);
    }

    private function appointment(): PersonalEmoCita
    {
        $document = (string) random_int(74000000, 74999999);
        $worker = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
            'tipo_documento' => 'DNI', 'nombre_completo' => 'PRUEBA EMO', 'estado' => 'EN_PROCESO',
            'puesto' => 'Operario', 'ocupacion' => 'Operario', 'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid(),
        ]);
        $clinic = ClinicaEmo::query()->create(['nombre' => 'Clinica de prueba', 'nombre_normalizado' => 'clinica '.Str::random(8)]);

        return PersonalEmoCita::query()->create([
            'personal_id' => $worker->id, 'clinica_id' => $clinic->id,
            'clinica_nombre_snapshot' => $clinic->nombre, 'fecha_programacion' => '2026-10-24', 'origen' => 'MANUAL',
        ]);
    }

    private function assertMigrationVariant(bool $preexistingColumns): void
    {
        $path = database_path('migrations/2026_10_07_000100_create_emo_appointments_and_clinics.php');
        $this->assertFileExists($path);
        $primary = DB::getDefaultConnection();
        config(['database.connections.emo_schema_variant' => array_replace(
            config('database.connections.'.$primary), ['prefix' => 'emo_test_'.Str::lower(Str::random(8)).'_', 'prefix_indexes' => false],
        )]);
        DB::setDefaultConnection('emo_schema_variant');
        Schema::clearResolvedInstance('db.schema');
        $tables = ['personal_emo_cita_historial', 'examen_minero_categoria_historial', 'personal_emo_export_audits',
            'personal_mina_examen_intentos', 'personal_curso_registros', 'personal_emo_citas', 'clinicas_emo',
            'personal_mina_examenes', 'examenes_mineros', 'personal', 'usuarios'];

        try {
            $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
            $this->assertSame('proserge_app_test', DB::selectOne('SELECT DATABASE() AS name')->name);
            foreach (['personal', 'usuarios', 'personal_mina_examenes'] as $table) {
                Schema::create($table, fn (Blueprint $blueprint) => $blueprint->char('id', 36)->primary());
            }
            Schema::create('examenes_mineros', function (Blueprint $table) use ($preexistingColumns): void {
                $table->char('id', 36)->primary();
                $table->string('nombre');
                $table->string('tipo');
                $table->boolean('activo')->default(true);
                if ($preexistingColumns) {
                    $table->string('categoria_operativa', 10)->default('OTRO');
                }
            });
            foreach (['personal_mina_examen_intentos', 'personal_curso_registros'] as $name) {
                Schema::create($name, function (Blueprint $table) use ($name, $preexistingColumns): void {
                    $table->char('id', 36)->primary();
                    if ($name === 'personal_mina_examen_intentos') {
                        $table->char('personal_mina_examen_id', 36)->nullable();
                    }
                    $table->string('observacion')->nullable();
                    if ($preexistingColumns) {
                        $table->char('emo_cita_id', 36)->nullable();
                    }
                });
                DB::table($name)->insert(['id' => (string) Str::uuid(), 'observacion' => 'APTO CON RESTRICCION: conservar']);
            }
            $examId = (string) Str::uuid();
            DB::table('examenes_mineros')->insert(['id' => $examId, 'nombre' => 'EMO historico', 'tipo' => 'EMO']
                + ($preexistingColumns ? ['categoria_operativa' => 'CURSO'] : []));
            $migration = require $path;
            $migration->up();
            $foreignKeys = $this->foreignKeyCount('personal_mina_examen_intentos', 'emo_cita_id');
            $this->assertSame(1, $foreignKeys);
            $migration->up();
            $this->assertSame($foreignKeys, $this->foreignKeyCount('personal_mina_examen_intentos', 'emo_cita_id'));

            $this->assertSame($preexistingColumns ? 'CURSO' : 'OTRO', DB::table('examenes_mineros')->where('id', $examId)->value('categoria_operativa'));
            foreach (['personal_mina_examen_intentos', 'personal_curso_registros'] as $table) {
                $this->assertNull(DB::table($table)->value('emo_cita_id'));
                $this->assertSame('APTO CON RESTRICCION: conservar', DB::table($table)->value('observacion'));
                $this->assertSame(1, $this->foreignKeyCount($table, 'emo_cita_id'));
            }
            $this->assertTrue(Schema::hasIndex('personal_mina_examen_intentos', ['personal_mina_examen_id', 'emo_cita_id'], 'unique'));
            $this->assertSame(0, DB::table('personal_emo_citas')->count());
            $this->assertSame(0, DB::table('personal_emo_cita_historial')->count());
            $this->assertTrue(Schema::hasColumn('personal_emo_citas', 'revision'));
            $workerId = (string) Str::uuid();
            $clinicId = (string) Str::uuid();
            $appointmentId = (string) Str::uuid();
            DB::table('personal')->insert(['id' => $workerId]);
            DB::table('clinicas_emo')->insert([
                'id' => $clinicId, 'nombre' => 'Clinica historica', 'nombre_normalizado' => 'clinica historica',
            ]);
            if ($preexistingColumns) {
                // Only this test's ephemeral schema simulates the previously deployed migration.
                Schema::table('personal_emo_citas', fn (Blueprint $table) => $table->dropColumn('revision'));
            }
            DB::table('personal_emo_citas')->insert([
                'id' => $appointmentId, 'personal_id' => $workerId, 'clinica_id' => $clinicId,
                'clinica_nombre_snapshot' => 'Nombre conservado', 'fecha_programacion' => '2026-10-24',
                'origen' => 'MANUAL', 'resultado_emo' => 'APTO_CON_OBSERVACION',
                'detalle_resultado_emo' => 'Detalle sin cambios',
            ]);
            $migration->up();
            $this->assertSame(1, (int) DB::table('personal_emo_citas')->where('id', $appointmentId)->value('revision'));
            $repaired = DB::table('personal_emo_citas')->where('id', $appointmentId)->first();
            $this->assertSame('Nombre conservado', $repaired->clinica_nombre_snapshot);
            $this->assertSame('2026-10-24', $repaired->fecha_programacion);
            $this->assertSame('APTO_CON_OBSERVACION', $repaired->resultado_emo);
            $this->assertSame('Detalle sin cambios', $repaired->detalle_resultado_emo);
            $revisionColumn = DB::selectOne(
                'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS'
                .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB::connection()->getDatabaseName(), DB::connection()->getTablePrefix().'personal_emo_citas', 'revision'],
            );
            $this->assertStringContainsString('unsigned', $revisionColumn->COLUMN_TYPE);
            $this->assertSame('NO', $revisionColumn->IS_NULLABLE);
            $this->assertSame('1', (string) $revisionColumn->COLUMN_DEFAULT);
            DB::table('personal_emo_citas')->where('id', $appointmentId)->update(['revision' => 7]);
            $beforeRerun = DB::table('personal_emo_citas')->where('id', $appointmentId)->first();
            $migration->up();
            $this->assertEquals($beforeRerun, DB::table('personal_emo_citas')->where('id', $appointmentId)->first());
            $this->assertSame(7, (int) DB::table('personal_emo_citas')->where('id', $appointmentId)->value('revision'));
            $migration->down();
            $this->assertTrue(Schema::hasTable('personal_emo_citas'));
            $this->assertTrue(Schema::hasColumn('personal_mina_examen_intentos', 'emo_cita_id'));
            $this->assertSame('APTO CON RESTRICCION: conservar', DB::table('personal_mina_examen_intentos')->value('observacion'));
        } finally {
            Schema::disableForeignKeyConstraints();
            foreach ($tables as $table) {
                Schema::dropIfExists($table);
            }
            Schema::enableForeignKeyConstraints();
            DB::setDefaultConnection($primary);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('emo_schema_variant');
        }
    }

    private function foreignKeyCount(string $table, string $column): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            .' AND REFERENCED_TABLE_NAME = ?',
            [DB::connection()->getDatabaseName(), DB::connection()->getTablePrefix().$table,
                $column, DB::connection()->getTablePrefix().'personal_emo_citas'],
        )->total;
    }
}
