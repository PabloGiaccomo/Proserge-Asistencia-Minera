<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\RQMinaPlan;
use App\Modules\RQMina\Services\RQMinaPlanService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class RQMinaPlanParentConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'rq_plan_parent_test', 'database.connections.rq_plan_parent_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::create('rq_mina', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->timestamps();
        });
        Schema::create('rq_mina_planes', function (Blueprint $table): void {
            $table->string('id')->primary();
            foreach (['rq_mina_id', 'codigo', 'nombre', 'semana_referencia', 'estado', 'observaciones', 'created_by_usuario_id', 'updated_by_usuario_id'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('version');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->timestamps();
        });
        Schema::create('rq_mina_actividad_grupos', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_mina_id');
            $table->string('rq_mina_plan_id')->nullable();
            $table->integer('orden');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('rq_plan_parent_test');
        parent::tearDown();
    }

    public function test_el_plan_inicial_usa_el_rango_actual_de_la_parada(): void
    {
        $stale = $this->staleParada();

        $plan = app(RQMinaPlanService::class)->createDefaultPlan($stale);

        $this->assertSame('2026-11-01', $plan->fecha_inicio->toDateString());
        $this->assertSame('2026-11-15', $plan->fecha_fin->toDateString());
    }

    public function test_crear_plan_rechaza_fechas_que_solo_cabian_en_el_rango_anterior(): void
    {
        $stale = $this->staleParada();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El plan no puede terminar despues de la parada.');

        app(RQMinaPlanService::class)->createPlan($stale, [
            'nombre' => 'Plan de prueba', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-20',
        ]);
    }

    public function test_editar_plan_rechaza_fechas_que_solo_cabian_en_el_rango_anterior(): void
    {
        $stale = $this->staleParada();
        $plan = $this->existingPlan($stale);

        try {
            app(RQMinaPlanService::class)->updatePlan($stale, $plan, ['fecha_fin' => '2026-11-20']);
            $this->fail('El rango debe validarse con la parada actual.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('El plan no puede terminar despues de la parada.', $exception->getMessage());
        }

        $this->assertSame('2026-11-10', $plan->fresh()->fecha_fin->toDateString());
    }

    public function test_duplicar_plan_rechaza_fechas_que_solo_cabian_en_el_rango_anterior(): void
    {
        $stale = $this->staleParada();
        $plan = $this->existingPlan($stale);

        try {
            app(RQMinaPlanService::class)->duplicatePlan($stale, $plan, [
                'nombre' => 'Copia de prueba', 'fecha_inicio' => '2026-11-11', 'fecha_fin' => '2026-11-20',
            ]);
            $this->fail('El rango duplicado debe validarse con la parada actual.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('El plan no puede terminar despues de la parada.', $exception->getMessage());
        }

        $this->assertDatabaseCount('rq_mina_planes', 1);
    }

    private function staleParada(): RQMina
    {
        $parada = RQMina::query()->create([
            'id' => 'parada-sintetica', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30',
        ]);
        DB::table('rq_mina')->where('id', $parada->id)->update(['fecha_fin' => '2026-11-15']);

        return $parada;
    }

    private function existingPlan(RQMina $parada): RQMinaPlan
    {
        return RQMinaPlan::query()->create([
            'id' => 'plan-sintetico', 'rq_mina_id' => $parada->id,
            'codigo' => 'PLAN-001', 'nombre' => 'Plan de prueba', 'version' => 1,
            'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-10', 'estado' => 'BORRADOR',
        ]);
    }
}
