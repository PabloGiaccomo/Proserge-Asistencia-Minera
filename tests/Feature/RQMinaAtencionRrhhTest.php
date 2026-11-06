<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use App\Modules\RQMina\Services\RQMinaService;
use App\Modules\RQMina\Support\RQMinaAtencionRrhhException;
use App\Modules\RQProserge\Services\RQProsergeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RQMinaAtencionRrhhTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'rq_atencion_test', 'database.connections.rq_atencion_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);

        Schema::create('rq_mina', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('estado');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->dateTime('enviado_at')->nullable();
            $table->timestamps();
        });
        Schema::create('rq_mina_detalle', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('rq_mina_id');
            $table->string('puesto');
            $table->integer('cantidad');
            $table->unsignedInteger('cantidad_backup')->default(0);
            $table->unsignedInteger('cantidad_total')->default(0);
            $table->unsignedInteger('cantidad_atendida')->default(0);
            $table->timestamps();
        });
        foreach (['rq_mina_transporte_detalle', 'rq_mina_planes', 'rq_mina_actividad_grupos'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('rq_mina_id');
                $table->string('rq_mina_plan_id')->nullable();
                $table->string('estado')->nullable();
                $table->date('fecha_inicio')->nullable();
                $table->date('fecha_fin')->nullable();
                $table->unsignedInteger('version')->nullable();
                $table->string('codigo')->nullable();
                $table->integer('orden')->nullable();
            });
        }
        Schema::create('rq_mina_actividad_transportes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('grupo_id');
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
            $table->string('rq_proserge_id');
            $table->string('estado');
            $table->timestamps();
        });

        $policy = $this->mock(RQMinaPolicy::class);
        $policy->shouldReceive('send')->andReturnUsing(
            fn (Usuario $usuario, RQMina $parada): bool => $usuario->id === 'planner' && $parada->estado === 'BORRADOR',
        );
        $policy->shouldReceive('update')->andReturnTrue();
    }

    public function test_failed_rrhh_sync_rolls_back_the_transition(): void
    {
        $parada = $this->paradaConPedido();
        $this->mock(RQProsergeService::class)->shouldReceive('syncFromRqMina')->once()->andReturnNull();

        try {
            $this->app->make(RQMinaService::class)->send(new Usuario(['id' => 'planner']), $parada);
            self::fail('A failed RR. HH. registration must fail the transaction.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('RQ Proserge', $exception->getMessage());
        }

        self::assertSame('BORRADOR', $parada->fresh()->estado);
        self::assertNull($parada->fresh()->enviado_at);
    }

    public function test_retry_uses_current_state_and_does_not_synchronize_twice(): void
    {
        $parada = $this->paradaConPedido();
        $this->mock(RQProsergeService::class)->shouldReceive('syncFromRqMina')->once()->andReturn(new RQProserge);
        $service = $this->app->make(RQMinaService::class);
        $actor = new Usuario(['id' => 'planner']);

        self::assertSame('ENVIADO', $service->send($actor, $parada)?->estado);
        self::assertNull($service->send($actor, $parada));
        self::assertSame('ENVIADO', $parada->fresh()->estado);
    }

    public function test_rrhh_failure_preserves_the_last_saved_personnel_and_reports_a_retryable_error(): void
    {
        $parada = $this->paradaConPedido();
        $plan = $this->attachPlan($parada);
        $this->mock(RQProsergeService::class)->shouldReceive('syncFromRqMina')->once()
            ->andThrow(new RuntimeException('Synthetic synchronization failure'));

        try {
            $this->app->make(RQMinaService::class)->updatePlanOperativo(
                new Usuario(['id' => 'planner']), $parada, [], [['puesto' => 'Mecánico', 'cantidad' => 5]],
                $plan, iniciarAtencionRrhh: true,
            );
            self::fail('The complete save must roll back when RR. HH. synchronization fails.');
        } catch (RQMinaAtencionRrhhException $exception) {
            self::assertStringContainsString('Vuelve a intentar', $exception->getMessage());
        }

        self::assertSame('BORRADOR', $parada->fresh()->estado);
        $this->assertDatabaseHas('rq_mina_detalle', ['id' => 'cargo', 'cantidad' => 2]);
        $this->assertDatabaseCount('rq_proserge', 0);
    }

    public function test_unauthorized_or_empty_orders_remain_in_preparation(): void
    {
        $conPedido = $this->paradaConPedido();
        $vacia = RQMina::query()->create(['id' => 'vacia', 'estado' => 'BORRADOR']);
        $this->mock(RQProsergeService::class)->shouldNotReceive('syncFromRqMina');
        $service = $this->app->make(RQMinaService::class);

        self::assertNull($service->send(new Usuario(['id' => 'lector']), $conPedido));
        self::assertNull($service->send(new Usuario(['id' => 'planner']), $vacia));
        self::assertSame('BORRADOR', $conPedido->fresh()->estado);
        self::assertSame('BORRADOR', $vacia->fresh()->estado);
    }

    public function test_old_editor_revision_cannot_replace_personnel_changed_in_the_meantime(): void
    {
        $parada = $this->paradaConPedido();
        $service = $this->app->make(RQMinaService::class);
        $revision = $service->revisionPlan($parada, '');
        DB::table('rq_mina_detalle')->where('rq_mina_id', $parada->id)->update(['cantidad' => 7]);

        try {
            $service->updatePlanOperativo(new Usuario(['id' => 'planner']), $parada, [], [], revisionEsperada: $revision);
            self::fail('The outdated editor must not replace the current order.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Revisa la versión actual', $exception->getMessage());
        }

        self::assertSame(7, DB::table('rq_mina_detalle')->where('rq_mina_id', $parada->id)->value('cantidad'));
    }

    public function test_first_web_save_synchronizes_the_saved_personnel_and_marks_the_transition(): void
    {
        $parada = $this->paradaConPedido();
        $plan = $this->attachPlan($parada);
        $this->mock(RQProsergeService::class)->shouldReceive('syncFromRqMina')->once()->andReturnUsing(
            function (Usuario $actor, RQMina $saved): RQProserge {
                self::assertSame(5, $saved->detalle->sole()->cantidad);

                return RQProserge::query()->create(['id' => 'rrhh', 'rq_mina_id' => $saved->id, 'estado' => 'PENDIENTE']);
            },
        );
        $service = $this->app->make(RQMinaService::class);
        $saved = $service->updatePlanOperativo(new Usuario(['id' => 'planner']), $parada, [], [
            ['puesto' => 'Mecánico', 'cantidad' => 5],
        ], $plan, iniciarAtencionRrhh: true, revisionEsperada: $service->revisionPlan($parada, $plan));

        self::assertTrue($saved->wasChanged('estado'));
        $this->assertDatabaseHas('rq_proserge', ['rq_mina_id' => $parada->id, 'estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('rq_mina', ['id' => $parada->id, 'estado' => 'ENVIADO']);
        $this->assertDatabaseHas('rq_mina_detalle', ['rq_mina_id' => $parada->id, 'cantidad' => 5]);
    }

    private function attachPlan(RQMina $parada): string
    {
        DB::table('rq_mina_planes')->insert([
            'id' => 'plan', 'rq_mina_id' => $parada->id, 'codigo' => 'PLAN-001', 'version' => 1,
            'estado' => 'BORRADOR', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-15',
        ]);

        return 'plan';
    }

    private function paradaConPedido(): RQMina
    {
        $parada = RQMina::query()->create([
            'id' => 'parada', 'estado' => 'BORRADOR', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-15',
        ]);
        DB::table('rq_mina_detalle')->insert(['id' => 'cargo', 'rq_mina_id' => $parada->id, 'puesto' => 'Mecánico', 'cantidad' => 2]);

        return $parada;
    }
}
