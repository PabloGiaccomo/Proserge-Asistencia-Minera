<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionCheckboxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Route::get('/__test/checkbox-dashboard', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.auth', 'web.permission:evaluaciones,dashboards']);
        Route::post('/__test/checkbox-approval', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.auth', 'web.permission:personal_ingresos,aprobar']);
        Route::get('/__test/checkbox-notifications', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.auth', 'web.permission:notificaciones,ver']);
    }

    public function test_explicit_unchecked_permissions_are_not_recreated_by_normalization_or_access_checks(): void
    {
        foreach (PermissionCatalog::availableModuleActions() as $module => $actions) {
            foreach (array_intersect($actions, ['ver', 'actualizar', 'administrar', 'dashboards']) as $action) {
                $matrix = PermissionCatalog::matrixFromSelections([$module => [$action]]);
                $normalized = PermissionMatrix::normalize($matrix);
                foreach ($actions as $other) {
                    $this->assertSame($other === $action, $normalized[$module][$other], "$module.$action recreated $other");
                    $this->assertSame($other === $action, PermissionMatrix::allowsDirect($normalized, $module, $other), "$module.$action directly granted $other");
                    $this->assertSame($other === $action, PermissionMatrix::allows($normalized, $module, $other), "$module.$action indirectly granted $other");
                }
            }
        }
    }

    public function test_saving_checks_revokes_dashboard_and_preserves_only_selected_actions(): void
    {
        $editor = $this->user($this->role(['roles' => ['ver', 'actualizar']]));
        $target = $this->role(['evaluaciones' => ['ver', 'dashboards', 'crear']]);
        $this->withSession($this->sessionFor($editor))->put(route('seguridad.roles.update', $target->id), [
            'nombre' => $target->nombre, 'estado' => 'ACTIVO', 'permisos_present' => '1',
            'permisos' => ['evaluaciones' => ['ver' => '1', 'evaluar_desempeno' => '1']],
        ])->assertRedirect(route('seguridad.roles.edit', $target->id))->assertSessionHasNoErrors();
        $stored = $target->fresh()->permisos;
        $this->assertTrue($stored['evaluaciones']['evaluar_desempeno']);
        $this->assertFalse($stored['evaluaciones']['dashboards']);
        $this->assertFalse($stored['evaluaciones']['crear']);
        $this->get(route('seguridad.roles.edit', $target->id))->assertOk()
            ->assertSee('name="permisos[evaluaciones][dashboards]" value="1" data-action-toggle >', false);
    }

    public function test_grants_and_revocations_apply_to_existing_session_on_next_request(): void
    {
        $role = $this->role(['evaluaciones' => ['ver', 'evaluar_desempeno']]);
        $user = $this->user($role);
        $this->withSession($this->sessionFor($user))->get('/__test/checkbox-dashboard')->assertForbidden();
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['evaluaciones' => ['ver', 'evaluar_desempeno', 'dashboards']])]);
        $this->get('/__test/checkbox-dashboard')->assertOk();
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['evaluaciones' => ['ver', 'evaluar_desempeno']])]);
        $this->get('/__test/checkbox-dashboard')->assertForbidden();
    }

    public function test_validation_retry_does_not_restore_unchecked_permissions(): void
    {
        $editor = $this->user($this->role(['roles' => ['ver', 'actualizar']]));
        $target = $this->role(['evaluaciones' => ['ver', 'dashboards']]);
        $this->withSession([
            ...$this->sessionFor($editor),
            '_old_input' => ['permisos_present' => '1', 'nombre' => '', 'estado' => 'ACTIVO'],
        ])->get(route('seguridad.roles.edit', $target->id))->assertOk()
            ->assertDontSee('name="permisos[evaluaciones][dashboards]" value="1" data-action-toggle checked', false);
    }

    public function test_unchecked_approval_cannot_be_granted_by_another_module_action(): void
    {
        $user = $this->user($this->role(['personal' => ['ver', 'actualizar']]));
        $this->withSession($this->sessionFor($user))->post('/__test/checkbox-approval')->assertForbidden();
    }

    public function test_notification_preferences_cannot_grant_unchecked_module_access(): void
    {
        $user = $this->user($this->role(['inicio' => ['ver']]));
        DB::table('notification_user_settings')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'in_app_enabled' => true]);
        $this->withSession($this->sessionFor($user))->get('/__test/checkbox-notifications')->assertForbidden();
        $this->assertStringNotContainsString('headerNotifToggle', view('partials.header', [
            'notificationsEnabled' => true, 'headerUnreadCount' => 0, 'headerNotifications' => collect(),
        ])->render());
    }

    public function test_active_administrators_can_access_notifications_even_when_delivery_is_disabled(): void
    {
        foreach (['ADMIN', 'SUPERADMIN'] as $name) {
            $role = Rol::firstOrCreate(['nombre' => $name], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::emptyMatrix()]);
            $role->update(['estado' => 'ACTIVO']);
            $user = $this->user($role);
            DB::table('notification_user_settings')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'in_app_enabled' => false]);
            $this->withSession($this->sessionFor($user))->get('/__test/checkbox-notifications')->assertOk();
        }
    }

    public function test_another_active_role_can_grant_permission_but_inactive_roles_cannot(): void
    {
        $user = $this->user($this->role(['evaluaciones' => ['ver', 'evaluar_desempeno']]));
        $additional = $this->role(['evaluaciones' => ['dashboards']]);
        DB::table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'rol_id' => $additional->id, 'tipo' => 'CARGO']);
        $this->withSession($this->sessionFor($user))->get('/__test/checkbox-dashboard')->assertOk();
        $additional->update(['estado' => 'INACTIVO']);
        $this->get('/__test/checkbox-dashboard')->assertForbidden();
    }

    public function test_active_privileged_roles_always_have_every_permission_even_with_partial_matrix(): void
    {
        foreach (['ADMIN', 'SUPERADMIN'] as $name) {
            $matrix = PermissionCatalog::matrixFromSelections(['inicio' => ['ver']]);
            $this->assertSame(PermissionCatalog::fullAccessMatrix(), PermissionMatrix::normalizeForRole($name, $matrix));
            $effective = PermissionMatrix::normalizeForRoles([new Rol(['nombre' => $name, 'estado' => 'ACTIVO', 'permisos' => $matrix])]);
            $this->assertSame(PermissionCatalog::fullAccessMatrix(), $effective);
            $inactive = PermissionMatrix::normalizeForRoles([new Rol(['nombre' => $name, 'estado' => 'INACTIVO', 'permisos' => $matrix])]);
            $this->assertFalse(PermissionMatrix::allowsDirect($inactive, 'evaluaciones', 'dashboards'));
        }
    }

    public function test_logistics_dashboard_can_be_granted_without_granting_the_epp_module(): void
    {
        $user = $this->user($this->role(['logistica' => ['ver', 'ver_logistica_dashboard']]));
        $this->withSession($this->sessionFor($user))->get(route('logistica.index'))->assertOk()
            ->assertSessionHas('user.permissions.epps.ver', false)
            ->assertSee('data-logistics-tab-panel="dashboard"', false);
    }

    public function test_each_logistics_tab_receives_its_context_without_other_tab_or_epp_grants(): void
    {
        foreach (PermissionCatalog::logisticsTabActions() as $tab => $action) {
            $user = $this->user($this->role(['logistica' => ['ver', $action]]));
            $response = $this->withSession($this->sessionFor($user))->get(route('logistica.index', ['tab' => $tab]));
            $response->assertOk()->assertSessionHas('user.permissions.epps.ver', false);
            if (! in_array($tab, ['herramientas', 'servicios'], true)) {
                $response->assertViewHas('costRows')->assertViewHas('identityRows')->assertViewHas('filterChips');
            }
        }
    }

    public function test_evaluations_hides_dashboard_and_denies_direct_and_partial_requests_when_unchecked(): void
    {
        $role = $this->role(['evaluaciones' => ['ver', 'ver_desempeno', 'evaluar_desempeno']]);
        $user = $this->user($role);
        $this->withSession($this->sessionFor($user))->get(route('evaluaciones.index'))
            ->assertOk()->assertViewHas('availableTypes', fn ($types) => $types->all() === ['desempeno'])
            ->assertDontSee('tipo=dashboard', false);
        $this->get(route('evaluaciones.index', ['tipo' => 'dashboard']))->assertForbidden();
        $this->withHeader('X-Evaluation-Dashboard', '1')->get(route('evaluaciones.index', ['tipo' => 'dashboard']))->assertForbidden();
    }

    public function test_evaluations_dashboard_grant_and_revocation_apply_on_real_page_without_logout(): void
    {
        $role = $this->role(['evaluaciones' => ['ver_desempeno', 'dashboards']]);
        $user = $this->user($role);
        $this->withSession($this->sessionFor($user))->get(route('evaluaciones.index', ['tipo' => 'dashboard']))
            ->assertOk()->assertViewHas('activeType', 'dashboard');
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['evaluaciones' => ['ver_desempeno']])]);
        $this->get(route('evaluaciones.index'))->assertOk()
            ->assertViewHas('availableTypes', fn ($types) => $types->all() === ['desempeno']);
        $this->get(route('evaluaciones.index', ['tipo' => 'dashboard']))->assertForbidden();
    }

    private function role(array $permissions): Rol
    {
        return Rol::create(['id' => (string) Str::uuid(), 'nombre' => 'CHECKS-'.Str::random(12), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections($permissions)]);
    }

    private function user(Rol $role): Usuario
    {
        return Usuario::create(['id' => (string) Str::uuid(), 'email' => Str::random(12).'@test.local', 'password' => 'test-only-password', 'estado' => 'ACTIVO', 'rol_id' => $role->id]);
    }

    private function sessionFor(Usuario $user): array
    {
        return ['auth_token' => 'test-only-token', 'user_id' => $user->id, 'user' => ['id' => $user->id, 'permissions' => PermissionCatalog::emptyMatrix()]];
    }
}
