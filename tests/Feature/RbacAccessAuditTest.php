<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Seguridad\Services\AdminProtectionService;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacAccessAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName(), 'Never run audit fixtures on real data');
    }

    public function test_gerente_respects_configured_permissions_including_empty_matrix(): void
    {
        foreach ([PermissionCatalog::emptyMatrix(), PermissionCatalog::matrixFromSelections(['inicio' => ['ver']])] as $matrix) {
            $effective = PermissionMatrix::normalizeForRoles([new Rol(['nombre' => 'GERENTE', 'estado' => 'ACTIVO', 'permisos' => $matrix])]);
            $this->assertFalse(PermissionMatrix::allowsDirect($effective, 'usuarios', 'asignar'));
            $this->assertFalse(PermissionMatrix::allowsDirect($effective, 'personal', 'eliminar'));
            $this->assertSame((bool) $matrix['inicio']['ver'], PermissionMatrix::allowsDirect($effective, 'inicio', 'ver'));
        }
    }

    public function test_inactive_roles_do_not_grant_permissions_but_active_roles_still_do(): void
    {
        $effective = PermissionMatrix::normalizeForRoles([
            new Rol(['nombre' => 'AUDIT-READ', 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['personal' => ['ver']])]),
            new Rol(['nombre' => 'AUDIT-EDIT', 'estado' => 'INACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['personal' => ['eliminar']])]),
            new Rol(['nombre' => 'SUPERADMIN', 'estado' => 'INACTIVO', 'permisos' => PermissionCatalog::fullAccessMatrix()]),
        ]);
        $this->assertTrue(PermissionMatrix::allowsDirect($effective, 'personal', 'ver'));
        $this->assertFalse(PermissionMatrix::allowsDirect($effective, 'personal', 'eliminar'));
        $this->assertFalse(PermissionMatrix::allowsDirect($effective, 'usuarios', 'administrar'));
    }

    public function test_active_admin_and_superadmin_keep_global_privileges(): void
    {
        foreach (['ADMIN', 'SUPERADMIN'] as $name) {
            $effective = PermissionMatrix::normalizeForRoles([new Rol(['nombre' => $name, 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::emptyMatrix()])]);
            $this->assertTrue(PermissionMatrix::allowsDirect($effective, 'usuarios', 'administrar'));
        }
    }

    public function test_permissions_are_the_same_with_additional_roles_loaded_or_not_loaded(): void
    {
        $user = $this->user(['personal' => ['ver']]);
        $role = $this->role(['personal' => ['editar']], 'ACTIVO');
        $this->attach($user, $role);
        $unloaded = Usuario::findOrFail($user->id);
        $loaded = Usuario::with('rolesAdicionales')->findOrFail($user->id);
        $this->assertTrue(PermissionMatrix::userCanDirect($unloaded, 'personal', 'editar'));
        $this->assertSame(PermissionMatrix::effectivePermissions($loaded), PermissionMatrix::effectivePermissions($unloaded));
    }

    public function test_inactive_additional_role_is_rejected_in_web_and_api(): void
    {
        $user = $this->user(['inicio' => ['ver']]);
        $role = $this->role(['usuarios' => ['ver']], 'INACTIVO');
        $this->attach($user, $role);
        $this->withSession($this->webSession($user))->get(route('usuarios.index'))->assertForbidden();
        $this->withToken($this->token($user))->getJson('/api/v1/seguridad/usuarios')->assertForbidden();
    }

    public function test_active_additional_role_works_in_web_and_api_and_revocation_takes_effect(): void
    {
        $user = $this->user(['inicio' => ['ver']]);
        $role = $this->role(['usuarios' => ['ver']], 'ACTIVO');
        $this->attach($user, $role);
        $token = $this->token($user);
        $this->withSession($this->webSession($user))->get(route('usuarios.index'))->assertOk();
        $this->withToken($token)->getJson('/api/v1/seguridad/usuarios')->assertOk();
        DB::table('roles')->where('id', $role->id)->update(['estado' => 'INACTIVO']);
        $this->get(route('usuarios.index'))->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/seguridad/usuarios')->assertForbidden();
    }

    public function test_inactive_account_cannot_log_in_through_api(): void
    {
        $user = $this->user(['inicio' => ['ver']], 'INACTIVO');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'audit-test-password'])->assertStatus(401);
        $this->assertSame(0, DB::table('auth_tokens')->where('usuario_id', $user->id)->count());
    }

    public function test_deactivated_account_cannot_reuse_existing_web_session_or_api_token(): void
    {
        $user = $this->user(['inicio' => ['ver'], 'perfil' => ['ver']]);
        $token = $this->token($user);
        $this->withSession($this->webSession($user))->get(route('perfil.index'))->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        DB::table('usuarios')->where('id', $user->id)->update(['estado' => 'INACTIVO']);
        $this->get(route('perfil.index'))->assertRedirect(route('login'));
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_expired_and_revoked_tokens_remain_rejected(): void
    {
        $user = $this->user(['inicio' => ['ver']]);
        $token = $this->token($user);
        DB::table('auth_tokens')->where('usuario_id', $user->id)->update(['expires_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        DB::table('auth_tokens')->where('usuario_id', $user->id)->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_inactive_privileged_primary_role_does_not_reappear_through_web_controller(): void
    {
        $admin = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::fullAccessMatrix()]);
        $admin->update(['estado' => 'INACTIVO']);
        $user = $this->user(['inicio' => ['ver']]);
        $user->update(['rol_id' => $admin->id]);
        $reader = $this->role(['evaluaciones' => ['ver_supervisores']], 'ACTIVO');
        $this->attach($user, $reader);
        $this->withSession($this->webSession($user))->get(route('evaluaciones.index', ['tipo' => 'supervisores']))
            ->assertOk()->assertDontSee('Cargar datos históricos')->assertDontSee('Nueva evaluación de supervisor');
        $this->withToken($this->token($user))->getJson('/api/v1/seguridad/usuarios')->assertForbidden();
    }

    public function test_partially_selected_persisted_role_is_checked_against_real_state(): void
    {
        $role = $this->role(['usuarios' => ['ver']], 'INACTIVO');
        $partial = Rol::query()->select(['id', 'nombre', 'permisos'])->findOrFail($role->id);
        $this->assertFalse(PermissionMatrix::allowsDirect(PermissionMatrix::normalizeForRoles([$partial]), 'usuarios', 'ver'));
    }

    public function test_retiring_an_additional_role_revokes_access_without_relogin(): void
    {
        $user = $this->user(['inicio' => ['ver']]);
        $role = $this->role(['usuarios' => ['ver']], 'ACTIVO');
        $this->attach($user, $role);
        $token = $this->token($user);
        $this->withSession($this->webSession($user))->get(route('usuarios.index'))->assertOk();
        DB::table('usuario_roles')->where('usuario_id', $user->id)->delete();
        $this->get(route('usuarios.index'))->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/seguridad/usuarios')->assertForbidden();
    }

    public function test_inactive_privileged_role_cannot_grant_admin_or_mine_scope_overrides(): void
    {
        $super = Rol::query()->firstOrCreate(['nombre' => 'SUPERADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::fullAccessMatrix()]);
        $super->update(['estado' => 'INACTIVO']);
        $user = $this->user(['evaluaciones' => ['evaluar_desempeno']]);
        $user->update(['rol_id' => $super->id]);
        $reader = $this->role(['evaluaciones' => ['evaluar_desempeno'], 'usuarios' => ['ver', 'eliminar']], 'ACTIVO');
        $this->attach($user, $reader);
        $user = $user->fresh(['rol', 'rolesAdicionales']);
        $this->assertFalse(app(AdminProtectionService::class)->isSuperAdminUser($user));
        $this->assertFalse(MinaScopeAccess::isPrivileged($user));
        $this->assertFalse(app(EvaluacionesPolicy::class)->canEvaluateOthers($user));
        $target = $this->user(['inicio' => ['ver']]);
        $token = $this->token($user);
        $this->withToken($token)->deleteJson('/api/v1/seguridad/usuarios/'.$target->id)->assertForbidden();
        $this->assertDatabaseHas('usuarios', ['id' => $target->id]);
        $this->withToken($token)->getJson('/api/v1/seguridad/scope-check?mina_id='.Str::uuid())->assertForbidden();
    }

    public function test_private_storage_rejects_unsigned_read_and_write(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit-existing.txt', 'synthetic private content');
        $this->get('/storage/audit-existing.txt')->assertForbidden();
        $this->put('/storage/audit-new.txt', ['content' => 'synthetic'])->assertForbidden();
        Storage::disk('local')->assertMissing('audit-new.txt');
    }

    private function role(array $permissions, string $state): Rol
    {
        return Rol::create(['id' => (string) Str::uuid(), 'nombre' => 'AUDIT-'.Str::random(12),
            'permisos' => PermissionCatalog::matrixFromSelections($permissions), 'estado' => $state]);
    }

    private function user(array $permissions, string $state = 'ACTIVO'): Usuario
    {
        $role = $this->role($permissions, 'ACTIVO');

        return Usuario::create(['id' => (string) Str::uuid(), 'email' => 'audit-'.Str::random(12).'@test.local',
            'password' => 'audit-test-password', 'estado' => $state, 'rol_id' => $role->id]);
    }

    private function attach(Usuario $user, Rol $role): void
    {
        DB::table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'rol_id' => $role->id, 'tipo' => 'CARGO']);
    }

    private function token(Usuario $user): string
    {
        $token = Str::random(80);
        DB::table('auth_tokens')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        return $token;
    }

    private function webSession(Usuario $user): array
    {
        return ['auth_token' => 'audit-token', 'user_id' => $user->id, 'user' => ['id' => $user->id, 'email' => $user->email,
            'rol' => $user->rol->nombre, 'permissions' => $user->rol->permisos]];
    }
}
