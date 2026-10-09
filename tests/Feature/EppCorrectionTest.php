<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppCorrectionService;
use App\Modules\Epps\Services\EppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class EppCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private EppAlmacen $warehouse;

    private EppExistencia $existence;

    private EppOperacion $operation;

    private EppOperacionDetalle $detail;

    private EppEntrega $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO'])->load('rol');
        $worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'CORRECCION EPP', 'puesto' => 'OPERARIO', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->warehouse = EppAlmacen::query()->create(['nombre' => 'ALMACEN CORRECCION '.Str::uuid()]);
        $epp = app(EppService::class)->storeCatalog(['nombre' => 'CASCO CORRECCION '.Str::uuid()]);
        $this->existence = EppExistencia::query()->create(['almacen_id' => $this->warehouse->id, 'epp_id' => $epp->id, 'variante_clave' => hash('sha256', '[]'), 'atributos_json' => [], 'cantidad_disponible' => 5, 'cantidad_reservada' => 0, 'cantidad_cuarentena' => 0, 'conciliado_at' => now()]);
        $this->operation = EppOperacion::query()->create(['personal_id' => $worker->id, 'almacen_id' => $this->warehouse->id, 'tipo' => 'ENTREGA', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(), 'registrado_por_usuario_id' => $this->actor->id, 'confirmado_por_usuario_id' => $this->actor->id, 'firmado_at' => now(), 'version' => 2]);
        $this->detail = $this->operation->detalles()->create(['epp_solicitado_id' => $epp->id, 'epp_id' => $epp->id, 'existencia_id' => $this->existence->id, 'cantidad_solicitada' => 4, 'cantidad_procesada' => 4, 'cantidad_pendiente' => 0, 'estado' => 'ENTREGADO', 'atributos_json' => [], 'variante_clave' => $this->existence->variante_clave, 'politica_devolucion_snapshot' => 'RETORNABLE', 'control_inventario_snapshot' => true, 'snapshot_json' => ['real' => ['nombre' => $epp->nombre]]]);
        $this->delivery = $this->operation->entregas()->create(['id' => (string) Str::uuid(), 'personal_id' => $worker->id, 'epp_id' => $epp->id, 'operacion_detalle_id' => $this->detail->id, 'cantidad' => 4, 'cantidad_original' => 4, 'cantidad_pendiente_devolucion' => 4, 'fecha_entrega' => now()->toDateString(), 'estado' => 'ENTREGADO', 'politica_devolucion_snapshot' => 'RETORNABLE', 'es_legacy' => false, 'estado_integridad' => 'FIRMADO_VALIDO']);
    }

    public function test_signed_delivery_cannot_be_updated_or_deleted(): void
    {
        $session = ['auth_token' => 'test-token', 'user_id' => $this->actor->id];

        $this->withSession($session)->put(route('epps.entregas.update', $this->delivery), ['cantidad' => 9])->assertStatus(409);
        $this->withSession($session)->post(route('epps.entregas.destroy', $this->delivery))->assertStatus(409);
        $this->assertDatabaseHas('epp_entregas', ['id' => $this->delivery->id, 'cantidad' => 4]);
    }

    public function test_correction_is_linked_and_compensates_inventory_and_balance(): void
    {
        $original = $this->operation->fresh()->getAttributes();
        $correction = app(EppCorrectionService::class)->correct($this->operation, [
            'lines' => [['detalle_origen_id' => $this->detail->id, 'cantidad' => 2]],
        ], $this->actor, 'Cantidad registrada por duplicado');

        $this->assertSame('CORRECCION', $correction->tipo);
        $this->assertSame('FIRMADO', $correction->estado);
        $this->assertSame($this->operation->id, $correction->operacion_origen_id);
        $this->assertSame(7, $this->existence->fresh()->cantidad_disponible);
        $this->assertSame(2, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame($original, $this->operation->fresh()->getAttributes());
        $this->assertDatabaseHas('epp_inventario_movimientos', ['operacion_id' => $correction->id, 'tipo' => 'CORRECCION', 'cantidad' => 2]);
        $this->withSession(['auth_token' => 'test-token', 'user_id' => $this->actor->id])->get(route('epps.operations.show', $correction))->assertOk()->assertSee('Compensacion administrativa');
    }

    public function test_annulment_reverses_remaining_effect_once_and_keeps_original_operation(): void
    {
        $annulment = app(EppCorrectionService::class)->annul($this->operation, $this->actor, 'Entrega atribuida al trabajador equivocado');

        $this->assertSame('ANULACION', $annulment->tipo);
        $this->assertSame($this->operation->id, $annulment->operacion_origen_id);
        $this->assertSame('FIRMADO', $this->operation->fresh()->estado);
        $this->assertSame('ANULADO', $this->delivery->fresh()->estado);
        $this->assertSame(0, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(9, $this->existence->fresh()->cantidad_disponible);

        $this->expectException(InvalidArgumentException::class);
        app(EppCorrectionService::class)->annul($this->operation, $this->actor, 'No debe duplicarse');
    }

    public function test_reason_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(EppCorrectionService::class)->annul($this->operation, $this->actor, ' ');
    }

    public function test_legacy_annulment_preserves_original_and_does_not_invent_stock(): void
    {
        $legacy = $this->delivery->replicate(['operacion_id', 'operacion_detalle_id']);
        $legacy->id = (string) Str::uuid();
        $legacy->es_legacy = true;
        $legacy->save();
        $operation = app(EppCorrectionService::class)->compensateLegacy($legacy, $this->warehouse, 4, $this->actor, 'Registro historico duplicado', true);
        $this->assertSame('ANULACION', $operation->tipo);
        $this->assertNull($operation->aceptacion);
        $this->assertSame($legacy->id, $operation->detalles->first()->entrega_origen_id);
        $this->assertSame(0, $legacy->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(4, $legacy->fresh()->cantidad);
        $this->assertSame(5, $this->existence->fresh()->cantidad_disponible);
        $this->assertDatabaseMissing('epp_inventario_movimientos', ['operacion_id' => $operation->id]);
        $this->expectException(InvalidArgumentException::class);
        app(EppCorrectionService::class)->compensateLegacy($legacy, $this->warehouse, 4, $this->actor, 'Reintento no debe duplicar', true);
    }
}
