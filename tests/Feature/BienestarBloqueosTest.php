<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\Usuario;
use App\Modules\Bienestar\Controllers\BienestarPageController;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => 'actor@example.test']);
        session(['user' => ['id' => $this->actor->id, 'permissions' => ['bienestar' => ['ver' => true, 'crear' => true]]]]);
        $notifications = $this->mock(OperationalNotificationService::class);
        $notifications->shouldReceive('bienestarBloqueoRegistrado')->zeroOrMoreTimes();
        $this->controller = new BienestarPageController($notifications);
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
