<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RrhhIncrementalSchemaTest extends TestCase
{
    private const MIGRATIONS = [
        '2026_11_04_000100_create_personal_archivo_versiones.php',
        '2026_11_04_000200_add_update_purpose_to_personal_ingresos.php',
        '2026_11_04_000300_create_personal_ingreso_links_table.php',
        '2026_11_04_000400_create_personal_ingreso_review_batches.php',
        '2026_11_04_000600_create_signed_contract_upload_batches.php',
        '2026_11_04_000800_create_grupo_trabajo_detalle_movimientos.php',
        '2026_11_04_000900_add_revision_to_evaluaciones_de_asistencia.php',
        '2026_11_04_001000_create_rol_cambio_eventos.php',
        '2026_11_04_001100_create_personal_bloqueo_eventos.php',
    ];

    #[DataProvider('legacyIdentifierTypes')]
    public function test_migrations_preserve_legacy_rows_and_accept_existing_identifier_types(string $identifierType): void
    {
        $this->withIsolatedSchema(function () use ($identifierType): void {
            $this->legacySchema($identifierType);
            $person = (string) Str::uuid();
            $user = (string) Str::uuid();
            $ingreso = (string) Str::uuid();
            DB::connection('rrhh_schema_variant')->table('personal')->insert(['id' => $person, 'nombre_completo' => 'Persona de prueba', 'estado' => 'CESADO']);
            DB::connection('rrhh_schema_variant')->table('usuarios')->insert(['id' => $user]);
            DB::connection('rrhh_schema_variant')->table('personal_ingresos')->insert([
                'id' => $ingreso, 'estado' => 'FICHA_RECIBIDA', 'datos_json' => json_encode(['nombres' => 'Prueba']),
            ]);
            DB::connection('rrhh_schema_variant')->table('evaluacion_desempeno')->insert(['id' => (string) Str::uuid(), 'total' => 17]);
            foreach (self::MIGRATIONS as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $row = DB::connection('rrhh_schema_variant')->table('personal_ingresos')->where('id', $ingreso)->sole();
            self::assertSame('ALTA', $row->proposito);
            self::assertSame(1, $row->revision);
            self::assertSame('FICHA_RECIBIDA', $row->estado);
            self::assertSame(['nombres' => 'Prueba'], json_decode($row->datos_json, true));
            self::assertSame('CESADO', DB::connection('rrhh_schema_variant')->table('personal')->where('id', $person)->value('estado'));
            self::assertSame('VALIDA', DB::connection('rrhh_schema_variant')->table('evaluacion_desempeno')->value('estado_revision'));
            self::assertSame(17, DB::connection('rrhh_schema_variant')->table('evaluacion_desempeno')->value('total'));
            $versionPath = DB::connection('rrhh_schema_variant')->selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['personal_archivo_versiones', 'path'],
            );
            self::assertSame(500, (int) $versionPath->max_length);
            DB::connection('rrhh_schema_variant')->table('personal_ingreso_links')->insert([
                'id' => (string) Str::uuid(), 'ingreso_id' => $ingreso, 'personal_id' => $person,
                'proposito' => 'ACTUALIZACION', 'token_hash' => str_repeat('a', 64),
                'expires_at' => now()->addHours(72), 'actor_id' => $user,
            ]);
            self::assertSame(1, DB::connection('rrhh_schema_variant')->table('personal_ingreso_links')->count());
        });
    }

    public function test_interrupted_additive_migrations_can_resume_without_changing_existing_values(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->legacySchema('char');
            Schema::table('personal_ingresos', function (Blueprint $table): void {
                $table->string('proposito', 24)->default('ALTA');
                $table->unsignedInteger('revision')->default(1);
            });
            Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
                $table->string('estado_revision', 24)->default('VALIDA')->index();
                $table->unsignedInteger('revision_asistencia')->default(0);
            });
            $id = (string) Str::uuid();
            DB::connection('rrhh_schema_variant')->table('personal_ingresos')->insert([
                'id' => $id, 'estado' => 'ACEPTADA', 'proposito' => 'ALTA', 'revision' => 8,
            ]);
            foreach ([
                '2026_11_04_000200_add_update_purpose_to_personal_ingresos.php',
                '2026_11_04_000900_add_revision_to_evaluaciones_de_asistencia.php',
            ] as $file) {
                $migration = require database_path('migrations/'.$file);
                $migration->up();
                $migration->up();
            }
            self::assertSame(8, DB::connection('rrhh_schema_variant')->table('personal_ingresos')->where('id', $id)->value('revision'));
            self::assertTrue(Schema::hasTable('personal_ingreso_revisiones'));
            self::assertTrue(Schema::hasTable('evaluacion_asistencia_revision_eventos'));
        });
    }

    public static function legacyIdentifierTypes(): array
    {
        return [['char'], ['string']];
    }

    private function legacySchema(string $identifierType): void
    {
        foreach (['personal', 'usuarios', 'personal_bloqueo', 'personal_ingresos', 'evaluacion_desempeno'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name, $identifierType): void {
                $table->{$identifierType}('id', 36)->primary();
                if ($name === 'personal') {
                    $table->string('nombre_completo');
                    $table->string('estado', 40);
                }
                if ($name === 'personal_ingresos') {
                    $table->string('estado', 40);
                    $table->json('datos_json')->nullable();
                    $table->dateTime('submitted_at')->nullable();
                }
                if ($name === 'evaluacion_desempeno') {
                    $table->integer('total');
                }
            });
        }
    }

    private function withIsolatedSchema(callable $test): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1'
            || (int) config('database.connections.mysql.port') !== 13376
            || config('database.connections.mysql.database') !== 'proserge_app_test'
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
            $this->markTestSkipped('Requiere la instancia aislada de pruebas 127.0.0.1:13376.');
        }
        self::assertSame('proserge_app_test:13376', DB::scalar("SELECT CONCAT(DATABASE(), ':', @@port)"));
        $name = 'proserge_app_test_schema_'.bin2hex(random_bytes(8));
        self::assertMatchesRegularExpression('/^proserge_app_test_schema_[a-f0-9]{16}$/D', $name);
        $originalSchema = Schema::getFacadeRoot();
        DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.rrhh_schema_variant' => array_replace(
            config('database.connections.mysql'), ['database' => $name],
        )]);
        try {
            Schema::swap(DB::connection('rrhh_schema_variant')->getSchemaBuilder());
            $test();
        } finally {
            Schema::swap($originalSchema);
            DB::purge('rrhh_schema_variant');
            DB::statement('DROP DATABASE `'.$name.'`');
        }
    }
}
