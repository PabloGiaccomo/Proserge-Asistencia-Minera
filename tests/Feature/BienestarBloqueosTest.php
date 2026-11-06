<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\PersonalBloqueoEvento;
use App\Models\Usuario;
use App\Modules\Bienestar\Controllers\BienestarPageController;
use App\Modules\Bienestar\Services\BienestarBloqueoService;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BienestarBloqueosTest extends TestCase
{
    private BienestarPageController $controller;
    private Usuario $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'bienestar_test', 'database.connections.bienestar_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Carbon::setTestNow('2026-08-31 12:00:00');
        Schema::create('personal', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dni');
            $table->string('nombre_completo');
            $table->string('estado');
            $table->string('puesto')->nullable();
            $table->timestamps();
        });
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
            $table->string('personal_id')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_fichas', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('personal_id');
            $table->json('datos_json')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_04_24_000400_create_personal_bloqueos_table.php'))->up();
        (require database_path('migrations/2026_11_04_001100_create_personal_bloqueo_eventos.php'))->up();
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => 'actor@example.test']);
        session(['user' => ['id' => $this->actor->id, 'permissions' => ['bienestar' => ['ver' => true, 'crear' => true]]]]);
        $notifications = $this->mock(OperationalNotificationService::class);
        $notifications->shouldReceive('bienestarBloqueoRegistrado')->zeroOrMoreTimes();
        $this->controller = new BienestarPageController($notifications, new BienestarBloqueoService());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('bienestar_test');
        parent::tearDown();
    }

    public function test_muestra_bloqueos_sin_exigir_estado_laboral_activo(): void
    {
        foreach (['ACTIVO', 'FALTA_CONTRATO', 'CESADO'] as $estado) {
            $this->block($this->worker($estado));
        }
        $data = $this->listing();
        $this->assertCount(3, $data['trabajadoresBloqueados']);
        $this->assertSame(3, $data['resumen']['total_activos_hoy']);
        $this->assertSame(3, $data['resumen']['trabajadores_no_disponibles_periodo']);
        $this->assertSame(1, Personal::where('estado', 'ACTIVO')->count());
    }

    public function test_respeta_limites_del_rango_anulaciones_y_visibilidad(): void
    {
        $worker = $this->worker('FALTA_CONTRATO');
        $first = $this->block($worker, ['fecha_inicio' => '2026-07-15', 'fecha_fin' => '2026-08-01']);
        $last = $this->block($worker, ['fecha_inicio' => '2026-10-31', 'fecha_fin' => '2026-11-05']);
        $this->block($worker, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);
        $this->block($worker, ['estado' => 'ANULADO']);
        $this->block($worker, ['visible_para_planner' => false]);
        $data = $this->listing();
        $this->assertEqualsCanonicalizing([$first->id, $last->id], $data['bloqueos']->modelKeys());
        $this->assertCount(1, $data['trabajadoresBloqueados']);
        $this->assertSame(0, $data['resumen']['total_activos_hoy']);
    }

    public function test_registro_general_y_por_trabajador_aparecen_en_el_listado(): void
    {
        foreach (['general', 'individual'] as $mode) {
            $worker = $this->worker('FALTA_CONTRATO');
            $request = Request::create('/bienestar/bloqueos', 'POST', [
                'personal_id' => $worker->id, 'tipo' => 'vacaciones', 'motivo' => 'Prueba aislada',
                'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-09-02',
            ]);
            $request->setLaravelSession(app('session.store'));
            $response = $mode === 'general'
                ? $this->controller->storeBloqueoGeneral($request)
                : $this->controller->storeBloqueo($request, $worker->id);
            $this->assertSame(302, $response->getStatusCode());
            $block = PersonalBloqueo::where('personal_id', $worker->id)->sole();
            $this->assertSame($this->actor->id, $block->bloqueado_por_id);
            $this->assertTrue($this->listing()['bloqueos']->contains('id', $block->id));
            $this->assertCount(1, $this->controller->show($worker->id)->getData()['bloqueos']);
            $this->assertSame('FALTA_CONTRATO', $worker->fresh()->estado);
        }
    }

    public function test_busqueda_encuentra_trabajadores_sin_contrato(): void
    {
        $worker = $this->worker('FALTA_CONTRATO');
        $this->block($worker);
        $this->block($this->worker('CESADO'));
        $data = $this->listing(['search' => $worker->dni]);
        $this->assertCount(1, $data['trabajadoresBloqueados']);
        $this->assertSame($worker->id, $data['trabajadoresBloqueados']->first()['personal_id']);
    }

    public function test_no_registra_un_rango_de_fechas_invertido(): void
    {
        $worker = $this->worker('FALTA_CONTRATO');
        try {
            $this->controller->storeBloqueoGeneral(Request::create('/', 'POST', [
                'personal_id' => $worker->id, 'tipo' => 'vacaciones', 'motivo' => 'Prueba',
                'fecha_inicio' => '2026-09-02', 'fecha_fin' => '2026-08-31',
            ]));
            $this->fail('The invalid date range must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('fecha_fin', $error->errors());
        }
        $this->assertSame(0, PersonalBloqueo::count());
    }

    public function test_ver_bienestar_no_permite_registrar_bloqueos(): void
    {
        session(['user.permissions' => ['bienestar' => ['ver' => true]]]);
        try {
            $this->controller->storeBloqueoGeneral(Request::create('/', 'POST'));
            $this->fail('Creating a block requires its permission.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, PersonalBloqueo::count());
    }

    public function test_editar_exige_motivo_de_decision_y_conserva_el_bloqueo_si_falta(): void
    {
        $block = $this->block($this->worker('FALTA_CONTRATO'));
        session(['user.permissions' => ['bienestar' => ['editar' => true]]]);
        $request = Request::create('/', 'PUT', [
            'tipo' => 'vacaciones', 'motivo' => 'Motivo cambiado',
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-09-02',
        ]);

        try {
            $this->controller->updateBloqueo($request, $block->id);
            $this->fail('Editar un bloqueo exige explicar la decisión.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('motivo_decision', $error->errors());
        }
        $this->assertSame('Prueba aislada', $block->fresh()->motivo);
    }

    public function test_edicion_y_anulacion_registran_actor_y_decision_cifrados_y_sin_duplicados(): void
    {
        $worker = $this->worker('FALTA_CONTRATO');
        $block = $this->block($worker, ['tipo' => 'descanso_medico', 'detalle' => 'Detalle médico previo']);
        $actor = $this->decisionActor(['ver', 'editar', 'anular']);
        $this->mock(OperationalNotificationService::class)->shouldReceive('bienestarBloqueoAnulado')->once();
        $controller = app(BienestarPageController::class);
        $edit = Request::create('/', 'PUT', [
            'tipo' => 'descanso_medico', 'motivo' => 'Prueba aislada', 'detalle' => 'Detalle médico corregido',
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-09-04',
            'motivo_decision' => 'Corrección del plazo', 'mes' => '2026-09',
        ]);
        $edit->setUserResolver(fn () => $actor);

        $this->assertSame(
            route('bienestar.show', ['id' => $worker->id, 'mes' => '2026-09']),
            $controller->updateBloqueo($edit, $block->id)->getTargetUrl(),
        );
        $updated = PersonalBloqueoEvento::query()->sole();
        $this->assertSame(PersonalBloqueoEvento::EDITADO, $updated->tipo);
        $this->assertSame($actor->id, $updated->actor_id);
        $this->assertSame($this->actor->id, $block->fresh()->bloqueado_por_id);
        $this->assertSame('Prueba aislada', $updated->antes['motivo']);
        $this->assertSame('Detalle médico previo', $updated->antes['detalle']);
        $this->assertSame('Detalle médico corregido', $updated->despues['detalle']);
        $this->assertSame('Corrección del plazo', $updated->motivo_decision);
        $raw = DB::table('personal_bloqueo_eventos')->where('id', $updated->id)->first();
        $this->assertStringNotContainsString('Detalle médico', $raw->antes.$raw->despues);
        $this->assertStringNotContainsString('Corrección', $raw->motivo_decision);
        $this->assertArrayNotHasKey('antes', $updated->toArray());

        $annul = Request::create('/', 'POST', ['motivo_decision' => 'Documento rectificado', 'mes' => '2026-09']);
        $annul->setUserResolver(fn () => $actor);
        $controller->anularBloqueo($annul, $block->id);
        $controller->anularBloqueo($annul, $block->id);
        $this->assertSame(2, PersonalBloqueoEvento::query()->count());
        $event = PersonalBloqueoEvento::query()->where('tipo', PersonalBloqueoEvento::ANULADO)->sole();
        $this->assertSame('ACTIVO', $event->antes['estado']);
        $this->assertSame('ANULADO', $event->despues['estado']);
        $this->assertSame('Prueba aislada', $block->fresh()->motivo);
        $this->assertSame('FALTA_CONTRATO', $worker->fresh()->estado);
        request()->query->set('mes', '2026-09');
        $page = $controller->show($worker->id)->getData();
        $this->assertCount(2, $page['history']);
        $this->assertSame('2026-09', $page['month']);
        session(['user.permissions' => ['bienestar' => ['ver' => true]]]);
        $this->assertCount(0, $controller->show($worker->id)->getData()['history']);
    }

    public function test_anulacion_sin_motivo_o_permiso_no_cambia_el_bloqueo(): void
    {
        $block = $this->block($this->worker('ACTIVO'));
        $actor = $this->decisionActor(['anular']);
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $actor);
        try {
            $this->controller->anularBloqueo($request, $block->id);
            $this->fail('Anular un bloqueo exige explicar la decisión.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('motivo_decision', $error->errors());
        }

        try {
            app(BienestarBloqueoService::class)->annul($block, $this->decisionActor(['ver']), 'Sin permiso de anular');
            $this->fail('Ver Bienestar no autoriza a anular bloqueos.');
        } catch (AuthorizationException) {
            $this->assertSame('ACTIVO', $block->fresh()->estado);
            $this->assertSame(0, PersonalBloqueoEvento::query()->count());
        }
    }

    public function test_fallo_del_evento_revierte_la_anulacion(): void
    {
        $block = $this->block($this->worker('ACTIVO'));
        $actor = $this->decisionActor(['anular']);
        Event::listen('eloquent.creating: '.PersonalBloqueoEvento::class, function (): void {
            throw new \RuntimeException('Fallo de auditoría simulado');
        });
        try {
            app(BienestarBloqueoService::class)->annul($block, $actor, 'Prueba');
            $this->fail('El fallo de auditoría debe abortar la anulación.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fallo de auditoría simulado', $error->getMessage());
        }
        $this->assertSame('ACTIVO', $block->fresh()->estado);
        $this->assertSame(0, PersonalBloqueoEvento::query()->count());
    }

    private function decisionActor(array $actions): Usuario
    {
        $permissions = ['bienestar' => array_fill_keys($actions, true)];
        session(['user.permissions' => $permissions]);
        $actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => 'decision@example.test']);
        $actor->setRelation('rol', (object) ['nombre' => 'BIENESTAR_TEST', 'estado' => 'ACTIVO', 'permisos' => $permissions]);
        $actor->setRelation('rolesAdicionales', new Collection());

        return $actor;
    }

    private function worker(string $estado): Personal
    {
        return Personal::create(['id' => (string) Str::uuid(), 'dni' => Str::random(8), 'nombre_completo' => 'PERSONAL DE PRUEBA', 'estado' => $estado]);
    }

    private function block(Personal $worker, array $overrides = []): PersonalBloqueo
    {
        return PersonalBloqueo::create(array_merge([
            'id' => (string) Str::uuid(), 'personal_id' => $worker->id,
            'bloqueado_por_id' => $this->actor->id, 'tipo' => 'vacaciones', 'motivo' => 'Prueba aislada',
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-09-02',
            'estado' => 'ACTIVO', 'visible_para_planner' => true,
        ], $overrides));
    }

    private function listing(array $filters = []): array
    {
        return $this->controller->index(Request::create('/bienestar', 'GET', array_merge([
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-10-31',
        ], $filters)))->getData();
    }
}
