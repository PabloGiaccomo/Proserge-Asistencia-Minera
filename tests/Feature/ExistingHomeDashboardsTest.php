<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Modules\Faltas\Policies\FaltaPolicy;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExistingHomeDashboardsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_permissions_open_existing_panels_without_module_read_or_write_permissions(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        foreach (['asistencias', 'faltas', 'evaluaciones', 'man_power', 'logistica'] as $module) {
            $action = $module === 'logistica' ? 'ver_logistica_dashboard' : 'dashboards';
            $user = $this->createUser(['inicio' => ['ver'], $module => [$action]]);
            $response = $this->withSession($this->sessionFor($user))->get('/inicio/dashboards/'.$module);
            $response->assertOk()->assertSee('Volver a Inicio');
            $response->assertSee('action="'.url('/inicio/dashboards/'.$module).'"', false);
            $response->assertDontSee('action="'.route('man-power.guardar-grupo').'"', false);
        }
    }

    public function test_module_read_permission_does_not_grant_standalone_dashboard(): void
    {
        foreach (['asistencias', 'faltas', 'evaluaciones', 'man_power', 'logistica'] as $module) {
            $action = $module === 'evaluaciones' ? 'ver_desempeno' : 'ver';
            $user = $this->createUser([$module => [$action]]);
            $this->withSession($this->sessionFor($user))->get('/inicio/dashboards/'.$module)->assertForbidden();
        }
    }

    public function test_dashboard_permission_does_not_enable_domain_manage_policies(): void
    {
        $user = $this->createUser(['asistencias' => ['dashboards'], 'faltas' => ['dashboards'], 'man_power' => ['dashboards']]);
        $this->assertFalse(app(AsistenciaPolicy::class)->manage($user));
        $this->assertFalse(app(FaltaPolicy::class)->manage($user));
        $this->assertFalse(app(ManPowerPolicy::class)->manageGrupos($user));
        $this->assertFalse(app(ManPowerPolicy::class)->viewParadas($user));
    }

    public function test_evaluations_fragment_keeps_type_permissions_and_standalone_filter_url(): void
    {
        $user = $this->createUser(['evaluaciones' => ['dashboards']]);
        $url = url('/inicio/dashboards/evaluaciones');
        $this->withSession($this->sessionFor($user))
            ->withHeader('X-Evaluation-Dashboard', '1')
            ->get($url.'?evaluacion_tipo=residentes')
            ->assertOk()->assertSee('id="evaluation-dashboard"', false)
            ->assertSee('action="'.$url.'"', false)
            ->assertSee('No tienes habilitada la consulta de tipos de evaluación')
            ->assertDontSee('name="trabajador_id"', false);
    }

    public function test_standalone_periods_and_logistics_filters_reject_invalid_input(): void
    {
        $user = $this->createUser(['asistencias' => ['dashboards'], 'faltas' => ['dashboards'], 'logistica' => ['ver_logistica_dashboard']]);
        $this->withSession($this->sessionFor($user));
        foreach (['asistencias', 'faltas'] as $module) {
            $this->getJson('/inicio/dashboards/'.$module.'?fecha_desde=2099-01-01')->assertUnprocessable();
        }
        $this->getJson('/inicio/dashboards/logistica?q[]=invalid')->assertUnprocessable();
        $this->getJson('/inicio/dashboards/logistica?minas[0][]=invalid')->assertUnprocessable();
    }

    private function createUser(array $permissions): Usuario
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId, 'nombre' => 'HOME_DASHBOARD_'.Str::upper(Str::random(8)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)), 'estado' => 'ACTIVO',
        ]);
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id, 'rol_id' => $roleId, 'email' => $id.'@test.local',
            'password' => bcrypt('secret123'), 'estado' => 'ACTIVO',
        ]);

        return Usuario::query()->with('rol')->findOrFail($id);
    }

    private function sessionFor(Usuario $user): array
    {
        return ['auth_token' => 'test-token', 'user_id' => $user->id, 'user' => ['id' => $user->id]];
    }
}
