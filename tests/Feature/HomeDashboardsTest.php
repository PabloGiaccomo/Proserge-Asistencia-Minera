<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomeDashboardsTest extends TestCase
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

    public function test_home_cards_link_only_to_authorized_dashboards(): void
    {
        $this->signIn(['inicio' => ['ver'], 'personal' => ['dashboards']]);

        $this->get('/inicio')->assertOk()
            ->assertSee('href="'.url('/inicio/dashboards/personal').'"', false)
            ->assertDontSee('href="'.url('/inicio/dashboards/faltas').'"', false);
    }

    public function test_dashboard_access_does_not_grant_module_access(): void
    {
        $this->signIn(['inicio' => ['ver'], 'personal' => ['dashboards']]);

        $this->get('/inicio/dashboards/personal')->assertOk()->assertSee('Volver a Inicio');
        $this->get('/personal')->assertForbidden();
        $this->get('/inicio/dashboards/faltas')->assertForbidden();
    }

    public function test_module_view_permission_does_not_grant_dashboard_access(): void
    {
        $this->signIn(['inicio' => ['ver'], 'personal' => ['ver']]);

        $this->get('/inicio/dashboards/personal')->assertForbidden();
    }

    public function test_dashboards_require_an_authenticated_session(): void
    {
        $this->get('/inicio/dashboards/personal')->assertRedirect(route('login'));
    }

    public function test_all_cards_open_readonly_dashboards_and_keep_return_navigation(): void
    {
        $modules = ['personal', 'mi_asistencia', 'rq_mina', 'rq_proserge', 'bienestar', 'catalogos', 'asistencias', 'faltas', 'evaluaciones', 'man_power'];
        $permissions = array_fill_keys($modules, ['dashboards']);
        $permissions['inicio'] = ['ver'];
        $permissions['logistica'] = ['ver_logistica_dashboard'];
        $permissions['evaluaciones'] = ['dashboards', 'ver_desempeno', 'ver_supervisores', 'ver_residentes'];
        $this->signIn($permissions);
        $paths = ['inicio' => '/inicio'];
        foreach ([...$modules, 'logistica'] as $module) {
            $paths[$module] = '/inicio/dashboards/'.$module;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($paths as $module => $path) {
            $response = $this->get($path)->assertOk();
            if ($module !== 'inicio') {
                $response->assertSee('Volver a Inicio')->assertSee('href="'.route('inicio').'"', false);
            }
            if (getenv('HOME_DASHBOARD_QA') === '1') {
                $directory = storage_path('app/testing/home-dashboards');
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory.'/'.$module.'.html', $response->getContent());
            }
        }
        $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => (bool) preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $writes);
    }

    public function test_revoked_dashboard_permission_takes_effect_on_the_next_request(): void
    {
        $userId = $this->signIn(['inicio' => ['ver'], 'personal' => ['dashboards']]);
        $this->get('/inicio/dashboards/personal')->assertOk();
        DB::table('roles')->where('id', DB::table('usuarios')->where('id', $userId)->value('rol_id'))
            ->update(['permisos' => json_encode(PermissionCatalog::matrixFromSelections(['inicio' => ['ver']]))]);

        $this->get('/inicio/dashboards/personal')->assertForbidden();
        $this->get('/inicio')->assertOk()->assertDontSee('href="'.url('/inicio/dashboards/personal').'"', false);
    }

    public function test_inactive_roles_do_not_provide_dashboard_access(): void
    {
        $userId = $this->signIn(['inicio' => ['ver'], 'personal' => ['dashboards']]);
        DB::table('roles')->where('id', DB::table('usuarios')->where('id', $userId)->value('rol_id'))->update(['estado' => 'INACTIVO']);
        $this->get('/inicio/dashboards/personal')->assertForbidden();
    }

    public function test_invalid_summary_periods_are_rejected_before_queries(): void
    {
        $this->signIn(['inicio' => ['ver'], 'bienestar' => ['dashboards']]);
        foreach (['fecha_desde=2026-10-26&fecha_hasta=2026-10-01', 'fecha_desde=2024-01-01&fecha_hasta=2026-10-26', 'fecha_desde[]=2026-10-26'] as $query) {
            $this->getJson('/inicio/dashboards/bienestar?'.$query)->assertUnprocessable();
        }
    }

    private function signIn(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'HOME_'.Str::random(12),
            'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => $userId.'@test.local',
            'password' => bcrypt('test-secret'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
        ]);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $userId, 'user' => ['id' => $userId]]);

        return $userId;
    }
}
