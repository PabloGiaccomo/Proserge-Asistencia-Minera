<?php

namespace Tests\Feature;

use App\Models\EppRegistro;
use App\Models\EppEntrega;
use App\Models\Personal;
use App\Modules\Epps\Services\EppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class EppCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalogo_guarda_si_epp_requiere_talla_y_color(): void
    {
        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Chaleco',
            'codigo' => 'MANUAL',
            'categoria' => 'HERRAMIENTA',
            'stock' => 99,
            'vida_util_dias' => 365,
            'requiere_talla' => true,
            'tallas' => "S\nM\nM\nXL",
            'requiere_color' => true,
            'colores' => 'Rojo, Azul; Azul',
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        $epp->refresh();

        $this->assertSame('CHALECO', $epp->codigo);
        $this->assertSame('EPP', $epp->categoria);
        $this->assertSame(0, $epp->stock);
        $this->assertTrue($epp->requiere_talla);
        $this->assertSame(['S', 'M', 'XL'], $epp->tallas);
        $this->assertTrue($epp->requiere_color);
        $this->assertSame(['ROJO', 'AZUL'], $epp->colores);
    }

    public function test_catalogo_limpia_opciones_si_no_requiere_talla_ni_color(): void
    {
        $service = app(EppService::class);

        $service->storeCatalog([
            'nombre' => 'Guantes de cuero',
            'vida_util_dias' => 90,
            'requiere_talla' => true,
            'tallas' => 'S, M',
            'requiere_color' => true,
            'colores' => 'Marron',
        ]);

        $epp = $service->storeCatalog([
            'nombre' => 'Guantes de cuero',
            'vida_util_dias' => 120,
            'requiere_talla' => false,
            'requiere_color' => false,
        ]);

        $epp->refresh();

        $this->assertFalse($epp->requiere_talla);
        $this->assertNull($epp->tallas);
        $this->assertFalse($epp->requiere_color);
        $this->assertNull($epp->colores);
        $this->assertSame(120, $epp->vida_util_dias);
    }

    public function test_catalogo_actualiza_epp_existente_sin_crear_duplicado(): void
    {
        $service = app(EppService::class);

        $epp = $service->storeCatalog([
            'nombre' => 'Chaleco',
            'vida_util_dias' => 365,
            'requiere_talla' => true,
            'tallas' => 'S, M',
            'requiere_color' => false,
        ]);

        $actualizado = $service->updateCatalog($epp->id, [
            'nombre' => 'Chaleco reflectivo',
            'vida_util_dias' => 240,
            'requiere_talla' => false,
            'requiere_color' => true,
            'colores' => 'Naranja, Verde, Naranja',
            'estado' => EppRegistro::ESTADO_INACTIVO,
        ]);

        $this->assertSame($epp->id, $actualizado->id);
        $this->assertSame('CHALECO_REFLECTIVO', $actualizado->codigo);
        $this->assertSame('CHALECO REFLECTIVO', $actualizado->nombre);
        $this->assertSame(240, $actualizado->vida_util_dias);
        $this->assertFalse($actualizado->requiere_talla);
        $this->assertNull($actualizado->tallas);
        $this->assertTrue($actualizado->requiere_color);
        $this->assertSame(['NARANJA', 'VERDE'], $actualizado->colores);
        $this->assertSame(EppRegistro::ESTADO_INACTIVO, $actualizado->estado);
        $this->assertSame(1, EppRegistro::query()->where('nombre', 'CHALECO REFLECTIVO')->count());
    }

    public function test_ultima_entrega_devuelve_mini_kardex_mas_reciente(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '12345678',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'nombre_completo' => 'Trabajador Kardex',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-KARDEX',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Casco',
            'vida_util_dias' => 180,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-01',
            'fecha_vencimiento_calendario' => '2026-12-28',
            'vida_util_dias_snapshot' => 180,
            'estado' => EppEntrega::ESTADO_DEVUELTO,
            'devuelto_at' => '2026-07-10',
            'observacion' => 'Entrega previa',
        ]);

        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 2,
            'fecha_entrega' => '2026-07-15',
            'fecha_vencimiento_calendario' => '2027-01-11',
            'vida_util_dias_snapshot' => 180,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
            'observacion' => 'Entrega vigente',
        ]);

        $summary = app(EppService::class)->lastDeliverySummary($personal->id, $epp->id);

        $this->assertNotNull($summary);
        $this->assertSame('CASCO', $summary['epp']);
        $this->assertSame(2, $summary['cantidad']);
        $this->assertSame('15/07/2026', $summary['fecha_entrega']);
        $this->assertSame('11/01/2027', $summary['fecha_vencimiento_calendario']);
        $this->assertSame(EppEntrega::ESTADO_ENTREGADO, $summary['estado']);
        $this->assertSame('Entrega vigente', $summary['observacion']);
    }
}
