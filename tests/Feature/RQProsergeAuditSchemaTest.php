<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RQProsergeAuditSchemaTest extends TestCase
{
    public function test_repair_is_additive_repeatable_and_preserves_audit_on_rollback(): void
    {
        config(['database.connections.audit_repair_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('audit_repair_test');
        Schema::create('rq_proserge_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('estado');
            $table->text('motivo_retiro')->nullable();
        });
        DB::table('rq_proserge_detalle')->insert([
            'id' => 'historical-assignment', 'estado' => 'RETIRADO', 'motivo_retiro' => 'Motivo historico',
        ]);

        $migration = require database_path('migrations/2026_09_07_000100_repair_rq_proserge_assignment_audit_columns.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame(1, DB::table('rq_proserge_detalle')->count());
        $row = DB::table('rq_proserge_detalle')->first();
        $this->assertSame('RETIRADO', $row->estado);
        $this->assertSame('Motivo historico', $row->motivo_retiro);
        $this->assertNull($row->retirado_por_id);
        $this->assertNull($row->retirado_at);
        $this->assertNull($row->asignado_por_id);
        $this->assertNull($row->posicion_asignacion);
        $this->assertTrue(Schema::hasColumn('rq_proserge_detalle', 'reemplaza_a_id'));
    }
}
