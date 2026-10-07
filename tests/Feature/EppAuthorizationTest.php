<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppOperacion;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Modules\Epps\Policies\EppOperationPolicy;
use App\Modules\Epps\Services\EppScopeService;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EppAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public static function capabilities(): array
    {
        return array_map(fn (string $action): array => [$action], [
            'ver_operaciones', 'preparar_entrega', 'confirmar_entrega',
            'preparar_devolucion', 'confirmar_devolucion', 'ver_evidencia',
            'descargar_constancia', 'gestionar_inventario', 'ajustar_inventario',
            'corregir', 'anular', 'aprobar_excepcion_firma',
        ]);
    }

    #[DataProvider('capabilities')]
    public function test_operation_capability_requires_direct_grant(string $action): void
    {
        $this->assertFalse(PermissionMatrix::allows([
            'logistica' => ['ver' => true, 'ver_logistica_entregas' => true, 'administrar' => true],
        ], 'epps', $action));
        $this->assertTrue(PermissionMatrix::allowsDirect([
            'epps' => [$action => true],
        ], 'epps', $action));
    }

    public function test_tab_visibility_does_not_grant_epp_writes(): void
    {
        // The operation controller is introduced in a later task. Exercise its exact middleware contract now.
        Route::post('/__test/epps/operaciones', fn () => response()->json(['ok' => true]))
            ->middleware(['web', 'web.auth', 'web.permission:epps,preparar_entrega']);
        $user = $this->user(['logistica' => ['ver', 'ver_logistica_entregas']]);

        $this->withSession(['auth_token' => 'test-token', 'user_id' => $user->id])
            ->postJson('/__test/epps/operaciones')->assertForbidden();
        $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections([
            'logistica' => ['ver', 'ver_logistica_entregas'], 'epps' => ['preparar_entrega'],
        ])]);
        $this->postJson('/__test/epps/operaciones')->assertOk();
    }

    public function test_legacy_epp_grants_are_normalized_without_logistics_escalation(): void
    {
        $matrix = PermissionMatrix::normalize(['epps' => [
            'ver' => true, 'registrar' => true, 'devolver' => true,
            'editar' => true, 'eliminar' => true, 'configurar' => true,
        ]]);
        foreach (['ver_operaciones', 'preparar_entrega', 'confirmar_entrega',
            'preparar_devolucion', 'confirmar_devolucion', 'corregir', 'anular', 'gestionar_inventario'] as $action) {
            $this->assertTrue(PermissionMatrix::allowsDirect($matrix, 'epps', $action), $action);
        }
        $this->assertFalse(PermissionMatrix::allowsDirect($matrix, 'epps', 'aprobar_excepcion_firma'));
        $this->assertFalse(PermissionMatrix::allowsDirect($matrix, 'epps', 'ajustar_inventario'));
        $this->assertTrue(PermissionMatrix::allowsDirect(['epps.write'], 'epps', 'confirmar_entrega'));
        $this->assertTrue(PermissionMatrix::allowsDirect(['epps.admin'], 'epps', 'gestionar_inventario'));
        $this->assertFalse(PermissionMatrix::allowsDirect(['epps.admin'], 'epps', 'aprobar_excepcion_firma'));
        $this->assertSame($matrix, PermissionMatrix::normalize($matrix));
    }

    public function test_explicit_false_overrides_legacy_grant(): void
    {
        $permissions = ['epps' => ['registrar' => true, 'confirmar_entrega' => false]];
        $this->assertTrue(PermissionMatrix::allowsDirect($permissions, 'epps', 'preparar_entrega'));
        $this->assertFalse(PermissionMatrix::allowsDirect($permissions, 'epps', 'confirmar_entrega'));
    }

    public function test_malformed_epp_permissions_deny_access(): void
    {
        $this->assertFalse(PermissionMatrix::allowsDirect(['epps' => true], 'epps', 'confirmar_entrega'));
    }

    public function test_only_warehouses_in_scope_are_visible(): void
    {
        $user = $this->user();
        $mine = $this->mine();
        $mineLess = $this->warehouse(null);
        $allowed = $this->warehouse($mine);
        $outside = $this->warehouse($this->mine());
        $this->scope($user, $mine);
        $service = app(EppScopeService::class);

        $this->assertEqualsCanonicalizing([$allowed->id], $service->visibleWarehouseIds($user));
        $service->assertWarehouseAccess($user, $allowed);
        $this->assertFalse($service->canAccessWarehouse($user, $mineLess));
        $this->expectException(AuthorizationException::class);
        $service->assertWarehouseAccess($user, $outside);
    }

    public function test_worker_requires_an_active_relation_in_scope(): void
    {
        $user = $this->user();
        $mine = $this->mine();
        $this->scope($user, $mine);
        $personal = $this->personal();
        $relation = $this->assign($personal, $mine, false);
        $this->assign($personal, $this->mine(), true);
        $service = app(EppScopeService::class);
        $this->assertFalse($service->canAccessPersonal($user, $personal));
        $relation->update(['activo' => true]);
        $service->assertPersonalAccess($user, $personal);
        $relation->update(['activo' => false]);
        $this->expectException(AuthorizationException::class);
        $service->assertPersonalAccess($user, $personal);
    }

    public static function privilegedRoles(): array
    {
        return [['ADMIN'], ['GERENTE'], ['SUPERADMIN']];
    }

    #[DataProvider('privilegedRoles')]
    public function test_active_privileged_user_sees_all_warehouses_and_workers(string $role): void
    {
        $user = $this->user([], $role);
        $warehouse = $this->warehouse(null);
        $other = $this->warehouse($this->mine());
        $service = app(EppScopeService::class);
        $this->assertContains($warehouse->id, $service->visibleWarehouseIds($user));
        $this->assertContains($other->id, $service->visibleWarehouseIds($user));
        $service->assertWarehouseAccess($user, $warehouse);
        $service->assertPersonalAccess($user, $this->personal());
        $this->assertTrue(app(EppOperationPolicy::class)->viewAny($user));
    }

    public function test_inactive_user_is_denied_even_when_privileged(): void
    {
        $user = $this->user([], 'ADMIN');
        $user->update(['estado' => 'INACTIVO']);
        $service = app(EppScopeService::class);
        $this->assertSame([], $service->visibleWarehouseIds($user));
        $this->assertFalse($service->canAccessWarehouse($user, $this->warehouse(null)));
        $this->assertFalse($service->canAccessPersonal($user, $this->personal()));
        $this->assertFalse(app(EppOperationPolicy::class)->viewAny($user));
    }

    public function test_policy_applies_permission_warehouse_and_worker_scope_to_each_operation_gate(): void
    {
        $mine = $this->mine();
        $warehouse = $this->warehouse($mine);
        $personal = $this->personal();
        $relation = $this->assign($personal, $mine);
        $operation = EppOperacion::query()->create([
            'personal_id' => $personal->id, 'almacen_id' => $warehouse->id, 'mina_id' => $mine->id,
            'tipo' => 'ENTREGA', 'submission_uuid' => (string) Str::uuid(),
        ]);
        $user = $this->user();
        $this->scope($user, $mine);
        foreach ([
            'view' => 'ver_operaciones', 'confirmDelivery' => 'confirmar_entrega',
            'confirmReturn' => 'confirmar_devolucion', 'viewEvidence' => 'ver_evidencia',
            'downloadReceipt' => 'descargar_constancia', 'correct' => 'corregir',
            'cancel' => 'anular', 'approveSignatureException' => 'aprobar_excepcion_firma',
        ] as $gate => $action) {
            $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections([])]);
            $this->assertFalse(Gate::forUser($user)->allows($gate, $operation), $gate.' missing permission');
            $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['epps' => [$action]])]);
            $this->assertTrue(Gate::forUser($user)->allows($gate, $operation), $gate.' allowed');
            $relation->update(['activo' => false]);
            $this->assertFalse(Gate::forUser($user)->allows($gate, $operation), $gate.' inactive relation');
            $relation->update(['activo' => true]);
            $warehouse->update(['mina_id' => $this->mine()->id]);
            $operation->unsetRelation('almacen');
            $this->assertFalse(Gate::forUser($user)->allows($gate, $operation), $gate.' warehouse outside scope');
            $warehouse->update(['mina_id' => $mine->id]);
            $operation->unsetRelation('almacen');
        }
    }

    public function test_policy_preparation_and_inventory_need_their_own_capability(): void
    {
        $mine = $this->mine();
        $warehouse = $this->warehouse($mine);
        $personal = $this->personal();
        $this->assign($personal, $mine);
        $user = $this->user();
        $this->scope($user, $mine);
        $policy = app(EppOperationPolicy::class);
        foreach (['prepareDelivery' => 'preparar_entrega', 'prepareReturn' => 'preparar_devolucion'] as $gate => $action) {
            $this->assertFalse($policy->$gate($user, $warehouse, $personal));
            $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['epps' => [$action]])]);
            $this->assertTrue($policy->$gate($user, $warehouse, $personal));
            $this->assertFalse($policy->$gate($user, $warehouse, $this->personal()));
        }
        foreach (['manageInventory' => 'gestionar_inventario', 'adjustInventory' => 'ajustar_inventario'] as $gate => $action) {
            $this->assertFalse($policy->$gate($user, $warehouse));
            $user->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['epps' => [$action]])]);
            $this->assertTrue($policy->$gate($user, $warehouse));
            $this->assertFalse($policy->$gate($user, $this->warehouse(null)));
        }
    }

    private function user(array $permissions = [], string $roleName = 'EPP_OPERATOR'): Usuario
    {
        $role = Rol::query()->firstOrCreate(['nombre' => $roleName], [
            'id' => (string) Str::uuid(),
            'permisos' => PermissionCatalog::matrixFromSelections($permissions), 'estado' => 'ACTIVO',
        ]);
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections($permissions)]);

        return Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local',
            'password' => 'secret123', 'rol_id' => $role->id, 'estado' => 'ACTIVO',
        ])->load('rol');
    }

    private function mine(): Mina
    {
        return Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'Mina '.Str::uuid(), 'unidad_minera' => 'UM TEST']);
    }

    private function warehouse(?Mina $mine): EppAlmacen
    {
        return EppAlmacen::query()->create(['nombre' => 'Almacen '.Str::uuid(), 'mina_id' => $mine?->id]);
    }

    private function personal(): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'TRABAJADOR AUTORIZACION EPP', 'puesto' => 'OPERARIO',
            'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO',
        ]);
    }

    private function scope(Usuario $user, Mina $mine): void
    {
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'mina_id' => $mine->id]);
    }

    private function assign(Personal $personal, Mina $mine, bool $active = true): PersonalMina
    {
        return PersonalMina::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $personal->id, 'mina_id' => $mine->id,
            'estado' => 'EN_PROCESO', 'activo' => $active,
        ]);
    }
}
