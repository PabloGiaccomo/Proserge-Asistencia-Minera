<?php

namespace Tests\Feature;

use App\Models\EppAceptacion;
use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\EppReserva;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EppSignedSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public function test_schema_supports_partial_quantities_and_private_evidence(): void
    {
        $this->assertTrue(Schema::hasColumns('epp_operacion_detalles', [
            'cantidad_solicitada', 'cantidad_procesada', 'cantidad_pendiente',
            'politica_devolucion_snapshot', 'snapshot_json',
        ]));
        $this->assertTrue(Schema::hasColumns('epp_aceptaciones', [
            'tipo_firmante', 'firma_path', 'firma_sha256', 'contenido_json',
            'contenido_sha256', 'codigo_verificacion',
        ]));
    }

    public function test_models_persist_partial_operation_and_its_origin_relations(): void
    {
        $epp = EppRegistro::query()->create([
            'id' => (string) Str::uuid(), 'codigo' => 'SIGNED-SCHEMA', 'nombre' => 'Casco schema', 'categoria' => 'EPP',
        ])->fresh();
        $this->assertSame('RETORNABLE', $epp->politica_devolucion);
        $this->assertTrue($epp->control_inventario);
        $this->assertFalse($epp->permite_entrega_repetida);

        $almacen = EppAlmacen::query()->create(['nombre' => 'Almacen schema']);
        $existencia = EppExistencia::query()->create([
            'almacen_id' => $almacen->id, 'epp_id' => $epp->id, 'variante_clave' => 'sin-variante',
        ])->fresh();
        $this->assertNull($existencia->conciliado_at);
        $operacion = $this->operation($almacen);
        $detalle = $operacion->detalles()->create([
            'epp_solicitado_id' => $epp->id, 'epp_id' => $epp->id,
            'cantidad_solicitada' => 10, 'cantidad_procesada' => 5, 'cantidad_pendiente' => 5,
            'politica_devolucion_snapshot' => 'RETORNABLE', 'snapshot_json' => ['nombre' => 'Casco schema'],
        ]);
        $reserva = EppReserva::query()->create([
            'operacion_id' => $operacion->id, 'operacion_detalle_id' => $detalle->id,
            'existencia_id' => $existencia->id, 'cantidad' => 5, 'expires_at' => now()->addMinutes(10),
        ]);
        $entrega = EppEntrega::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $operacion->personal_id, 'epp_id' => $epp->id,
            'operacion_id' => $operacion->id, 'operacion_detalle_id' => $detalle->id,
            'cantidad' => 5, 'cantidad_original' => 5, 'cantidad_pendiente_devolucion' => 5,
            'politica_devolucion_snapshot' => 'RETORNABLE', 'es_legacy' => false,
            'estado_integridad' => 'PENDIENTE_VERIFICACION', 'fecha_entrega' => '2026-10-07',
        ]);
        $aceptacion = $operacion->aceptacion()->create($this->evidence());
        $evento = $operacion->eventos()->create(['accion' => 'PREPARADO', 'origen' => 'WEB']);
        $movimiento = EppInventarioMovimiento::query()->create([
            'existencia_id' => $existencia->id, 'almacen_id' => $almacen->id, 'epp_id' => $epp->id,
            'operacion_id' => $operacion->id, 'operacion_detalle_id' => $detalle->id,
            'tipo' => 'SALIDA', 'cantidad' => 5, 'saldo_anterior' => 10, 'saldo_posterior' => 5,
            'motivo' => 'Entrega firmada',
        ]);

        $this->assertTrue(Str::isUuid($operacion->id));
        $this->assertSame(5, $operacion->detalles()->first()->cantidad_pendiente);
        $this->assertSame(['nombre' => 'Casco schema'], $detalle->fresh()->snapshot_json);
        $this->assertTrue($entrega->operacion->is($operacion));
        $this->assertTrue($entrega->operacionDetalle->is($detalle));
        $this->assertTrue($operacion->entregas()->first()->is($entrega));
        $this->assertTrue($operacion->aceptacion->is($aceptacion));
        $this->assertTrue($operacion->eventos()->first()->is($evento));
        $this->assertTrue($reserva->existencia->is($existencia));
        $this->assertTrue($movimiento->operacion->is($operacion));
        $this->assertSame(['version' => 1], $aceptacion->fresh()->contenido_json);
        $this->assertNotNull($reserva->fresh()->expires_at);
    }

    public function test_operation_submission_uuid_is_unique(): void
    {
        $almacen = EppAlmacen::query()->create(['nombre' => 'Almacen submission']);
        $operacion = $this->operation($almacen);
        $this->expectException(QueryException::class);
        $this->operation($almacen, $operacion->submission_uuid);
    }

    public function test_existence_is_unique_by_warehouse_item_and_variant(): void
    {
        $data = ['almacen_id' => (string) Str::uuid(), 'epp_id' => (string) Str::uuid(), 'variante_clave' => 'M-AZUL'];
        EppExistencia::query()->create($data);
        $this->expectException(QueryException::class);
        EppExistencia::query()->create($data);
    }

    public function test_acceptance_is_unique_per_operation(): void
    {
        $operacion = $this->operation(EppAlmacen::query()->create(['nombre' => 'Almacen firma']));
        $operacion->aceptacion()->create($this->evidence());
        $this->expectException(QueryException::class);
        $operacion->aceptacion()->create($this->evidence());
    }

    public function test_verification_code_is_unique(): void
    {
        $almacen = EppAlmacen::query()->create(['nombre' => 'Almacen codigo']);
        $data = $this->evidence();
        $this->operation($almacen)->aceptacion()->create($data);
        $this->expectException(QueryException::class);
        $this->operation($almacen)->aceptacion()->create($data);
    }

    public function test_both_signer_types_require_signature_image_metadata(): void
    {
        $operacion = $this->operation(EppAlmacen::query()->create(['nombre' => 'Almacen testigo']));
        $data = $this->evidence();
        $data['tipo_firmante'] = 'TESTIGO_AUTORIZADO';
        $aceptacion = $operacion->aceptacion()->create($data);
        $this->assertSame('TESTIGO_AUTORIZADO', $aceptacion->fresh()->tipo_firmante);
        $data['operacion_id'] = (string) Str::uuid();
        $data['codigo_verificacion'] = Str::random(24);
        $data['firma_path'] = null;
        $this->expectException(QueryException::class);
        EppAceptacion::query()->create($data);
    }

    public function test_worker_acceptance_also_requires_signature_image_metadata(): void
    {
        $data = $this->evidence();
        $data['operacion_id'] = (string) Str::uuid();
        $data['firma_path'] = null;
        $this->expectException(QueryException::class);
        EppAceptacion::query()->create($data);
    }

    public function test_acceptance_rejects_unknown_signer_type(): void
    {
        $data = $this->evidence();
        $data['operacion_id'] = (string) Str::uuid();
        $data['tipo_firmante'] = 'OPERADOR';
        $this->expectException(QueryException::class);
        EppAceptacion::query()->create($data);
    }

    public function test_inventory_ledger_cannot_be_updated(): void
    {
        $movimiento = $this->movement();
        $this->expectException(\LogicException::class);
        $movimiento->update(['cantidad' => 99]);
    }

    public function test_inventory_ledger_cannot_be_deleted(): void
    {
        $movimiento = $this->movement();
        $this->expectException(\LogicException::class);
        $movimiento->delete();
    }

    #[DataProvider('ledgerMutationMethods')]
    public function test_inventory_ledger_rejects_quiet_and_bulk_mutations(string $method): void
    {
        $movimiento = $this->movement();
        $query = EppInventarioMovimiento::query()->whereKey($movimiento->id);
        try {
            match ($method) {
                'saveQuietly' => $movimiento->forceFill(['cantidad' => 99])->saveQuietly(),
                'updateQuietly' => $movimiento->updateQuietly(['cantidad' => 99]),
                'deleteQuietly' => $movimiento->deleteQuietly(),
                'withoutEvents' => EppInventarioMovimiento::withoutEvents(fn () => $movimiento->update(['cantidad' => 99])),
                'bulkUpdate' => $query->update(['cantidad' => 99]),
                'bulkDelete' => $query->delete(),
                'forceDelete' => $query->forceDelete(),
                'increment' => $query->increment('cantidad'),
                'decrement' => $query->decrement('cantidad'),
                'incrementEach' => $query->incrementEach(['cantidad' => 1]),
                'decrementEach' => $query->decrementEach(['cantidad' => 1]),
                'incrementQuietly' => $movimiento->incrementQuietly('cantidad'),
                'decrementQuietly' => $movimiento->decrementQuietly('cantidad'),
                'updateOrCreate' => EppInventarioMovimiento::query()->updateOrCreate(['id' => $movimiento->id], ['cantidad' => 99]),
                'updateOrInsert' => $query->updateOrInsert(['id' => $movimiento->id], ['cantidad' => 99]),
                'upsert' => $query->upsert([array_replace($movimiento->getAttributes(), ['cantidad' => 99])], ['id'], ['cantidad']),
                'truncate' => DB::connection()->pretend(fn () => $query->truncate()),
            };
        } catch (\LogicException) {
            $this->assertDatabaseHas('epp_inventario_movimientos', [
                'id' => $movimiento->id, 'cantidad' => 10, 'saldo_anterior' => 0, 'saldo_posterior' => 10,
            ]);

            return;
        }

        $this->fail("La via {$method} debe rechazar modificaciones del libro.");
    }

    public static function ledgerMutationMethods(): array
    {
        return array_map(fn (string $method): array => [$method], [
            'saveQuietly', 'updateQuietly', 'deleteQuietly', 'withoutEvents',
            'bulkUpdate', 'bulkDelete', 'forceDelete', 'increment', 'decrement',
            'incrementEach', 'decrementEach', 'incrementQuietly', 'decrementQuietly',
            'updateOrCreate', 'updateOrInsert', 'upsert', 'truncate',
        ]);
    }

    public function test_acceptance_preserves_canonical_json_bytes_without_double_encoding(): void
    {
        $canonical = '{"declaracion":"Sí / acepto","cantidad":5,"precio":1.0}';
        $data = $this->evidence();
        $data['operacion_id'] = (string) Str::uuid();
        $data['contenido_json'] = $canonical;
        $data['contenido_sha256'] = hash('sha256', $canonical);
        $aceptacion = EppAceptacion::query()->create($data)->fresh();

        $this->assertSame($canonical, $aceptacion->getRawOriginal('contenido_json'));
        $this->assertSame(hash('sha256', $aceptacion->getRawOriginal('contenido_json')), $aceptacion->contenido_sha256);
        $this->assertSame(['declaracion' => 'Sí / acepto', 'cantidad' => 5, 'precio' => 1.0], $aceptacion->contenido_json);
    }

    public function test_acceptance_array_input_uses_fixed_json_serialization(): void
    {
        $aceptacion = new EppAceptacion;
        $aceptacion->contenido_json = ['declaracion' => 'Sí / acepto', 'precio' => 1.0];
        $this->assertSame('{"declaracion":"Sí / acepto","precio":1.0}', $aceptacion->getAttributes()['contenido_json']);
    }

    public function test_acceptance_rejects_invalid_canonical_json(): void
    {
        $this->expectException(\JsonException::class);
        $aceptacion = new EppAceptacion;
        $aceptacion->contenido_json = '{invalid';
    }

    public function test_acceptance_rejects_scalar_json_content(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $aceptacion = new EppAceptacion;
        $aceptacion->contenido_json = '"text"';
    }

    public function test_migrations_are_repeatable_without_overwriting_existing_catalog_policy(): void
    {
        $epp = EppRegistro::query()->create([
            'id' => (string) Str::uuid(), 'codigo' => 'SIGNED-REPEAT', 'nombre' => 'Consumible schema',
            'categoria' => 'EPP', 'politica_devolucion' => 'CONSUMIBLE',
        ]);
        foreach (glob(database_path('migrations/2026_10_07_*.php')) as $path) {
            $migration = require $path;
            $migration->up();
            $migration->up();
        }
        $this->assertSame('CONSUMIBLE', $epp->fresh()->politica_devolucion);
        $this->assertTrue(Schema::hasTable('epp_operacion_eventos'));
    }

    private function movement(): EppInventarioMovimiento
    {
        return EppInventarioMovimiento::query()->create([
            'existencia_id' => (string) Str::uuid(), 'almacen_id' => (string) Str::uuid(),
            'epp_id' => (string) Str::uuid(), 'tipo' => 'APERTURA',
            'cantidad' => 10, 'saldo_anterior' => 0, 'saldo_posterior' => 10, 'motivo' => 'Conteo de apertura',
        ]);
    }

    private function operation(EppAlmacen $almacen, ?string $submission = null): EppOperacion
    {
        return EppOperacion::query()->create([
            'personal_id' => (string) Str::uuid(), 'almacen_id' => $almacen->id,
            'tipo' => 'ENTREGA', 'submission_uuid' => $submission ?? (string) Str::uuid(),
        ]);
    }

    private function evidence(): array
    {
        return [
            'tipo_firmante' => 'TRABAJADOR', 'personal_id' => (string) Str::uuid(),
            'firma_path' => 'epp/firmas/test.png', 'firma_sha256' => str_repeat('a', 64),
            'contenido_json' => ['version' => 1], 'contenido_sha256' => str_repeat('b', 64),
            'codigo_verificacion' => Str::random(24), 'firmado_at' => now(), 'firmado_at_utc' => now()->utc(),
            'declaracion_version' => '1', 'submission_uuid' => (string) Str::uuid(),
        ];
    }
}
