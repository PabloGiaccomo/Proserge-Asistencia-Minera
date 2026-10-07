<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppInventarioConfirmacion;
use App\Models\EppInventarioMovimiento;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class EppInventoryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private EppAlmacen $warehouse;

    private EppRegistro $epp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local',
            'password' => 'test-secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO',
        ])->load('rol');
        $this->warehouse = EppAlmacen::query()->create(['nombre' => 'ALMACEN PRUEBA']);
        $this->epp = app(EppService::class)->storeCatalog(['nombre' => 'GUANTE INVENTARIO', 'requiere_talla' => true, 'tallas' => 'M,L']);
    }

    public function test_legacy_stock_is_not_available_until_variant_is_reconciled(): void
    {
        $this->epp->update(['stock' => 999]);
        $this->assertNull(app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['talla' => 'M']));
        EppExistencia::query()->create(['almacen_id' => $this->warehouse->id, 'epp_id' => $this->epp->id, 'variante_clave' => app(EppInventoryService::class)->variantKey(['talla' => 'M']), 'cantidad_disponible' => 99]);
        $this->assertNull(app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['talla' => 'M']));
    }

    public function test_variant_key_normalizes_case_whitespace_and_attribute_order(): void
    {
        $service = app(EppInventoryService::class);
        $this->assertSame($service->variantKey([' talla ' => ' m ', 'color' => 'azul']), $service->variantKey(['COLOR' => 'AZUL', 'TALLA' => 'M']));
        $this->assertNotSame($service->variantKey(['talla' => 'M']), $service->variantKey(['talla' => 'L']));
    }

    public function test_opening_creates_ledger_and_reconciles_variant(): void
    {
        $moves = app(EppInventoryService::class)->openStock($this->warehouse, [['epp_id' => $this->epp->id, 'cantidad' => 8, 'talla' => 'm']], $this->actor, 'Conteo inicial');
        $this->assertSame(8, app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['TALLA' => 'M']));
        $move = $moves->first();
        $this->assertSame('APERTURA', $move->tipo);
        $this->assertSame(0, $move->saldo_anterior);
        $this->assertSame(8, $move->saldo_posterior);
        $this->assertSame($this->actor->id, $move->actor_usuario_id);
        $this->assertSame(['TALLA' => 'M'], $move->existencia->atributos_json);
        $this->assertSame('Conteo inicial', $move->motivo);
    }

    public function test_zero_opening_is_reconciled_not_unknown(): void
    {
        app(EppInventoryService::class)->openStock($this->warehouse, [$this->line(0)], $this->actor, 'Sin unidades fisicas');
        $this->assertSame(0, app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['talla' => 'M']));
    }

    public function test_opening_cannot_overwrite_a_reconciled_variant(): void
    {
        $service = app(EppInventoryService::class);
        $service->openStock($this->warehouse, [$this->line(8)], $this->actor, 'Conteo inicial');
        try {
            $service->openStock($this->warehouse, [$this->line(10)], $this->actor, 'Segundo conteo');
            $this->fail('La segunda apertura debe rechazarse.');
        } catch (InvalidArgumentException) {
            $this->assertSame(8, $service->available($this->warehouse, $this->epp, ['talla' => 'M']));
            $this->assertSame(1, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
        }
    }

    public function test_failed_opening_batch_rolls_back_earlier_lines(): void
    {
        try {
            app(EppInventoryService::class)->openStock($this->warehouse, [$this->line(8), $this->line(3)], $this->actor, 'Conteo duplicado');
            $this->fail('El lote duplicado debe rechazarse.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, EppExistencia::query()->where('almacen_id', $this->warehouse->id)->count());
            $this->assertSame(0, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
        }
    }

    public function test_adjustment_appends_audited_before_and_after_balances(): void
    {
        $service = app(EppInventoryService::class);
        $opening = $service->openStock($this->warehouse, [$this->line(8)], $this->actor, 'Conteo inicial')->first();
        $move = $service->adjust($this->warehouse, $this->epp, ['talla' => 'M'], -3, $this->actor, 'Diferencia de conteo');
        $this->assertSame('AJUSTE', $move->tipo);
        $this->assertSame(3, $move->cantidad);
        $this->assertSame(8, $move->saldo_anterior);
        $this->assertSame(5, $move->saldo_posterior);
        $this->assertSame(5, $service->available($this->warehouse, $this->epp, ['talla' => 'M']));
        $this->assertSame(8, $opening->refresh()->saldo_posterior);
    }

    public function test_adjustment_rejects_unknown_stock_negative_balance_and_empty_reason(): void
    {
        $service = app(EppInventoryService::class);
        foreach (['unknown', 'negative', 'reason', 'zero'] as $case) {
            if ($case === 'negative') {
                $service->openStock($this->warehouse, [$this->line(2)], $this->actor, 'Conteo inicial');
            }
            try {
                $service->adjust($this->warehouse, $this->epp, ['talla' => 'M'], $case === 'zero' ? 0 : -3, $this->actor, $case === 'reason' ? ' ' : 'Conteo');
                $this->fail('Debe rechazarse: '.$case);
            } catch (InvalidArgumentException) {
                $this->assertSame($case === 'unknown' ? null : 2, $service->available($this->warehouse, $this->epp, ['talla' => 'M']));
            }
        }
    }

    public function test_inventory_service_denies_warehouse_outside_scope(): void
    {
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'INVENTORY '.Str::uuid(), 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['gestionar_inventario']]), 'estado' => 'ACTIVO']);
        $this->actor->update(['rol_id' => $role->id]);
        $this->actor->load('rol');
        $this->expectException(AuthorizationException::class);
        app(EppInventoryService::class)->openStock($this->warehouse, [$this->line(8)], $this->actor, 'Conteo');
    }

    public function test_inventory_preview_is_read_only_and_confirmation_uses_server_payload(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $payload = ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo inicial'];
        $response = $this->post('/epps/inventario/apertura/preview', $payload)->assertOk()->assertSee('Confirmar apertura');
        $this->assertSame(0, EppExistencia::query()->where('almacen_id', $this->warehouse->id)->count());
        $token = session('epp_inventory_preview.token');
        $this->post('/epps/inventario/apertura', ['preview_token' => $token, 'confirmacion' => '1', 'lines' => [$this->line(999)]])->assertRedirect();
        $this->assertSame(8, app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['talla' => 'M']));
        $this->post('/epps/inventario/apertura', ['preview_token' => $token, 'confirmacion' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
    }

    public function test_inventory_http_requires_direct_permission(): void
    {
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'VIEWER '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['logistica' => ['ver', 'ver_logistica_entregas']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id])->postJson('/epps/inventario/apertura/preview', ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo'])->assertForbidden();
    }

    public function test_catalog_http_requires_policy_review_and_preserves_controls_in_edit_form(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $payload = ['nombre' => 'CONSUMIBLE HTTP', 'vida_util_dias' => 30, 'politica_devolucion' => 'CONSUMIBLE', 'control_inventario' => '1', 'permite_entrega_repetida' => '1', 'requiere_serie' => '1', 'es_requerido_cobertura' => '0'];
        $this->post('/epps/catalogo', $payload)->assertSessionHasErrors('politica_revisada');
        $this->assertDatabaseMissing('epp_registro', ['nombre' => 'CONSUMIBLE HTTP']);
        $this->post('/epps/catalogo', $payload + ['politica_revisada' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $epp = EppRegistro::query()->where('nombre', 'CONSUMIBLE HTTP')->firstOrFail();
        $response = $this->get('/logistica?tab=identificacion')->assertOk()->assertSee('He revisado la política de devolución')->assertSee('Inventario por almacén');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $form = "//form[contains(@action, '/epps/catalogo/{$epp->id}')]";
        $this->assertSame(1, $xpath->query($form."//input[@name='politica_revisada']")->length);
        $this->assertSame(1, $xpath->query($form."//select[@name='politica_devolucion']/option[@value='CONSUMIBLE' and @selected]")->length);
        $this->assertSame('CONSUMIBLE', $epp->politica_devolucion);
        $this->assertTrue($epp->permite_entrega_repetida);
    }

    public function test_adjustment_preview_requires_own_permission_and_revalidates_changed_balance(): void
    {
        $service = app(EppInventoryService::class);
        $opening = $service->openStock($this->warehouse, [$this->line(8)], $this->actor, 'Conteo inicial')->first();
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/ajuste/preview', ['existencia_id' => $opening->existencia_id, 'delta' => 3, 'motivo' => 'Diferencia de conteo'])->assertOk()->assertSee('Confirmar ajuste');
        $token = session('epp_inventory_preview.token');
        $service->adjust($this->warehouse, $this->epp, ['talla' => 'M'], 1, $this->actor, 'Otro operador');
        $this->post('/epps/inventario/ajuste', ['preview_token' => $token, 'confirmacion' => '1'])->assertSessionHasErrors('inventario');
        $this->assertNull(EppInventarioConfirmacion::query()->findOrFail($token)->confirmado_at);
        $this->assertNull(EppInventarioConfirmacion::query()->findOrFail($token)->resultado_json);
        $this->assertSame(9, $service->available($this->warehouse, $this->epp, ['talla' => 'M']));
        $this->post('/epps/inventario/ajuste/preview', ['existencia_id' => $opening->existencia_id, 'delta' => -2, 'motivo' => 'Conteo corregido'])->assertOk();
        $this->post('/epps/inventario/ajuste', ['preview_token' => session('epp_inventory_preview.token'), 'confirmacion' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(7, $service->available($this->warehouse, $this->epp, ['talla' => 'M']));
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MANAGER '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['gestionar_inventario']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->postJson('/epps/inventario/ajuste/preview', ['existencia_id' => $opening->existencia_id, 'delta' => 1, 'motivo' => 'Conteo'])->assertForbidden();
    }

    public function test_warehouse_creation_requires_preview_and_rechecks_mine_scope(): void
    {
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA '.Str::uuid(), 'unidad_minera' => 'PRUEBA']);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/almacen', ['nombre' => 'SIN PREVIEW', 'confirmacion' => '1'])->assertSessionHasErrors('preview_token');
        $this->post('/epps/inventario/almacen/preview', ['nombre' => 'ALMACEN NUEVO', 'mina_id' => $mine->id, 'motivo' => 'Nuevo punto de entrega'])->assertOk();
        $this->assertDatabaseMissing('epp_almacenes', ['nombre' => 'ALMACEN NUEVO']);
        $token = session('epp_inventory_preview.token');
        $this->post('/epps/inventario/almacen', ['preview_token' => $token, 'confirmacion' => '1'])->assertRedirect();
        $this->assertDatabaseHas('epp_almacenes', ['nombre' => 'ALMACEN NUEVO', 'mina_id' => $mine->id]);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SCOPED '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['gestionar_inventario']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->postJson('/epps/inventario/almacen/preview', ['nombre' => 'FUERA DE ALCANCE', 'mina_id' => $mine->id, 'motivo' => 'Conteo'])->assertForbidden();
        $this->get('/epps/inventario?almacen_id='.$this->warehouse->id)->assertForbidden();
    }

    public function test_adjustment_replay_after_balance_aba_has_only_one_effect(): void
    {
        $inventory = app(EppInventoryService::class);
        $opening = $inventory->openStock($this->warehouse, [$this->line(8)], $this->actor, 'Conteo inicial')->first();
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/ajuste/preview', ['existencia_id' => $opening->existencia_id, 'delta' => 3, 'motivo' => 'Ajuste una vez'])->assertOk();
        $preview = session('epp_inventory_preview');
        $confirm = ['preview_token' => $preview['token'], 'confirmacion' => '1'];
        $this->post('/epps/inventario/ajuste', $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $inventory->adjust($this->warehouse, $this->epp, ['talla' => 'M'], -3, $this->actor, 'Otro conteo devuelve saldo anterior');
        $this->withSession(['epp_inventory_preview' => $preview])->post('/epps/inventario/ajuste', $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8, $inventory->available($this->warehouse, $this->epp, ['talla' => 'M']));
        $this->assertSame(1, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->where('motivo', 'Ajuste una vez')->count());
        $this->assertSame(3, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
    }

    public function test_warehouse_double_confirmation_returns_same_result_without_duplicate_or_server_error(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/almacen/preview', ['nombre' => 'ALMACEN IDEMPOTENTE', 'motivo' => 'Alta una vez'])->assertOk();
        $preview = session('epp_inventory_preview');
        $confirm = ['preview_token' => $preview['token'], 'confirmacion' => '1'];
        $this->post('/epps/inventario/almacen', $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $this->withSession(['epp_inventory_preview' => $preview])->post('/epps/inventario/almacen', $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, EppAlmacen::query()->where('nombre', 'ALMACEN IDEMPOTENTE')->count());
        $this->assertSame(0, EppInventarioMovimiento::query()->where('almacen_id', $preview['payload']['new_warehouse_id'])->count());
    }

    public function test_confirmation_uses_persisted_preview_even_if_session_payload_is_restored_or_modified(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/apertura/preview', ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo protegido'])->assertOk();
        $preview = session('epp_inventory_preview');
        $token = $preview['token'];
        $preview['payload']['lines'][0]['cantidad'] = 999;
        $this->withSession(['epp_inventory_preview' => $preview])->post('/epps/inventario/apertura', ['preview_token' => $token, 'confirmacion' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8, app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['talla' => 'M']));
    }

    public function test_confirmations_reject_expired_wrong_actor_and_wrong_action_tokens(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $payload = ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo'];
        $this->post('/epps/inventario/apertura/preview', $payload)->assertOk();
        $token = session('epp_inventory_preview.token');
        $this->postJson('/epps/inventario/apertura', ['preview_token' => (string) Str::uuid(), 'confirmacion' => '1'])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->postJson('/epps/inventario/almacen', ['preview_token' => $token, 'confirmacion' => '1'])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $other = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $this->actor->rol_id, 'estado' => 'ACTIVO']);
        $this->withSession(['user_id' => $other->id])->postJson('/epps/inventario/apertura', ['preview_token' => $token, 'confirmacion' => '1'])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->travel(16)->minutes();
        try {
            $this->withSession(['user_id' => $this->actor->id])->postJson('/epps/inventario/apertura', ['preview_token' => $token, 'confirmacion' => '1'])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        } finally {
            $this->travelBack();
        }
        $this->assertSame(0, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
    }

    public function test_opening_http_rejects_product_without_inventory_control(): void
    {
        $this->epp->update(['control_inventario' => false]);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id])->postJson('/epps/inventario/apertura/preview', ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo'])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame(0, EppExistencia::query()->where('almacen_id', $this->warehouse->id)->count());
    }

    public function test_confirmation_replay_still_requires_current_warehouse_scope(): void
    {
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id]);
        $this->post('/epps/inventario/apertura/preview', ['almacen_id' => $this->warehouse->id, 'lines' => [$this->line(8)], 'motivo' => 'Conteo'])->assertOk();
        $token = session('epp_inventory_preview.token');
        $confirm = ['preview_token' => $token, 'confirmacion' => '1'];
        $this->post('/epps/inventario/apertura', $confirm)->assertRedirect()->assertSessionHasNoErrors();
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'REPLAY SCOPED '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['gestionar_inventario']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->postJson('/epps/inventario/apertura', $confirm)->assertForbidden();
        $this->assertSame(1, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
    }

    private function line(int $quantity): array
    {
        return ['epp_id' => $this->epp->id, 'cantidad' => $quantity, 'talla' => 'M'];
    }
}
