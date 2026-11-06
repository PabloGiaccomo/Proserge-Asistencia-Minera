<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Modules\Dashboard\Services\HomeSummaryDashboardService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HomeSummaryDashboardServiceTest extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as private beginIsolatedTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        if (config('database.connections.mysql.database') !== 'proserge_app_test') {
            throw new \RuntimeException('These tests require the isolated proserge_app_test database.');
        }
        $this->beginIsolatedTransaction();
    }

    public function test_view_permission_does_not_authorize_a_summary(): void
    {
        $user = $this->user(['personal' => ['ver']]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No tienes permiso para consultar este dashboard.');
        app(HomeSummaryDashboardService::class)->build($user, 'personal', []);
    }

    public function test_all_summaries_accept_dashboard_only_and_do_not_write(): void
    {
        $modules = ['personal', 'mi_asistencia', 'rq_mina', 'rq_proserge', 'bienestar', 'catalogos'];
        $user = $this->user(array_fill_keys($modules, ['dashboards']));
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($modules as $module) {
            $result = app(HomeSummaryDashboardService::class)->build($user, $module, $this->period());
            $this->assertNotEmpty($result['metrics'], $module);
            $this->assertArrayHasKey('sections', $result, $module);
        }
        $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => (bool) preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $writes);
    }

    public function test_mi_asistencia_keeps_responsible_scope_and_does_not_generate_late_marks(): void
    {
        $personal = $this->personal('RESPONSABLE DASHBOARD');
        $user = $this->user(['mi_asistencia' => ['dashboards']], $personal);
        $mine = $this->mine();
        $rq = $this->rq($user, $mine);
        $group = $this->group($user, $mine, $rq, $personal);
        $this->group($user, $mine, $rq, $this->personal('OTRO RESPONSABLE'));
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => (string) Str::uuid(), 'grupo_trabajo_id' => $group, 'personal_id' => $personal,
            'puesto_asignado_snapshot' => 'OPERARIO', 'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->travelTo(now()->setDate(2026, 8, 19)->setTime(23, 0));
        $before = DB::table('asistencia_encabezado')->count();
        $result = app(HomeSummaryDashboardService::class)->build($user, 'mi_asistencia', $this->period());
        $this->assertSame(1, $this->metric($result, 'Grupos asignados'));
        $this->assertSame(1, $this->metric($result, 'Pendientes de marcar'));
        $this->assertSame($before, DB::table('asistencia_encabezado')->count());
        $this->assertArrayNotHasKey('url', $result['sections'][0]['rows'][0]);
    }

    public function test_mine_filters_cannot_expand_rq_scope_or_add_detail_links(): void
    {
        $user = $this->user(['rq_mina' => ['dashboards']]);
        $mine = $this->mine();
        $otherMine = $this->mine();
        $this->scope($user, $mine);
        $this->rq($user, $mine);
        $this->rq($user, $otherMine);
        $service = app(HomeSummaryDashboardService::class);
        $result = $service->build($user, 'rq_mina', $this->period());
        $this->assertSame(1, $this->metric($result, 'Requerimientos'));
        $this->assertArrayNotHasKey('url', $result['sections'][0]['rows'][0]);
        $outside = $service->build($user, 'rq_mina', $this->period() + ['mina_id' => $otherMine]);
        $this->assertSame(0, $this->metric($outside, 'Requerimientos'));
    }

    public function test_rq_proserge_preserves_explicit_scope_for_gerente(): void
    {
        $user = $this->user(['rq_proserge' => ['dashboards']], null, 'GERENTE');
        $mine = $this->mine();
        $otherMine = $this->mine();
        $this->scope($user, $mine);
        foreach ([$mine, $otherMine] as $id) {
            DB::table('rq_proserge')->insert([
                'id' => (string) Str::uuid(), 'rq_mina_id' => $this->rq($user, $id), 'mina_id' => $id,
                'responsable_rrhh_id' => $user->id, 'estado' => 'PENDIENTE',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $result = app(HomeSummaryDashboardService::class)->build($user, 'rq_proserge', $this->period());
        $this->assertSame(1, $this->metric($result, 'Requerimientos'));
    }

    public function test_parada_dashboard_reuses_existing_kpis_with_readonly_navigation_and_mine_scope(): void
    {
        $user = $this->user(['inicio' => ['ver'], 'rq_mina' => ['dashboards']]);
        $mine = $this->mine();
        $this->scope($user, $mine);
        $rq = $this->rq($user, $mine);
        $outside = $this->rq($user, $this->mine());
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $user->id, 'user' => ['id' => $user->id]]);
        $response = $this->get('/inicio/dashboards/rq_mina/'.$rq)->assertOk()
            ->assertSee('Dashboard KPI')->assertSee('Volver a Inicio')
            ->assertSee('action="'.url('/inicio/dashboards/rq_mina/'.$rq).'"', false)
            ->assertDontSee('action="'.route('rq-mina.dashboard.recalculate', $rq).'"', false)->assertDontSee('Exportar Excel');
        $this->saveBrowserFixture('parada', $response->getContent());
        $this->get('/inicio/dashboards/rq_mina/'.$outside)->assertNotFound();
        $this->get('/rq-mina/'.$rq)->assertForbidden();
    }

    public function test_man_power_dashboard_forces_readonly_view_with_populated_context(): void
    {
        $user = $this->user(['inicio' => ['ver'], 'man_power' => ['dashboards']]);
        $mine = $this->mine();
        $this->scope($user, $mine);
        $rq = $this->rq($user, $mine);
        $person = $this->personal('RESPONSABLE MAN POWER DASHBOARD');
        $detail = (string) Str::uuid();
        $responseId = (string) Str::uuid();
        DB::table('rq_mina_detalle')->insert(['id' => $detail, 'rq_mina_id' => $rq, 'puesto' => 'OPERARIO', 'cantidad' => 1, 'cantidad_atendida' => 1]);
        DB::table('rq_proserge')->insert(['id' => $responseId, 'rq_mina_id' => $rq, 'mina_id' => $mine, 'responsable_rrhh_id' => $user->id, 'estado' => 'PENDIENTE']);
        DB::table('rq_proserge_detalle')->insert(['id' => (string) Str::uuid(), 'rq_proserge_id' => $responseId, 'rq_mina_detalle_id' => $detail, 'personal_id' => $person, 'puesto_asignado' => 'OPERARIO', 'fecha_inicio' => '2026-08-19', 'fecha_fin' => '2026-08-25', 'estado' => 'ASIGNADO']);
        $this->group($user, $mine, $rq, $person);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $user->id, 'user' => ['id' => $user->id]]);
        $response = $this->get('/inicio/dashboards/man_power?fecha=2026-08-19&rq_mina_id='.$rq.'&vista=grupos&dashboard_modo=turnos')
            ->assertOk()->assertSee('Estado por turno')->assertSee('OPERACIONES')
            ->assertDontSee('Seleccion diaria por cargo')
            ->assertDontSee('action="'.route('man-power.guardar-grupo').'"', false)
            ->assertDontSee('Organizar turno')->assertDontSee('Ver detalle');
        $this->saveBrowserFixture('man_power', $response->getContent());
    }

    public function test_rq_proserge_filters_period_in_sql_and_batches_coverage(): void
    {
        $user = $this->user(['rq_proserge' => ['dashboards']]);
        $mine = $this->mine();
        $this->scope($user, $mine);
        foreach (range(1, 5) as $index) {
            $rq = $this->rq($user, $mine);
            if ($index === 5) {
                DB::table('rq_mina')->where('id', $rq)->update(['fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-01-02']);
            }
            DB::table('rq_proserge')->insert(['id' => (string) Str::uuid(), 'rq_mina_id' => $rq, 'mina_id' => $mine, 'responsable_rrhh_id' => $user->id, 'estado' => 'PENDIENTE']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(HomeSummaryDashboardService::class)->build($user, 'rq_proserge', $this->period());
        $detailQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from `rq_mina_detalle`'));
        DB::disableQueryLog();
        $this->assertSame(4, $this->metric($result, 'Requerimientos'));
        $this->assertLessThanOrEqual(1, $detailQueries->count(), 'Coverage details must load in one batch for all paradas.');
    }

    public function test_wellbeing_counts_only_visible_active_overlapping_blocks(): void
    {
        $user = $this->user(['bienestar' => ['dashboards']]);
        $person = $this->personal('BIENESTAR DASHBOARD');
        foreach ([[true, 'ACTIVO', '2026-08-19'], [false, 'ACTIVO', '2026-08-19'], [true, 'ANULADO', '2026-08-19'], [true, 'ACTIVO', '2026-09-01']] as [$visible, $state, $date]) {
            DB::table('personal_bloqueo')->insert([
                'id' => (string) Str::uuid(), 'personal_id' => $person, 'tipo' => 'vacaciones',
                'fecha_inicio' => $date, 'fecha_fin' => $date, 'motivo' => 'PRUEBA',
                'bloqueado_por_id' => $user->id, 'estado' => $state, 'visible_para_planner' => $visible,
            ]);
        }
        $result = app(HomeSummaryDashboardService::class)->build($user, 'bienestar', $this->period());
        $this->assertSame(1, $this->metric($result, 'Bloqueos vigentes en el periodo'));
        $this->assertSame(1, $this->metric($result, 'Trabajadores no disponibles'));
    }

    private function metric(array $result, string $label): int
    {
        return (int) collect($result['metrics'])->firstWhere('label', $label)['value'];
    }

    private function saveBrowserFixture(string $module, string $html): void
    {
        if (getenv('HOME_DASHBOARD_QA') !== '1') {
            return;
        }
        $directory = storage_path('app/testing/home-dashboards');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$module.'.html', $html);
    }

    private function period(): array
    {
        return ['fecha_desde' => '2026-08-19', 'fecha_hasta' => '2026-08-19'];
    }

    private function user(array $permissions, ?string $personal = null, ?string $roleName = null): Usuario
    {
        $role = $roleName ? (DB::table('roles')->where('nombre', $roleName)->value('id') ?: (string) Str::uuid()) : (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('roles')->updateOrInsert(['id' => $role], ['nombre' => $roleName ?? 'HOME_SUMMARY_'.Str::random(10), 'estado' => 'ACTIVO', 'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions))]);
        DB::table('usuarios')->insert(['id' => $id, 'email' => $id.'@test.local', 'password' => bcrypt('test-secret'), 'rol_id' => $role, 'personal_id' => $personal, 'estado' => 'ACTIVO']);

        return Usuario::findOrFail($id);
    }

    private function personal(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert(['id' => $id, 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => $name, 'puesto' => 'OPERARIO', 'qr_code' => $id, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function mine(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $id, 'nombre' => 'MINA DASHBOARD '.Str::random(8), 'unidad_minera' => 'UNIDAD DASHBOARD', 'estado' => 'ACTIVO']);

        return $id;
    }

    private function scope(Usuario $user, string $mine): void
    {
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'mina_id' => $mine]);
    }

    private function rq(Usuario $user, string $mine): string
    {
        $id = (string) Str::uuid();
        DB::table('rq_mina')->insert(['id' => $id, 'mina_id' => $mine, 'area' => 'OPERACIONES', 'fecha_inicio' => '2026-08-19', 'fecha_fin' => '2026-08-25', 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function group(Usuario $user, string $mine, string $rq, string $supervisor): string
    {
        $id = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert(['id' => $id, 'fecha' => '2026-08-19', 'supervisor_id' => $supervisor, 'mina' => 'MINA DASHBOARD', 'unidad' => 'MINA', 'destino_tipo' => 'MINA', 'destino_id' => $mine, 'rq_mina_id' => $rq, 'servicio' => 'SERVICIO DASHBOARD', 'area' => 'OPERACIONES', 'horario_salida' => '07:00:00', 'turno' => 'DIA', 'estado' => 'PROGRAMADO', 'created_by_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
