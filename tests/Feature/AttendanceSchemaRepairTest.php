<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceSchemaRepairTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'attendance_repair_test', 'database.connections.attendance_repair_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach (['grupo_trabajo', 'asistencia_encabezado', 'faltas'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('observaciones')->nullable();
            });
            DB::table($name)->insert(['id' => 'historical', 'observaciones' => 'Preserve this record']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('attendance_repair_test');
        parent::tearDown();
    }

    public function test_repara_esquema_legacy_sin_perder_datos_y_permite_repetir(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_repair_attendance_destination_schema.php');
        $migration->up();
        $migration->up();
        foreach (['grupo_trabajo', 'asistencia_encabezado', 'faltas'] as $name) {
            $this->assertTrue(Schema::hasColumns($name, ['destino_tipo', 'destino_id']));
            $this->assertSame(1, DB::table($name)->count());
            $this->assertSame('Preserve this record', DB::table($name)->value('observaciones'));
            $this->assertNull(DB::table($name)->value('destino_id'));
        }
        $this->assertTrue(Schema::hasColumns('faltas', [
            'asistencia_encabezado_id', 'asistencia_detalle_id', 'estado', 'motivo_correccion',
            'motivo_anulacion', 'corregido_por_usuario_id', 'anulado_por_usuario_id', 'corregido_at', 'anulado_at',
        ]));
        DB::table('asistencia_encabezado')->insert(['id' => 'new', 'destino_tipo' => 'MINA', 'destino_id' => 'mine-test']);
        DB::table('faltas')->insert(['id' => 'absence', 'asistencia_encabezado_id' => 'new', 'destino_tipo' => 'MINA', 'destino_id' => 'mine-test']);
        $migration->down();
        $this->assertSame('mine-test', DB::table('asistencia_encabezado')->where('id', 'new')->value('destino_id'));
        $this->assertSame('ACTIVA', DB::table('faltas')->where('id', 'absence')->value('estado'));
    }

    public function test_no_sobrescribe_destinos_ni_estados_ya_configurados(): void
    {
        Schema::table('grupo_trabajo', function (Blueprint $table): void {
            $table->string('destino_tipo')->nullable();
            $table->string('destino_id')->nullable();
        });
        DB::table('grupo_trabajo')->update(['destino_tipo' => 'TALLER', 'destino_id' => 'existing']);
        Schema::table('faltas', fn (Blueprint $table) => $table->string('estado')->default('ACTIVA'));
        DB::table('faltas')->update(['estado' => 'ANULADA']);
        (require database_path('migrations/2026_08_31_000100_repair_attendance_destination_schema.php'))->up();
        $this->assertSame('TALLER', DB::table('grupo_trabajo')->value('destino_tipo'));
        $this->assertSame('existing', DB::table('grupo_trabajo')->value('destino_id'));
        $this->assertSame('ANULADA', DB::table('faltas')->value('estado'));
    }
}
