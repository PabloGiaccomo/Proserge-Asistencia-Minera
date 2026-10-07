<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Epps\Services\EppService;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EppOperationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private Personal $personal;

    private EppAlmacen $warehouse;

    private EppRegistro $epp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO'])->load('rol');
        $this->personal = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'PRUEBA OPERACION EPP', 'puesto' => 'OPERARIO', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->warehouse = EppAlmacen::query()->create(['nombre' => 'PRUEBA RESERVAS '.Str::uuid()]);
        $this->epp = app(EppService::class)->storeCatalog(['nombre' => 'GUANTE RESERVA', 'requiere_talla' => true, 'tallas' => 'M,L']);
    }

    public function test_ten_requested_with_five_available_prepares_only_five(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(10) + ['aceptar_parcial' => true, 'cantidad_procesada' => 999]]);
        $detail = $prepared->detalles->first();
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->estado);
        $this->assertSame(5, $detail->cantidad_procesada);
        $this->assertSame(5, $detail->cantidad_pendiente);
        $this->assertSame('RESERVADO', $detail->estado);
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(5, $this->existence()->cantidad_reservada);
        $this->assertSame(2, $this->ledgerCount());
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($prepared->reserva_expires_at), 2);
        $this->assertSame(0, $prepared->entregas()->count());
    }

    public function test_zero_stock_never_reaches_signature(): void
    {
        $this->open(0);
        $prepared = $this->prepare([$this->line(2)]);
        $this->assertSame('PENDIENTE_STOCK', $prepared->estado);
        $this->assertSame('NO_DISPONIBLE', $prepared->detalles->first()->estado);
        $this->assertSame(0, $prepared->detalles->first()->cantidad_procesada);
        $this->assertSame(2, $prepared->detalles->first()->cantidad_pendiente);
        $this->assertSame(0, $prepared->reservas()->count());
        $this->assertSame(1, $this->ledgerCount());
    }

    public function test_unreconciled_stock_is_distinguished_from_zero(): void
    {
        $this->epp->update(['stock' => 999]);
        $prepared = $this->prepare([$this->line(1)]);
        $this->assertSame('PENDIENTE_STOCK', $prepared->estado);
        $this->assertSame('STOCK_NO_CONCILIADO', $prepared->detalles->first()->snapshot_json['disponibilidad']);
        $this->assertSame(0, $this->ledgerCount());
    }

    public function test_partial_requires_explicit_acceptance(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(10)]);
        $this->assertSame('PENDIENTE_STOCK', $prepared->estado);
        $this->assertSame(0, $prepared->detalles->first()->cantidad_procesada);
        $this->assertSame(10, $prepared->detalles->first()->cantidad_pendiente);
        $this->assertSame(5, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->ledgerCount());
    }

    public function test_rejecting_all_lines_releases_reservations(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(5)]);
        $service = app(EppOperationService::class);
        $rejected = $service->reject($prepared, $this->actor, 'El trabajador no recibe los guantes');
        $this->assertSame('RECHAZADO', $rejected->estado);
        $this->assertSame('RECHAZADO', $rejected->detalles->first()->estado);
        $this->assertSame(0, $rejected->detalles->first()->cantidad_procesada);
        $this->assertSame(5, $rejected->detalles->first()->cantidad_pendiente);
        $this->assertSame(5, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame('LIBERADA', $rejected->reservas->first()->estado);
        $this->assertSame(3, $this->ledgerCount());
        $this->assertSame(1, $rejected->eventos()->where('accion', 'FIRMA_RECHAZADA')->count());
        $service->reject($rejected, $this->actor, 'Reintento');
        $this->assertSame(3, $this->ledgerCount());
    }

    public function test_all_rejected_draft_lines_become_rejected_without_signature(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(2) + ['rechazar' => true, 'motivo' => 'No corresponde']]);
        $this->assertSame('RECHAZADO', $prepared->estado);
        $this->assertSame(0, $prepared->reservas()->count());
        $this->assertSame(1, $this->ledgerCount());
        $this->assertSame(0, $prepared->eventos()->where('accion', 'FIRMA_RECHAZADA')->count());
    }

    public function test_review_excludes_rejected_lines_from_processed_list(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(1), array_replace($this->line(2), ['talla' => 'L', 'rechazar' => true, 'motivo' => 'No recibo talla L'])]);
        $payload = app(EppOperationService::class)->reviewPayload($prepared);
        $this->assertCount(1, $payload['details']);
        $this->assertSame(1, $payload['details'][0]['processed_quantity']);
        $this->assertCount(1, $payload['rejected']);
        $this->assertSame('No recibo talla L', $payload['rejected'][0]['reason']);
        $this->assertSame(2, $this->ledgerCount());
    }

    public function test_substitution_preserves_requested_and_actual_snapshots(): void
    {
        $this->open(5);
        $requested = app(EppService::class)->storeCatalog(['nombre' => 'GUANTE SOLICITADO', 'precio_unitario' => 12]);
        $prepared = $this->prepare([['epp_id' => $requested->id, 'epp_real_id' => $this->epp->id, 'talla' => 'M', 'cantidad_solicitada' => 2, 'aceptar_sustitucion' => true, 'motivo' => 'Alternativa aprobada por trabajador']]);
        $detail = $prepared->detalles->first();
        $requested->update(['nombre' => 'NOMBRE NUEVO']);
        $this->assertSame($requested->id, $detail->epp_solicitado_id);
        $this->assertSame($this->epp->id, $detail->epp_id);
        $this->assertSame('GUANTE SOLICITADO', $detail->snapshot_json['solicitado']['nombre']);
        $this->assertSame('GUANTE RESERVA', $detail->snapshot_json['real']['nombre']);
        $this->assertSame('M', $detail->atributos_json['TALLA']);
        $this->assertSame(2, $detail->cantidad_procesada);
        $this->assertSame(2, $this->ledgerCount());
    }

    public function test_substitution_requires_acceptance_and_reason(): void
    {
        $requested = app(EppService::class)->storeCatalog(['nombre' => 'SOLICITADO']);
        $this->expectException(InvalidArgumentException::class);
        $this->prepare([['epp_id' => $requested->id, 'epp_real_id' => $this->epp->id, 'talla' => 'M', 'cantidad_solicitada' => 1]]);
    }

    public function test_active_returnable_requires_reason(): void
    {
        $this->open(5);
        $this->existingDelivery();
        $draft = $this->draft();
        $service = app(EppOperationService::class);
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->prepareForSignature($service->syncDetails($draft, [$this->line(1)], $this->actor), $this->actor);
        } finally {
            $this->assertSame('BORRADOR', $draft->fresh()->estado);
            $this->assertSame(5, $this->existence()->cantidad_disponible);
            $this->assertSame(1, $this->ledgerCount());
        }
    }

    public function test_active_returnable_with_reason_is_allowed_and_preserved(): void
    {
        $this->open(5);
        $this->existingDelivery();
        $prepared = $this->prepare([$this->line(1) + ['motivo' => 'Reposicion por desgaste']]);
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->estado);
        $this->assertSame('Reposicion por desgaste', $prepared->detalles->first()->motivo);
        $this->assertSame(2, $this->ledgerCount());
    }

    public function test_consumable_can_repeat(): void
    {
        $this->open(5);
        $this->epp->update(['politica_devolucion' => 'CONSUMIBLE', 'permite_entrega_repetida' => true]);
        $this->existingDelivery();
        $prepared = $this->prepare([$this->line(1), $this->line(2)]);
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->estado);
        $this->assertSame(3, $prepared->detalles->sum('cantidad_procesada'));
        $this->assertSame(2, $prepared->reservas()->count());
        $this->assertSame(2, $this->existence()->cantidad_disponible);
        $this->assertSame(3, $this->ledgerCount());
    }

    public function test_duplicate_returnable_variant_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->prepare([$this->line(1), $this->line(1)]);
    }

    public function test_uncontrolled_item_skips_reservation(): void
    {
        $this->epp->update(['control_inventario' => false]);
        $prepared = $this->prepare([$this->line(3)]);
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->estado);
        $this->assertSame(3, $prepared->detalles->first()->cantidad_procesada);
        $this->assertSame(0, $prepared->detalles->first()->cantidad_pendiente);
        $this->assertFalse($prepared->detalles->first()->control_inventario_snapshot);
        $this->assertNull($prepared->detalles->first()->existencia_id);
        $this->assertSame(0, $prepared->reservas()->count());
        $this->assertSame(0, $this->ledgerCount());
    }

    public function test_expired_reservation_releases_once(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(3)]);
        $service = app(EppOperationService::class);
        // Cleanup is global; unrelated committed concurrency fixtures may expire first.
        $service->expireReservations($prepared->reserva_expires_at->copy()->subSecond());
        $this->assertSame('ACTIVA', $prepared->reservas()->first()->estado);
        $this->assertSame(1, $service->expireReservations($prepared->reserva_expires_at));
        $this->assertSame(0, $service->expireReservations($prepared->reserva_expires_at->copy()->addMinute()));
        $this->assertSame('EXPIRADO', $prepared->fresh()->estado);
        $this->assertSame('EXPIRADA', $prepared->reservas()->first()->estado);
        $this->assertSame(5, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame(3, $this->ledgerCount());
    }

    public function test_inventory_read_lazily_releases_expired_reservations(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(3)]);
        $this->travel(11)->minutes();
        try {
            $this->assertSame(5, app(EppInventoryService::class)->available($this->warehouse, $this->epp, ['TALLA' => 'M']));
            $this->assertSame('EXPIRADO', $prepared->fresh()->estado);
            $this->assertSame(3, $this->ledgerCount());
        } finally {
            $this->travelBack();
        }
    }

    public function test_preparation_retry_does_not_double_reserve_and_ready_is_not_editable(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(2)]);
        $service = app(EppOperationService::class);
        $this->assertSame($prepared->id, $service->prepareForSignature($prepared, $this->actor)->id);
        $this->assertSame(2, $this->ledgerCount());
        $this->assertSame(1, $prepared->reservas()->count());
        $this->expectException(InvalidArgumentException::class);
        $service->syncDetails($prepared, [$this->line(3)], $this->actor);
    }

    public function test_submission_uuid_is_idempotent_but_cannot_change_warehouse(): void
    {
        $draft = $this->draft();
        $service = app(EppOperationService::class);
        $this->assertSame($draft->id, $service->createDraft($this->actor, $this->personal, $this->warehouse, 'ENTREGA', $draft->submission_uuid)->id);
        $otherWarehouse = EppAlmacen::query()->create(['nombre' => 'OTRO ALMACEN']);
        $this->expectException(InvalidArgumentException::class);
        $service->createDraft($this->actor, $this->personal, $otherWarehouse, 'ENTREGA', $draft->submission_uuid);
    }

    public function test_sync_rolls_back_invalid_line_and_does_not_trust_existence_reference(): void
    {
        $draft = $this->draft();
        $service = app(EppOperationService::class);
        $service->syncDetails($draft, [$this->line(2)], $this->actor);
        $this->open(5);
        $other = EppAlmacen::query()->create(['nombre' => 'OTRO']);
        $opening = app(EppInventoryService::class)->openStock($other, [$this->line(4) + ['cantidad' => 4]], $this->actor, 'Conteo prueba')->first();
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->syncDetails($draft, [$this->line(1) + ['existencia_id' => $opening->existencia_id]], $this->actor);
        } finally {
            $this->assertSame(2, $draft->detalles()->first()->cantidad_solicitada);
            $this->assertSame(1, $this->ledgerCount());
        }
    }

    public function test_return_rejects_delivery_from_another_worker(): void
    {
        $delivery = $this->existingDelivery();
        $delivery->update(['personal_id' => (string) Str::uuid()]);
        $draft = app(EppOperationService::class)->createDraft($this->actor, $this->personal, $this->warehouse, 'DEVOLUCION', (string) Str::uuid());
        $this->expectException(InvalidArgumentException::class);
        app(EppOperationService::class)->syncDetails($draft, [$this->line(1) + ['entrega_origen_id' => $delivery->id]], $this->actor);
    }

    public function test_deactivated_catalog_is_rechecked_before_reserving(): void
    {
        $this->open(5);
        $draft = app(EppOperationService::class)->syncDetails($this->draft(), [$this->line(1)], $this->actor);
        $this->epp->update(['estado' => 'INACTIVO']);
        $this->expectException(InvalidArgumentException::class);
        app(EppOperationService::class)->prepareForSignature($draft, $this->actor);
    }

    public function test_preparation_rechecks_current_permission_and_scope(): void
    {
        $draft = app(EppOperationService::class)->syncDetails($this->draft(), [$this->line(1)], $this->actor);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SCOPED '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['preparar_entrega']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->expectException(AuthorizationException::class);
        app(EppOperationService::class)->prepareForSignature($draft, $this->actor);
    }

    public function test_preparation_rechecks_repeat_policy_after_catalog_change(): void
    {
        $this->open(5);
        $this->epp->update(['politica_devolucion' => 'CONSUMIBLE', 'permite_entrega_repetida' => true]);
        $service = app(EppOperationService::class);
        $draft = $service->syncDetails($this->draft(), [$this->line(1), $this->line(1)], $this->actor);
        $this->epp->update(['permite_entrega_repetida' => false]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->prepareForSignature($draft, $this->actor);
        } finally {
            $this->assertSame(5, $this->existence()->cantidad_disponible);
            $this->assertSame(1, $this->ledgerCount());
        }
    }

    public function test_return_rejects_variant_different_from_delivery_snapshot(): void
    {
        $delivery = $this->existingDelivery();
        $delivery->update(['talla' => 'L', 'atributos_json' => ['TALLA' => 'L']]);
        $service = app(EppOperationService::class);
        $draft = $service->createDraft($this->actor, $this->personal, $this->warehouse, 'DEVOLUCION', (string) Str::uuid());
        $this->expectException(InvalidArgumentException::class);
        $service->syncDetails($draft, [$this->line(1) + ['entrega_origen_id' => $delivery->id]], $this->actor);
    }

    public function test_followup_line_cannot_request_more_than_signed_pending_quantity(): void
    {
        $this->open(1);
        $service = app(EppOperationService::class);
        $source = $this->prepare([$this->line(3) + ['aceptar_parcial' => true]]);
        // A prior signed batch has 2 pending units; a followup must not duplicate 3.
        $source->update(['estado' => 'FIRMADO_PARCIAL']);
        $this->expectException(InvalidArgumentException::class);
        $service->syncDetails($this->draft(), [$this->line(3) + ['detalle_origen_id' => $source->detalles->first()->id]], $this->actor);
    }

    public function test_returnable_with_repeating_policy_still_requires_reason_for_active_balance(): void
    {
        $this->open(5);
        $this->epp->update(['permite_entrega_repetida' => true]);
        $this->existingDelivery();
        $this->expectException(InvalidArgumentException::class);
        $this->prepare([$this->line(1)]);
    }

    public function test_frozen_summary_hash_survives_mysql_json_key_reordering(): void
    {
        $this->open(3);
        $prepared = $this->prepare([$this->line(1)]);
        $service = app(EppOperationService::class);
        $this->assertSame($prepared->resumen_sha256, hash('sha256', json_encode($service->reviewPayload($prepared->fresh()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        $this->personal->update(['nombre_completo' => 'OTRO NOMBRE']);
        $this->epp->update(['nombre' => 'OTRO EPP']);
        $this->assertSame('PRUEBA OPERACION EPP', $service->reviewPayload($prepared)['worker']['name']);
        $this->assertSame('GUANTE RESERVA', $service->reviewPayload($prepared)['details'][0]['snapshot']['real']['nombre']);
    }

    public function test_review_uses_the_canonical_evidence_contract(): void
    {
        $this->open(5);
        $prepared = $this->prepare([$this->line(10) + ['aceptar_parcial' => true]]);
        $payload = app(EppOperationService::class)->reviewPayload($prepared);
        $this->assertArrayHasKey('details', $payload);
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame(5, $payload['details'][0]['processed_quantity']);
        $this->assertSame(10, $payload['details'][0]['requested_quantity']);
        $this->assertSame(5, $payload['details'][0]['pending_quantity']);
        $this->assertSame('DELIVERY_V1', $payload['declaration_version']);
    }

    public function test_canonical_payload_sorts_nested_keys_and_preserves_list_order(): void
    {
        $this->assertSame('{"a":{"x":1,"y":2},"z":[{"b":2,"c":3},{"a":1}]}', EppCanonicalPayload::json(['z' => [['c' => 3, 'b' => 2], ['a' => 1]], 'a' => ['y' => 2, 'x' => 1]]));
    }

    public function test_adjustment_cannot_fill_available_uint32_limit_while_one_unit_is_reserved(): void
    {
        $prepared = $this->reserveAtUnsignedLimit();
        $inventory = app(EppInventoryService::class);
        try {
            $inventory->adjust($this->warehouse, $this->epp, ['TALLA' => 'M'], 1, $this->actor, 'Ajuste invalido con reserva');
            $this->fail('El ajuste no debe impedir la futura liberacion de la reserva.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('reserv', $exception->getMessage());
        }
        $this->assertSame(4294967294, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->existence()->cantidad_reservada);
        $this->assertSame('ACTIVA', $prepared->reservas()->first()->estado);
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->fresh()->estado);
        $this->assertSame(2, $this->ledgerCount());
        app(EppOperationService::class)->expireReservations($prepared->reserva_expires_at);
        $this->assertSame(4294967295, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame('EXPIRADO', $prepared->fresh()->estado);
        $this->assertSame(3, $this->ledgerCount());
    }

    public function test_rejected_overflow_adjustment_does_not_prevent_rejecting_the_reserved_batch(): void
    {
        $prepared = $this->reserveAtUnsignedLimit();
        try {
            app(EppInventoryService::class)->adjust($this->warehouse, $this->epp, ['TALLA' => 'M'], 1, $this->actor, 'Ajuste invalido con reserva');
            $this->fail('El ajuste debe rechazar el saldo agregado fuera de rango.');
        } catch (InvalidArgumentException) {
            $this->assertSame(2, $this->ledgerCount());
        }
        app(EppOperationService::class)->reject($prepared, $this->actor, 'No recibe el EPP');
        $this->assertSame(4294967295, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame('RECHAZADO', $prepared->fresh()->estado);
        $this->assertSame('LIBERADA', $prepared->reservas()->first()->estado);
        $this->assertSame(3, $this->ledgerCount());
    }

    public function test_corrupt_overflow_reservation_is_diagnosed_without_blocking_other_expired_batches(): void
    {
        $blocked = $this->reserveAtUnsignedLimit();
        // Simulate a historical invalid adjustment: available is full while one unit remains reserved.
        $this->existence()->update(['cantidad_disponible' => 4294967295]);
        $otherWarehouse = EppAlmacen::query()->create(['nombre' => 'OTRA RESERVA EXPIRADA']);
        $inventory = app(EppInventoryService::class);
        $inventory->openStock($otherWarehouse, [['epp_id' => $this->epp->id, 'cantidad' => 1, 'talla' => 'M']], $this->actor, 'Conteo otro almacen');
        $service = app(EppOperationService::class);
        $other = $service->createDraft($this->actor, $this->personal, $otherWarehouse, 'ENTREGA', (string) Str::uuid());
        $other = $service->prepareForSignature($service->syncDetails($other, [$this->line(1)], $this->actor), $this->actor);
        $cutoff = now()->addMinutes(11);
        $service->expireReservations($cutoff);
        $this->assertSame('EXPIRADO', $other->fresh()->estado);
        $this->assertSame('EXPIRADA', $other->reservas()->first()->estado);
        $this->assertSame(1, EppExistencia::query()->where('almacen_id', $otherWarehouse->id)->firstOrFail()->cantidad_disponible);
        $this->assertSame('LISTO_PARA_FIRMA', $blocked->fresh()->estado);
        $this->assertSame('ACTIVA', $blocked->reservas()->first()->estado);
        $this->assertSame(4294967295, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->existence()->cantidad_reservada);
        $this->assertSame(2, $this->ledgerCount());
        $diagnostic = $blocked->eventos()->where('accion', 'RESERVA_LIBERACION_BLOQUEADA')->firstOrFail();
        $this->assertSame($this->existence()->id, $diagnostic->snapshot_json['existencia_id']);
        $this->assertStringContainsString('rango', $diagnostic->motivo);
        // A subsequent run keeps the corrupt batch intact and cannot append a release twice elsewhere.
        $service->expireReservations($cutoff);
        $this->assertSame(3, EppInventarioMovimiento::query()->where('almacen_id', $otherWarehouse->id)->count());
        $this->assertSame(2, $this->ledgerCount());
    }

    public function test_quarantine_uses_its_own_uint32_bucket_and_does_not_prevent_valid_release(): void
    {
        $prepared = $this->reserveAtUnsignedLimit();
        $this->existence()->update(['cantidad_disponible' => 4294967293, 'cantidad_cuarentena' => 4294967295]);
        app(EppInventoryService::class)->adjust($this->warehouse, $this->epp, ['TALLA' => 'M'], 1, $this->actor, 'Ajuste valido al limite disponible mas reserva');
        app(EppOperationService::class)->reject($prepared, $this->actor, 'No recibe el EPP');
        $this->assertSame(4294967295, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame(4294967295, $this->existence()->cantidad_cuarentena);
        $this->assertSame(4, $this->ledgerCount());
    }

    public function test_only_one_operator_reserves_last_unit(): void
    {
        $this->open(1);
        $service = app(EppOperationService::class);
        $otherActor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $this->actor->rol_id, 'estado' => 'ACTIVO']);
        $otherDraft = $service->createDraft($otherActor, $this->personal, $this->warehouse, 'ENTREGA', (string) Str::uuid());
        $drafts = [$service->syncDetails($this->draft(), [$this->line(1)], $this->actor), $service->syncDetails($otherDraft, [$this->line(1)], $otherActor)];
        $actors = [$this->actor, $otherActor];
        // Real independent connections require committed fixtures in the isolated test DB.
        // Keep their append-only ledger intact; all assertions use this unique warehouse.
        DB::commit();
        $processes = [];
        $readyPaths = [];
        try {
            DB::beginTransaction();
            EppAlmacen::query()->whereKey($this->warehouse->id)->lockForUpdate()->firstOrFail();
            foreach ($drafts as $i => $draft) {
                $readyPaths[$i] = storage_path('app/testing/epp-operation-'.$draft->id.'.ready');
                $process = new Process([PHP_BINARY, base_path('tests/Support/prepare-epp-operation.php'), $draft->id, $actors[$i]->id, $readyPaths[$i]], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')]);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $deadline) {
                usleep(10000);
            }
            foreach ($readyPaths as $path) {
                $this->assertFileExists($path);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Ambos operadores deben esperar el bloqueo de almacen.');
            }
            DB::commit();
            $states = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $states[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['estado'];
            }
            sort($states);
            $this->assertSame(['LISTO_PARA_FIRMA', 'PENDIENTE_STOCK'], $states);
            $this->assertSame(0, $this->existence()->cantidad_disponible);
            $this->assertSame(1, $this->existence()->cantidad_reservada);
            $this->assertSame(2, $this->ledgerCount());
            foreach ($drafts as $i => $draft) {
                if ($draft->fresh()->estado === 'LISTO_PARA_FIRMA') {
                    $service->reject($draft, $actors[$i], 'Limpieza de reserva de prueba concurrente');
                }
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($readyPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->retireConcurrencyFixtures($otherActor);
            DB::beginTransaction();
        }
    }

    public function test_two_mysql_processes_release_same_expired_reservation_once(): void
    {
        $this->open(1);
        $prepared = $this->prepare([$this->line(1)]);
        $prepared->update(['reserva_expires_at' => now()->subMinute()]);
        $prepared->reservas()->first()->update(['expires_at' => now()->subMinute()]);
        DB::commit();
        $processes = [];
        $readyPaths = [];
        try {
            DB::beginTransaction();
            EppOperacion::query()->whereKey($prepared->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $readyPaths[$i] = storage_path('app/testing/epp-expire-'.$prepared->id.'-'.$i.'.ready');
                $process = new Process([PHP_BINARY, base_path('tests/Support/prepare-epp-operation.php'), $prepared->id, $this->actor->id, $readyPaths[$i], 'expire'], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')]);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $deadline) {
                usleep(10000);
            }
            foreach ($readyPaths as $path) {
                $this->assertFileExists($path);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Ambas limpiezas deben esperar el bloqueo de operacion.');
            }
            DB::commit();
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame('EXPIRADO', $prepared->fresh()->estado);
            $this->assertSame(1, $this->existence()->cantidad_disponible);
            $this->assertSame(0, $this->existence()->cantidad_reservada);
            $this->assertSame(3, $this->ledgerCount());
            $this->assertSame(1, EppInventarioMovimiento::query()->where('operacion_id', $prepared->id)->where('tipo', 'LIBERACION')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($readyPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->retireConcurrencyFixtures();
            DB::beginTransaction();
        }
    }

    private function draft(): EppOperacion
    {
        return app(EppOperationService::class)->createDraft($this->actor, $this->personal, $this->warehouse, 'ENTREGA', (string) Str::uuid());
    }

    private function retireConcurrencyFixtures(?Usuario $otherActor = null): void
    {
        // Preserve audit references without polluting global counts of active business data.
        $this->personal->update(['estado' => 'CESADO']);
        $this->warehouse->update(['estado' => 'INACTIVO']);
        $this->epp->update(['estado' => 'INACTIVO']);
        $this->actor->update(['estado' => 'INACTIVO']);
        $otherActor?->update(['estado' => 'INACTIVO']);
    }

    private function prepare(array $lines): EppOperacion
    {
        $service = app(EppOperationService::class);

        return $service->prepareForSignature($service->syncDetails($this->draft(), $lines, $this->actor), $this->actor);
    }

    private function line(int $quantity): array
    {
        return ['epp_id' => $this->epp->id, 'cantidad_solicitada' => $quantity, 'talla' => 'M'];
    }

    private function open(int $quantity): void
    {
        app(EppInventoryService::class)->openStock($this->warehouse, [['epp_id' => $this->epp->id, 'cantidad' => $quantity, 'talla' => 'M']], $this->actor, 'Conteo prueba');
    }

    private function reserveAtUnsignedLimit(): EppOperacion
    {
        $this->open(1);
        // Legal historical balance reached through accumulated positive adjustments.
        $this->existence()->update(['cantidad_disponible' => 4294967295]);

        return $this->prepare([$this->line(1)]);
    }

    private function existence(): EppExistencia
    {
        return EppExistencia::query()->where('almacen_id', $this->warehouse->id)->where('epp_id', $this->epp->id)->firstOrFail();
    }

    private function ledgerCount(): int
    {
        return EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count();
    }

    private function existingDelivery(): EppEntrega
    {
        return EppEntrega::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->personal->id, 'epp_id' => $this->epp->id, 'cantidad' => 1, 'cantidad_original' => 1, 'cantidad_pendiente_devolucion' => 1, 'politica_devolucion_snapshot' => 'RETORNABLE', 'fecha_entrega' => now(), 'vida_util_dias_snapshot' => 30, 'fecha_vencimiento_calendario' => now()->addDays(30), 'estado' => 'ENTREGADO']);
    }
}
