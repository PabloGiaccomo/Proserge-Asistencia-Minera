<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class RQMinaAssignmentHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.rq_assignment_history_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('rq_assignment_history_test');

        Schema::create('rq_mina_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_mina_id');
            $table->string('puesto');
            $table->unsignedInteger('cantidad');
            $table->unsignedInteger('cantidad_backup');
            $table->unsignedInteger('cantidad_total');
            $table->unsignedInteger('cantidad_atendida');
            $table->timestamps();
        });
        Schema::create('rq_proserge', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_mina_id');
            $table->string('estado');
            $table->timestamps();
        });
        Schema::create('rq_proserge_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_mina_detalle_id');
            $table->foreign('rq_mina_detalle_id')->references('id')->on('rq_mina_detalle')->restrictOnDelete();
            $table->string('rq_proserge_id');
            $table->string('estado');
            $table->string('retirado_por_id')->nullable();
            $table->timestamp('retirado_at')->nullable();
            $table->string('motivo_retiro')->nullable();
            $table->timestamps();
        });
        Schema::create('rq_mina_detalle_cambios', function (Blueprint $table): void {
            $table->string('id')->primary();
            foreach (['rq_mina_id', 'rq_mina_detalle_id', 'rq_proserge_id', 'puesto', 'tipo', 'mensaje', 'estado', 'created_by_usuario_id'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['cantidad_anterior', 'cantidad_nueva', 'asignaciones_retiradas'] as $column) {
                $table->unsignedInteger($column);
            }
            $table->timestamps();
        });
        Schema::create('grupo_trabajo_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_proserge_detalle_id');
            $table->string('estado_distribucion')->nullable();
        });
        Schema::create('asistencia_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_proserge_detalle_id');
        });

        DB::table('rq_proserge')->insert(['id' => 'rrhh', 'rq_mina_id' => 'parada', 'estado' => 'COMPLETADO']);
        DB::table('rq_mina_detalle')->insert([
            'id' => 'pedido', 'rq_mina_id' => 'parada', 'puesto' => 'Técnico',
            'cantidad' => 2, 'cantidad_backup' => 0, 'cantidad_total' => 2, 'cantidad_atendida' => 2,
        ]);
    }

    public function test_guardar_la_misma_demanda_no_retira_asignaciones_por_el_historial(): void
    {
        $this->assignment('primera', 'ASIGNADO', '2026-11-01');
        $this->assignment('segunda', 'ASIGNADO', '2026-11-02');
        $this->assignment('historica', 'REEMPLAZADO', '2026-11-03');

        $this->replacePedido([['puesto' => 'Técnico', 'cantidad' => 2]]);

        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'primera', 'estado' => 'ASIGNADO']);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'segunda', 'estado' => 'ASIGNADO']);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'historica', 'estado' => 'REEMPLAZADO']);
        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'pedido', 'cantidad_atendida' => 2]);
        $this->assertDatabaseCount('rq_mina_detalle_cambios', 0);
    }

    public function test_reducir_retira_la_asignacion_mas_reciente_y_conserva_su_trazabilidad(): void
    {
        $this->assignment('primera', 'ASIGNADO', '2026-11-01');
        $this->assignment('segunda', 'ASIGNADO', '2026-11-02');
        $this->assignment('historica', 'RETIRADO', '2026-11-03', 'Motivo histórico');

        $this->replacePedido([['puesto' => 'Técnico', 'cantidad' => 1]]);

        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'primera', 'estado' => 'ASIGNADO']);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'segunda', 'estado' => 'RETIRADO', 'retirado_por_id' => 'actor']);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'historica', 'estado' => 'RETIRADO', 'motivo_retiro' => 'Motivo histórico']);
        $retired = DB::table('rq_proserge_detalle')->find('segunda');
        $this->assertNotNull($retired->retirado_at);
        $this->assertNotEmpty($retired->motivo_retiro);
        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'pedido', 'cantidad_total' => 1, 'cantidad_atendida' => 1]);
        $this->assertDatabaseHas('rq_mina_detalle_cambios', ['tipo' => 'CANTIDAD_REDUCIDA', 'asignaciones_retiradas' => 1]);
    }

    public function test_quitar_puesto_con_asignacion_historica_conserva_las_filas(): void
    {
        $this->assignment('historica', 'RETIRADO', '2026-11-01', 'Motivo histórico');

        try {
            $this->replacePedido([['puesto' => 'Otro puesto', 'cantidad' => 2]]);
            $this->fail('Quitar el puesto vinculado debe rechazarse.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Técnico', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'pedido', 'puesto' => 'Técnico']);
        $this->assertDatabaseCount('rq_mina_detalle', 1);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'historica', 'estado' => 'RETIRADO']);
        $this->assertDatabaseCount('rq_mina_detalle_cambios', 0);
    }

    public function test_reducir_no_retira_asignaciones_con_distribucion_activa(): void
    {
        $this->assignment('primera', 'ASIGNADO', '2026-11-01');
        $this->assignment('segunda', 'ASIGNADO', '2026-11-02');
        DB::table('grupo_trabajo_detalle')->insert(['id' => 'grupo', 'rq_proserge_detalle_id' => 'segunda', 'estado_distribucion' => 'ASIGNADO']);

        try {
            $this->replacePedido([['puesto' => 'Técnico', 'cantidad' => 1]]);
            $this->fail('La reducción con una distribución activa debe rechazarse.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Man Power', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'pedido', 'cantidad' => 2]);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'segunda', 'estado' => 'ASIGNADO']);
        $this->assertDatabaseHas('grupo_trabajo_detalle', ['id' => 'grupo', 'rq_proserge_detalle_id' => 'segunda']);
    }

    public function test_reducir_no_retira_asignaciones_con_asistencia_registrada(): void
    {
        $this->assignment('primera', 'ASIGNADO', '2026-11-01');
        $this->assignment('segunda', 'ASIGNADO', '2026-11-02');
        DB::table('asistencia_detalle')->insert(['id' => 'asistencia', 'rq_proserge_detalle_id' => 'segunda']);

        try {
            $this->replacePedido([['puesto' => 'Técnico', 'cantidad' => 1]]);
            $this->fail('La reducción con asistencia debe rechazarse.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('asistencia registrada', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'pedido', 'cantidad' => 2]);
        $this->assertDatabaseHas('rq_proserge_detalle', ['id' => 'segunda', 'estado' => 'ASIGNADO']);
        $this->assertDatabaseHas('asistencia_detalle', ['id' => 'asistencia', 'rq_proserge_detalle_id' => 'segunda']);
    }

    public function test_reemplazar_un_pedido_vacio_no_inventa_un_puesto(): void
    {
        DB::table('rq_mina_detalle')->delete();
        DB::table('rq_proserge')->delete();

        $this->replacePedido([]);

        $this->assertDatabaseCount('rq_mina_detalle', 0);
        $this->assertDatabaseCount('rq_mina_detalle_cambios', 0);
    }

    private function assignment(string $id, string $state, string $date, ?string $reason = null): void
    {
        DB::table('rq_proserge_detalle')->insert([
            'id' => $id, 'rq_mina_detalle_id' => 'pedido', 'rq_proserge_id' => 'rrhh',
            'estado' => $state, 'created_at' => $date, 'motivo_retiro' => $reason,
        ]);
    }

    private function replacePedido(array $items): void
    {
        $service = app(RQMinaService::class);
        $parada = new RQMina(['id' => 'parada', 'estado' => 'ENVIADO']);
        $actor = (new Usuario)->forceFill(['id' => 'actor']);

        DB::transaction(fn () => (new ReflectionMethod(RQMinaService::class, 'replaceDetalle'))->invoke($service, $parada, $items, $actor));
    }
}
