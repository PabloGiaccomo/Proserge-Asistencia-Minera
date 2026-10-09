<?php

namespace Tests\Feature;

use App\Models\EppEntrega;
use App\Models\Personal;
use App\Modules\Epps\Services\EppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EppLegacyBackfillTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public function test_backfill_marks_legacy_without_inventing_signature_or_operation(): void
    {
        $acceptancesBefore = DB::table('epp_aceptaciones')->count();
        $operationsBefore = DB::table('epp_operaciones')->count();
        $active = $this->legacyDelivery(EppEntrega::ESTADO_ENTREGADO, 3);
        $closed = $this->legacyDelivery(EppEntrega::ESTADO_DEVUELTO, 2);

        $this->artisan('epp:backfill-legacy-traceability')
            ->expectsOutputToContain('Revisados: 2')
            ->expectsOutputToContain('Actualizados: 2')
            ->assertSuccessful();

        $this->assertTrue($active->fresh()->es_legacy);
        $this->assertSame(EppEntrega::INTEGRIDAD_LEGACY, $active->fresh()->estado_integridad);
        $this->assertSame(3, $active->fresh()->cantidad_original);
        $this->assertSame(3, $active->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $closed->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame($acceptancesBefore, DB::table('epp_aceptaciones')->count());
        $this->assertSame($operationsBefore, DB::table('epp_operaciones')->count());
    }

    public function test_backfill_dry_run_and_repeated_run_are_idempotent(): void
    {
        $delivery = $this->legacyDelivery(EppEntrega::ESTADO_ENTREGADO, 4);

        $this->artisan('epp:backfill-legacy-traceability --dry-run')
            ->expectsOutputToContain('Simulacion: si')
            ->assertSuccessful();
        $this->assertNull($delivery->fresh()->cantidad_original);

        $this->artisan('epp:backfill-legacy-traceability')->assertSuccessful();
        $this->artisan('epp:backfill-legacy-traceability')
            ->expectsOutputToContain('Actualizados: 0')
            ->expectsOutputToContain('Omitidos: 1')
            ->assertSuccessful();
    }

    public function test_rerunning_backfill_never_reopens_a_reduced_legacy_balance(): void
    {
        $delivery = $this->legacyDelivery(EppEntrega::ESTADO_ENTREGADO, 4);
        $this->artisan('epp:backfill-legacy-traceability')->assertSuccessful();
        $delivery->refresh()->update(['cantidad_pendiente_devolucion' => 2]);
        $this->artisan('epp:backfill-legacy-traceability')->assertSuccessful();
        $this->assertSame(2, $delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(4, $delivery->fresh()->cantidad_original);
    }

    private function legacyDelivery(string $state, int $quantity): EppEntrega
    {
        $worker = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'LEGADO '.Str::uuid(),
            'puesto' => 'OPERARIO',
            'qr_code' => (string) Str::uuid(),
            'estado' => 'ACTIVO',
        ]);
        $epp = app(EppService::class)->storeCatalog(['nombre' => 'EPP LEGADO '.Str::uuid()]);

        return EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $worker->id,
            'epp_id' => $epp->id,
            'cantidad' => $quantity,
            'fecha_entrega' => now()->toDateString(),
            'estado' => $state,
            'cantidad_original' => null,
            'cantidad_pendiente_devolucion' => null,
            'politica_devolucion_snapshot' => 'RETORNABLE',
            'es_legacy' => true,
            'estado_integridad' => EppEntrega::INTEGRIDAD_LEGACY,
        ]);
    }
}
