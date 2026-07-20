<?php

namespace Tests\Feature;

use App\Models\EppRegistro;
use App\Models\EppEntrega;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Modules\Epps\Services\EppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_catalogo_destroy_inactiva_item_sin_borrarlo(): void
    {
        $service = app(EppService::class);

        $epp = EppRegistro::query()->create([
            'id' => (string) Str::uuid(),
            'codigo' => 'ARNES_CON_REFERENCIA',
            'nombre' => 'ARNES CON REFERENCIA',
            'categoria' => 'EPP',
            'stock' => 0,
            'vida_util_dias' => 180,
            'requiere_talla' => false,
            'requiere_color' => false,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        $service->destroyCatalog($epp->id);

        $this->assertDatabaseHas('epp_registro', [
            'id' => $epp->id,
            'estado' => EppRegistro::ESTADO_INACTIVO,
        ]);
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

    public function test_entrega_se_corrige_con_epp_vencimiento_cantidad_y_notas(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '45678912',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678912',
            'nombre_completo' => 'Trabajador Correccion EPP',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-CORRECCION-EPP',
            'estado' => 'ACTIVO',
        ]);

        $service = app(EppService::class);
        $casco = $service->storeCatalog([
            'nombre' => 'Casco',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $respirador = $service->storeCatalog([
            'nombre' => 'Respirador',
            'vida_util_dias' => 90,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        $entrega = $service->deliver([
            'personal_id' => $personal->id,
            'epp_id' => $casco->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-03',
        ], null);

        $service->updateEntrega($entrega->id, [
            'epp_id' => $respirador->id,
            'fecha_entrega' => '2026-07-05',
            'fecha_vencimiento_calendario' => '2026-10-10',
            'cantidad' => 2,
            'motivo_cambio' => 'Correccion de item registrado',
            'observacion' => 'Se habia elegido casco por error.',
        ], null);

        $entrega->refresh();

        $this->assertSame($respirador->id, $entrega->epp_id);
        $this->assertSame(90, $entrega->vida_util_dias_snapshot);
        $this->assertSame('2026-07-05', $entrega->fecha_entrega->toDateString());
        $this->assertSame('2026-10-10', $entrega->fecha_vencimiento_calendario->toDateString());
        $this->assertSame(2, $entrega->cantidad);
        $this->assertSame('Correccion de item registrado', $entrega->motivo_cambio);
        $this->assertSame('Se habia elegido casco por error.', $entrega->observacion);
    }

    public function test_entrega_guarda_talla_color_y_atributos_del_catalogo(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '45670001',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45670001',
            'nombre_completo' => 'Trabajador Atributos EPP',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-ATRIBUTOS-EPP',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Casco con atributos',
            'vida_util_dias' => 30,
            'requiere_talla' => true,
            'tallas' => 'S, M, L',
            'requiere_color' => true,
            'colores' => 'Negro, Azul',
            'otros_atributos' => [
                ['nombre' => 'Material', 'valores' => 'ABS, Fibra'],
            ],
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        $entrega = app(EppService::class)->deliver([
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'talla' => 'M',
            'color' => 'Azul',
            'atributos' => [
                ['nombre' => 'Material', 'valor' => 'ABS'],
            ],
            'fecha_entrega' => '2026-07-17',
        ], null);

        $entrega->refresh();

        $this->assertSame('M', $entrega->talla);
        $this->assertSame('AZUL', $entrega->color);
        $this->assertSame([['nombre' => 'MATERIAL', 'valor' => 'ABS']], $entrega->atributos_json);
    }

    public function test_entregas_se_pueden_filtrar_por_id_de_trabajador(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '87654321',
            'tipo_documento' => 'DNI',
            'numero_documento' => '87654321',
            'nombre_completo' => 'Trabajador Enlace Vencimiento',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-FILTRO-ID',
            'estado' => 'ACTIVO',
        ]);

        $otroPersonal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '11223344',
            'tipo_documento' => 'DNI',
            'numero_documento' => '11223344',
            'nombre_completo' => 'Trabajador Fuera De Filtro',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-FILTRO-ID-OTRO',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Respirador',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        foreach ([$personal, $otroPersonal] as $worker) {
            EppEntrega::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $worker->id,
                'epp_id' => $epp->id,
                'cantidad' => 1,
                'fecha_entrega' => '2026-07-01',
                'fecha_vencimiento_calendario' => '2026-07-31',
                'vida_util_dias_snapshot' => 30,
                'estado' => EppEntrega::ESTADO_ENTREGADO,
            ]);
        }

        $data = app(EppService::class)->pageData(['q' => $personal->id]);

        $this->assertSame(1, $data['entregas']->total());
        $this->assertSame($personal->id, $data['entregas']->first()['personal']->id);
    }

    public function test_entregas_usa_limite_default_de_diez_y_respeta_selector(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '44556677',
            'tipo_documento' => 'DNI',
            'numero_documento' => '44556677',
            'nombre_completo' => 'Trabajador Paginado EPP',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-EPP-PAGINADO',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Lentes paginados',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        foreach (range(1, 12) as $index) {
            EppEntrega::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'epp_id' => $epp->id,
                'cantidad' => 1,
                'fecha_entrega' => sprintf('2026-07-%02d', $index),
                'fecha_vencimiento_calendario' => sprintf('2026-08-%02d', $index),
                'vida_util_dias_snapshot' => 30,
                'estado' => EppEntrega::ESTADO_ENTREGADO,
            ]);
        }

        $defaultData = app(EppService::class)->pageData();
        $selectedData = app(EppService::class)->pageData(['per_page' => 25]);

        $this->assertSame(10, $defaultData['entregas']->perPage());
        $this->assertCount(10, $defaultData['entregas']->items());
        $this->assertSame(12, $defaultData['entregas']->total());

        $this->assertSame(25, $selectedData['entregas']->perPage());
        $this->assertCount(12, $selectedData['entregas']->items());
        $this->assertSame([10, 25, 50, 100], $selectedData['perPageOptions']);
    }

    public function test_descarga_kardex_exporta_matriz_solo_con_epp_usados(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '77889900',
            'tipo_documento' => 'DNI',
            'numero_documento' => '77889900',
            'nombre_completo' => 'Trabajador Kardex Formato',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-KARDEX-FORMATO',
            'estado' => 'ACTIVO',
        ]);

        $service = app(EppService::class);
        $casco = $service->storeCatalog([
            'nombre' => 'Casco kardex usado',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $respirador = $service->storeCatalog([
            'nombre' => 'Respirador kardex usado',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $guantes = $service->storeCatalog([
            'nombre' => 'Guantes kardex devuelto',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $service->storeCatalog([
            'nombre' => 'Chaleco kardex sin uso',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $casco->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-01',
            'fecha_vencimiento_calendario' => '2026-07-31',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
        ]);
        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $guantes->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-20',
            'fecha_vencimiento_calendario' => '2026-08-19',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_DEVUELTO,
            'devuelto_at' => '2026-07-25',
        ]);
        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $respirador->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-15',
            'fecha_vencimiento_calendario' => '2026-08-14',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
        ]);

        $response = $service->downloadPersonalKardex($personal->id);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'kardex-epp-').'.xlsx';
        file_put_contents($path, $content);

        $workbook = IOFactory::load($path);
        $sheet = $workbook->getSheetByName('Anterior');
        $posterior = $workbook->getSheetByName('Posterior');

        $this->assertNotNull($sheet);
        $this->assertNotNull($posterior);
        $values = collect($sheet->toArray(null, true, true, true))->flatten()->filter()->values();

        $this->assertTrue($values->contains('CASCO KARDEX USADO'));
        $this->assertTrue($values->contains('RESPIRADOR KARDEX USADO'));
        $this->assertTrue($values->contains('GUANTES KARDEX DEVUELTO'));
        $this->assertFalse($values->contains('CHALECO KARDEX SIN USO'));
        $this->assertSame('FORMATO DE ENTREGA DE EPPS', $sheet->getCell('C1')->getValue());
        $this->assertSame('SGC-FOR-59', $sheet->getCell('AD1')->getValue());
        $this->assertSame('0', (string) $sheet->getCell('AD2')->getValue());
        $this->assertSame(': 1 de 2', $sheet->getCell('AD3')->getValue());
        $this->assertSame('RECIBIDO', $sheet->getCell('AE11')->getValue());
        $this->assertSame('D', $sheet->getCell('AB8')->getValue());
        $this->assertSame('Devuelto por internamiento', $sheet->getCell('AC8')->getValue());
        $this->assertSame('C00000', $sheet->getStyle('C1')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $sheet->getStyle('C1')->getFont()->getColor()->getRGB());
        $this->assertSame('N', $sheet->getCell('C14')->getValue());
        $this->assertSame('C', $sheet->getCell('D15')->getValue());
        $this->assertSame('D', $sheet->getCell('E16')->getValue());

        @unlink($path);
    }

    public function test_cierre_registra_perdida_u_olvido_en_historial(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '99001122',
            'tipo_documento' => 'DNI',
            'numero_documento' => '99001122',
            'nombre_completo' => 'Trabajador Cierre EPP',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-CIERRE-EPP',
            'estado' => 'ACTIVO',
        ]);
        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Guantes cierre',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $entrega = EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-01',
            'fecha_vencimiento_calendario' => '2026-07-31',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
        ]);

        app(EppService::class)->closeEntrega($entrega->id, [
            'estado' => EppEntrega::ESTADO_PERDIDA_OLVIDO,
            'devuelto_at' => '2026-07-12',
        ], null);

        $this->assertDatabaseHas('epp_entregas', [
            'id' => $entrega->id,
            'estado' => EppEntrega::ESTADO_PERDIDA_OLVIDO,
            'motivo_cambio' => 'Perdida / olvido',
            'devuelto_at' => '2026-07-12',
        ]);
    }

    public function test_cambio_de_epp_crea_nueva_entrega_y_cierra_la_anterior_en_una_transaccion(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '99003344',
            'tipo_documento' => 'DNI',
            'numero_documento' => '99003344',
            'nombre_completo' => 'Trabajador Cambio Atomico',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-CAMBIO-ATOMICO',
            'estado' => 'ACTIVO',
        ]);
        $service = app(EppService::class);
        $epp = $service->storeCatalog([
            'nombre' => 'Casco cambio atomico',
            'vida_util_dias' => 30,
            'requiere_talla' => true,
            'tallas' => 'M, L',
            'requiere_color' => true,
            'colores' => 'Azul, Blanco',
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $anterior = $service->deliver([
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'talla' => 'M',
            'color' => 'Azul',
            'fecha_entrega' => '2026-07-01',
        ], null);

        $nueva = $service->replaceEntrega($anterior->id, [
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'talla' => 'L',
            'color' => 'Blanco',
            'fecha_entrega' => '2026-07-17',
        ], [
            'devuelto_at' => '2026-07-17',
            'motivo_cambio' => 'Cambio por desgaste',
        ], null);

        $anterior->refresh();
        $nueva->refresh();

        $this->assertSame(EppEntrega::ESTADO_CAMBIADO, $anterior->estado);
        $this->assertSame('Cambio por desgaste', $anterior->motivo_cambio);
        $this->assertSame(EppEntrega::ESTADO_ENTREGADO, $nueva->estado);
        $this->assertSame('L', $nueva->talla);
        $this->assertSame('BLANCO', $nueva->color);
    }

    public function test_cambio_de_epp_no_cierra_la_anterior_si_no_se_guarda_la_nueva_entrega(): void
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '99005566',
            'tipo_documento' => 'DNI',
            'numero_documento' => '99005566',
            'nombre_completo' => 'Trabajador Cambio Fallido',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-CAMBIO-FALLIDO',
            'estado' => 'ACTIVO',
        ]);
        $service = app(EppService::class);
        $epp = $service->storeCatalog([
            'nombre' => 'Casco cambio fallido',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $anterior = $service->deliver([
            'personal_id' => $personal->id,
            'epp_id' => $epp->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-01',
        ], null);

        try {
            $service->replaceEntrega($anterior->id, [
                'personal_id' => $personal->id,
                'epp_id' => (string) Str::uuid(),
                'cantidad' => 1,
                'fecha_entrega' => '2026-07-17',
            ], [
                'devuelto_at' => '2026-07-17',
                'motivo_cambio' => 'Cambio no confirmado',
            ], null);
        } catch (\Throwable $exception) {
            // Expected: the new delivery could not be saved.
        }

        $anterior->refresh();

        $this->assertSame(EppEntrega::ESTADO_ENTREGADO, $anterior->estado);
        $this->assertNull($anterior->devuelto_at);
        $this->assertNull($anterior->motivo_cambio);
    }

    public function test_entregas_filtra_por_mina_epp_tipo_de_movimiento_y_fechas(): void
    {
        $minaA = Mina::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'Mina filtro A',
            'unidad_minera' => 'Unidad filtro A',
            'estado' => 'ACTIVO',
        ]);
        $minaB = Mina::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'Mina filtro B',
            'unidad_minera' => 'Unidad filtro B',
            'estado' => 'ACTIVO',
        ]);

        $personalA = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '11112222',
            'tipo_documento' => 'DNI',
            'numero_documento' => '11112222',
            'nombre_completo' => 'Trabajador Filtro A',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-EPP-FILTRO-A',
            'estado' => 'ACTIVO',
        ]);
        $personalB = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '33334444',
            'tipo_documento' => 'DNI',
            'numero_documento' => '33334444',
            'nombre_completo' => 'Trabajador Filtro B',
            'puesto' => 'MECANICO',
            'qr_code' => 'QR-TEST-EPP-FILTRO-B',
            'estado' => 'ACTIVO',
        ]);

        foreach ([[$personalA, $minaA], [$personalB, $minaB]] as [$personal, $mina]) {
            PersonalMina::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'mina_id' => $mina->id,
                'estado' => PersonalMina::ESTADO_HABILITADO,
                'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
                'activo' => true,
            ]);
        }

        $casco = app(EppService::class)->storeCatalog([
            'nombre' => 'Casco filtro',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);
        $guantes = app(EppService::class)->storeCatalog([
            'nombre' => 'Guantes filtro',
            'vida_util_dias' => 30,
            'estado' => EppRegistro::ESTADO_ACTIVO,
        ]);

        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalA->id,
            'epp_id' => $casco->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-05',
            'fecha_vencimiento_calendario' => '2026-08-04',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
        ]);
        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalB->id,
            'epp_id' => $guantes->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-06-01',
            'fecha_vencimiento_calendario' => '2026-07-01',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_CAMBIADO,
            'devuelto_at' => '2026-07-20',
            'motivo_cambio' => 'Cambio por desgaste',
        ]);
        EppEntrega::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalA->id,
            'epp_id' => $guantes->id,
            'cantidad' => 1,
            'fecha_entrega' => '2026-07-25',
            'fecha_vencimiento_calendario' => '2026-08-24',
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
            'motivo_cambio' => 'Renovacion por vencimiento',
        ]);

        $service = app(EppService::class);

        $this->assertSame(2, $service->pageData(['mina_id' => $minaA->id])['entregas']->total());
        $this->assertSame(1, $service->pageData(['epp_id' => $casco->id])['entregas']->total());
        $this->assertSame(1, $service->pageData(['tipo_movimiento' => 'CAMBIO'])['entregas']->total());
        $this->assertSame(1, $service->pageData(['tipo_movimiento' => 'RENOVACION'])['entregas']->total());
        $this->assertSame(2, $service->pageData([
            'fecha_desde' => '2026-07-15',
            'fecha_hasta' => '2026-07-31',
        ])['entregas']->total());
    }
}
