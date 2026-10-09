<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Epps\Services\EppService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticaEppIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_individual_entry_is_retired_in_favour_of_signed_batch(): void
    {
        $session = $this->sessionWithSelections(['logistica' => ['ver', 'ver_logistica_entregas', 'ver_logistica_dashboard'], 'epps' => ['ver', 'registrar', 'preparar_entrega']]);
        $this->withSession($session)->get(route('logistica.index', ['tab' => 'entregas']))->assertOk()->assertSee('Nueva entrega multiple')->assertDontSee('Registrar y agregar otra');
        $this->post(route('epps.entregas.store'), [])->assertStatus(409);
        $this->get(route('logistica.index', ['tab' => 'dashboard']))->assertOk()
            ->assertSee('Unidades pendientes de lotes')->assertSee('Lotes con firma')
            ->assertSee('Entregas historicas sin firma')->assertSee('Stock por conciliar')
            ->assertDontSee('Obligatorios: casco, chaleco');
    }

    public function test_mobile_review_shows_processed_and_pending_before_signature(): void
    {
        [$session, $operation] = $this->signedOperationFixture();
        $this->withSession($session)->get(route('epps.operations.review', $operation))
            ->assertOk()->assertSee('Recibiras ahora: 5')->assertSee('Quedara pendiente: 5')
            ->assertSee('Confirmo haber recibido unicamente los articulos mostrados')
            ->assertDontSee('data-operation-line', false)->assertDontSee('Testigo autorizado');
    }

    public function test_mobile_form_prepares_multiple_items_and_retry_keeps_the_same_lot(): void
    {
        [$session, $operation, $warehouse] = $this->signedOperationFixture();
        $this->withSession($session)->post(route('epps.operations.reject', $operation), ['decision' => 'DESACUERDO', 'motivo' => 'Preparar otro lote'])->assertSessionHasNoErrors();
        $this->get(route('epps.operations.create', ['personal_id' => $operation->personal_id, 'almacen_id' => $warehouse->id]))
            ->assertOk()->assertSee('TRABAJADOR FIRMA MOVIL')->assertSee('data-add-line', false);
        $uncontrolled = app(EppService::class)->storeCatalog(['nombre' => 'ARTICULO SIN STOCK '.Str::uuid(), 'estado' => 'ACTIVO', 'vida_util_dias' => 30, 'control_inventario' => false]);
        $uuid = (string) Str::uuid();
        $payload = ['tipo' => 'ENTREGA', 'submission_uuid' => $uuid, 'personal_id' => $operation->personal_id, 'almacen_id' => $warehouse->id,
            'lines' => [['epp_id' => $operation->detalles()->first()->epp_id, 'cantidad_solicitada' => 2], ['epp_id' => $uncontrolled->id, 'cantidad_solicitada' => 1]]];
        $this->post(route('epps.operations.store'), $payload)->assertSessionHasNoErrors();
        $prepared = EppOperacion::query()->where('submission_uuid', $uuid)->firstOrFail();
        $this->assertSame('LISTO_PARA_FIRMA', $prepared->estado);
        $this->assertSame(2, $prepared->detalles()->count());
        $this->assertSame(3, (int) $prepared->detalles()->sum('cantidad_procesada'));
        $version = $prepared->version;
        $this->post(route('epps.operations.store'), $payload)->assertRedirect(route('epps.operations.review', $prepared))->assertSessionHasNoErrors();
        $this->assertSame($version, $prepared->fresh()->version);
        $this->assertSame(1, $prepared->reservas()->count());
    }

    public function test_mobile_worker_search_is_scoped_and_requires_real_selection(): void
    {
        [$session, $operation] = $this->signedOperationFixture();
        $hidden = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'TRABAJADOR FIRMA OCULTO', 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
        $this->withSession($session)->getJson(route('epps.operations.workers', ['q' => 'TRABAJADOR FIRMA']))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonFragment(['id' => $operation->personal_id])->assertJsonMissing(['id' => $hidden->id]);
        $this->getJson(route('epps.operations.workers', ['q' => '']))->assertOk()->assertJsonCount(0, 'items');
        $this->get(route('epps.operations.create', ['personal_id' => $hidden->id]))->assertForbidden();
    }

    public function test_disagreement_and_refusal_release_stock_without_delivery(): void
    {
        foreach (['DESACUERDO', 'NEGATIVA'] as $decision) {
            [$session, $operation, $warehouse] = $this->signedOperationFixture();
            $this->withSession($session)->post(route('epps.operations.reject', $operation), ['decision' => $decision, 'motivo' => 'El trabajador solicita revisar el lote'])
                ->assertRedirect(route('epps.operations.show', $operation))->assertSessionHasNoErrors();
            $this->assertSame('RECHAZADO', $operation->fresh()->estado);
            $this->assertSame(0, $operation->entregas()->count());
            $this->assertSame(0, $operation->aceptacion()->count());
            $this->assertSame(5, $warehouse->existencias()->first()->cantidad_disponible);
            $this->assertSame(0, $warehouse->existencias()->first()->cantidad_reservada);
            $this->get(route('epps.operations.show', $operation))->assertOk()->assertSee('No se registro entrega');
            $this->get(route('epps.operations.review', $operation))->assertRedirect(route('epps.operations.show', $operation));
        }
    }

    public function test_sign_uses_server_quantities_and_rejects_changed_review(): void
    {
        Storage::fake('local');
        [$session, $operation] = $this->signedOperationFixture();
        $image = imagecreatetruecolor(600, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $pen = imagecolorallocate($image, 15, 25, 35);
        imageline($image, 40, 160, 170, 50, $pen);
        imageline($image, 170, 50, 110, 210, $pen);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $payload = ['submission_uuid' => $operation->submission_uuid, 'personal_id' => $operation->personal_id,
            'review_hash' => $operation->resumen_sha256, 'review_version' => $operation->version,
            'firma_data_url' => 'data:image/png;base64,'.base64_encode($bytes), 'confirmacion' => '1',
            'ink' => ['point_count' => 40, 'bounds' => ['min_x' => 40, 'min_y' => 50, 'max_x' => 170, 'max_y' => 210]],
            'lines' => [['cantidad_procesada' => 999]], 'cantidad_procesada' => 999];
        $this->withSession($session)->post(route('epps.operations.sign', $operation), array_replace($payload, ['review_hash' => str_repeat('0', 64)]))
            ->assertSessionHasErrors();
        $this->assertSame(0, $operation->entregas()->count());
        $this->post(route('epps.operations.sign', $operation), $payload)
            ->assertRedirect(route('epps.operations.show', $operation))->assertSessionHasNoErrors();
        $this->assertSame(5, (int) $operation->entregas()->sum('cantidad'));
        $this->assertSame('FIRMADO_PARCIAL', $operation->fresh()->estado);
        $operation->aceptacion()->first()->update(['estado_integridad' => 'ARCHIVO_FALTANTE']);
        $this->get(route('epps.operations.show', $operation))->assertOk()->assertSee('Alerta de integridad');
    }

    public function test_mobile_routes_deny_worker_and_warehouse_outside_scope(): void
    {
        [$session, $operation] = $this->signedOperationFixture();
        DB::table('usuario_mina_scope')->where('usuario_id', $session['user_id'])->delete();
        foreach (['edit', 'review', 'show'] as $action) {
            $this->withSession($session)->get(route('epps.operations.'.$action, $operation))->assertForbidden();
        }
        $this->post(route('epps.operations.reject', $operation), ['decision' => 'NEGATIVA', 'motivo' => 'Prueba'])->assertForbidden();
    }

    public function test_witness_review_requires_capability_and_cannot_follow_refusal(): void
    {
        [$session, $operation] = $this->signedOperationFixture();
        $this->withSession($session)->get(route('epps.operations.review', [$operation, 'testigo' => 1]))->assertForbidden();
        $actor = Usuario::query()->findOrFail($session['user_id']);
        $actor->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['preparar_entrega', 'confirmar_entrega', 'ver_operaciones', 'aprobar_excepcion_firma']])]);
        $outside = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'TESTIGO FUERA DEL ALCANCE', 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
        Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'estado' => 'ACTIVO', 'rol_id' => $actor->rol_id, 'personal_id' => $outside->id]);
        $this->get(route('epps.operations.review', [$operation, 'testigo' => 1]))->assertOk()
            ->assertSee('Testigo autorizado')->assertSee('El trabajador no firmo')->assertDontSee('TESTIGO FUERA DEL ALCANCE');
        $this->post(route('epps.operations.reject', $operation), ['decision' => 'NEGATIVA', 'motivo' => 'No desea firmar'])->assertSessionHasNoErrors();
        $this->get(route('epps.operations.review', [$operation, 'testigo' => 1]))->assertRedirect(route('epps.operations.show', $operation));
    }

    private function signedOperationFixture(): array
    {
        $session = $this->sessionWithSelections(['epps' => ['preparar_entrega', 'confirmar_entrega', 'ver_operaciones', 'gestionar_inventario']]);
        $actor = Usuario::query()->findOrFail($session['user_id']);
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA MOVIL '.Str::uuid(), 'unidad_minera' => 'UM PRUEBA', 'estado' => 'ACTIVO']);
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $actor->id, 'mina_id' => $mine->id]);
        $worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'TRABAJADOR FIRMA MOVIL', 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
        PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $worker->id, 'mina_id' => $mine->id, 'activo' => true, 'estado_habilitacion' => 'EN_PROCESO']);
        $warehouse = EppAlmacen::query()->create(['nombre' => 'ALMACEN MOVIL', 'mina_id' => $mine->id, 'estado' => 'ACTIVO']);
        $epp = app(EppService::class)->storeCatalog(['nombre' => 'GUANTES MOVIL '.Str::uuid(), 'vida_util_dias' => 30, 'estado' => 'ACTIVO', 'control_inventario' => true]);
        app(EppInventoryService::class)->openStock($warehouse, [['epp_id' => $epp->id, 'cantidad' => 5]], $actor, 'Conteo de prueba');
        $service = app(EppOperationService::class);
        $draft = $service->createDraft($actor, $worker, $warehouse, 'ENTREGA', (string) Str::uuid());
        $draft = $service->syncDetails($draft, [['epp_id' => $epp->id, 'cantidad_solicitada' => 10, 'aceptar_parcial' => true]], $actor);

        return [$session, $service->prepareForSignature($draft, $actor), $warehouse];
    }

    public function test_registro_individual_retornando_no_crea_entrega(): void
    {
        $session = $this->sessionWithEppPermissions();
        $before = EppEntrega::query()->count();
        $this->withSession($session)->post(route('epps.entregas.store'), ['_epp_add_another' => '1'])->assertStatus(409);
        $this->assertSame($before, EppEntrega::query()->count());
    }

    public function test_logistica_entregas_renderiza_la_vista_operativa_de_epp(): void
    {
        $session = $this->sessionWithEppPermissions();

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas']));

        $response
            ->assertOk()
            ->assertSee('Entregas y cambios de EPP')
            ->assertSee('Seguimiento de EPP')
            ->assertSee('Mina')
            ->assertSee('EPP / item')
            ->assertSee('Tipo de movimiento')
            ->assertSee('Fecha desde')
            ->assertSee('Fecha hasta')
            ->assertDontSee('Registrar y agregar otra')
            ->assertSee('data-epp-filter-toggle', false)
            ->assertSee('aria-controls="eppFilterBody"', false)
            ->assertSee('Catalogo de EPP');
    }

    public function test_logistica_kardex_usa_rutas_relativas_para_busqueda(): void
    {
        $session = $this->sessionWithEppPermissions();

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'kardex']));

        $response
            ->assertOk()
            ->assertSee('id="kardexPersonalSearch"', false)
            ->assertSee('data-personal-search-url="/epps/personal/buscar"', false)
            ->assertSee('data-kardex-detail-url="/epps/kardex"', false)
            ->assertSee('data-kardex-download-url="/epps/kardex/verificable/descargar"', false);
    }

    public function test_logistica_oculta_pestanas_sin_permiso_visual_del_rol(): void
    {
        $session = $this->sessionWithSelections([
            'logistica' => ['ver', 'ver_logistica_entregas'],
            'epps' => ['ver'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'kardex']));

        $response
            ->assertOk()
            ->assertSee('data-logistics-tab-link="entregas"', false)
            ->assertSee('data-logistics-tab-panel="entregas" aria-hidden="false"', false)
            ->assertDontSee('data-logistics-tab-link="kardex"', false)
            ->assertDontSee('data-logistics-tab-panel="kardex"', false)
            ->assertDontSee('data-logistics-tab-link="cesados"', false);
    }

    public function test_kardex_busqueda_personal_devuelve_opciones_por_palabras(): void
    {
        $session = $this->sessionWithEppPermissions();

        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '74185296',
            'numero_documento' => '74185296',
            'nombre_completo' => 'CALCINA AGUILAR EDGAR JAIME',
            'puesto' => 'MECANICO',
            'qr_code' => 'QR-KARDEX-74185296',
            'estado' => 'ACTIVO',
        ]);

        $this
            ->withSession($session)
            ->getJson(route('epps.personal.buscar', [
                'q' => 'jaime edgar',
                'limit' => 10,
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $personal->id,
                'nombre' => 'CALCINA AGUILAR EDGAR JAIME',
                'documento' => '74185296',
                'puesto' => 'MECANICO',
            ]);
    }

    public function test_epps_index_redirige_a_logistica_entregas(): void
    {
        $session = $this->sessionWithEppPermissions();

        $response = $this
            ->withSession($session)
            ->get(route('epps.index', ['estado' => 'ENTREGADO']));

        $response->assertRedirect(route('logistica.index', [
            'tab' => 'entregas',
            'estado' => 'ENTREGADO',
        ]));
    }

    public function test_herramientas_parada_index_redirige_a_logistica_herramientas(): void
    {
        $session = $this->sessionWithSelections([
            'herramientas' => ['ver', 'actualizar'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('herramientas-parada.index', [
                'q' => 'cerro',
                'estado_lista' => 'BORRADOR',
            ]));

        $response->assertRedirect(route('logistica.index', [
            'tab' => 'herramientas',
            'q' => 'cerro',
            'estado_lista' => 'BORRADOR',
        ]));
    }

    public function test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente(): void
    {
        $session = $this->sessionWithSelections([
            'logistica' => ['ver', 'actualizar'],
            'herramientas' => ['ver', 'actualizar', 'importar'],
        ]);

        $mineId = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $mineId,
            'nombre' => 'Mina Herramientas Logistica',
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $session['user_id'],
            'mina_id' => $mineId,
        ]);

        DB::table('rq_mina')->insert([
            'id' => (string) Str::uuid(),
            'mina_id' => $mineId,
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'destino_nombre' => 'Mina Herramientas Logistica',
            'area' => 'Parada herramientas logistica',
            'fecha_inicio' => now()->addDays(6)->toDateString(),
            'fecha_fin' => now()->addDays(8)->toDateString(),
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $session['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', [
                'tab' => 'herramientas',
                'q' => 'herramientas logistica',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-logistics-tab-panel="herramientas" aria-hidden="false"', false)
            ->assertSee('Herramientas y consumibles por parada')
            ->assertSee('Listas semanales de equipos, herramientas, utillaje y consumibles por grupo')
            ->assertSee('Subir catalogo')
            ->assertSee('Estado lista')
            ->assertSee('Parada herramientas logistica')
            ->assertSee('<span class="nav-label">Logistica</span>', false)
            ->assertDontSee('<span class="nav-label">Herramientas</span>', false)
            ->assertSee('data-logistics-tab-link="herramientas"', false);
    }

    public function test_proximos_vencimientos_puede_abrir_entregas_filtradas_por_trabajador(): void
    {
        $session = $this->sessionWithEppPermissions();

        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '71318777',
            'tipo_documento' => 'DNI',
            'numero_documento' => '71318777',
            'nombre_completo' => 'Trabajador Navegable EPP',
            'puesto' => 'MECANICO',
            'qr_code' => 'QR-TEST-LOGISTICA-NAVEGABLE',
            'estado' => 'ACTIVO',
        ]);

        $otroPersonal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '99990000',
            'tipo_documento' => 'DNI',
            'numero_documento' => '99990000',
            'nombre_completo' => 'Trabajador Oculto EPP',
            'puesto' => 'MECANICO',
            'qr_code' => 'QR-TEST-LOGISTICA-OCULTO',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Casco navegable',
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

        $response = $this
            ->withSession($session)
            ->get(route('logistica.index', [
                'tab' => 'entregas-epp',
                'trabajador' => '71318777',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-logistics-tab-panel="entregas" aria-hidden="false"', false)
            ->assertSee('value="71318777"', false)
            ->assertSee('<option value="10" selected>10 registros</option>', false)
            ->assertSee('Trabajador: <strong>TRABAJADOR NAVEGABLE EPP</strong>', false)
            ->assertSee('aria-label="Quitar filtro de trabajador"', false)
            ->assertSee('href="'.route('logistica.index', ['tab' => 'entregas']).'"', false)
            ->assertSee('1 registros')
            ->assertSee('TRABAJADOR NAVEGABLE EPP');
    }

    public function test_paginacion_de_entregas_muestra_rango_selector_y_controles(): void
    {
        $session = $this->sessionWithEppPermissions();

        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '70605040',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70605040',
            'nombre_completo' => 'Trabajador Paginacion Logistica',
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-TEST-LOGISTICA-PAGINACION',
            'estado' => 'ACTIVO',
        ]);

        $epp = app(EppService::class)->storeCatalog([
            'nombre' => 'Guantes paginacion',
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

        $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas', 'q' => $personal->id, 'per_page' => 10]))
            ->assertOk()
            ->assertSee('Mostrando 1&ndash;10 de 12 registros', false)
            ->assertSee('<option value="10" selected>10 registros</option>', false)
            ->assertSee('Anterior')
            ->assertSee('<span class="epp-page-link is-current" aria-current="page">1</span>', false)
            ->assertSee('Siguiente')
            ->assertSee('page=2', false);

        $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas', 'q' => $personal->id, 'per_page' => 10, 'page' => 2]))
            ->assertOk()
            ->assertSee('Mostrando 11&ndash;12 de 12 registros', false)
            ->assertSee('<span class="epp-page-link is-current" aria-current="page">2</span>', false);

        $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas', 'q' => $personal->id, 'per_page' => 25]))
            ->assertOk()
            ->assertSee('Mostrando 1&ndash;12 de 12 registros', false)
            ->assertSee('<option value="25" selected>25 registros</option>', false);
    }

    public function test_identificacion_renderiza_herramientas_y_consumibles(): void
    {
        $session = $this->sessionWithEppPermissions();

        foreach ([
            ['categoria' => 'HERRAMIENTA', 'descripcion' => 'LLAVE DE GOLPE', 'unidad' => 'UND'],
            ['categoria' => 'CONSUMIBLE', 'descripcion' => 'DISCO DE CORTE', 'unidad' => 'UND'],
        ] as $item) {
            DB::table('parada_herramienta_catalogos')->insert([
                'id' => (string) Str::uuid(),
                'categoria' => $item['categoria'],
                'descripcion' => $item['descripcion'],
                'descripcion_normalizada' => $item['descripcion'],
                'unidad' => $item['unidad'],
                'unidad_normalizada' => $item['unidad'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this
            ->withSession($session)
            ->get(route('logistica.index', [
                'tab' => 'identificacion',
                'ident_categoria' => 'HERRAMIENTA',
            ]))
            ->assertOk()
            ->assertSee('data-logistics-tab-panel="identificacion"', false)
            ->assertSee('LLAVE DE GOLPE')
            ->assertSee('Catalogo de parada');

        $this
            ->withSession($session)
            ->get(route('logistica.index', [
                'tab' => 'identificacion',
                'ident_categoria' => 'CONSUMIBLE',
            ]))
            ->assertOk()
            ->assertSee('data-logistics-tab-panel="identificacion"', false)
            ->assertSee('DISCO DE CORTE')
            ->assertSee('Catalogo de parada');
    }

    private function sessionWithEppPermissions(): array
    {
        return $this->sessionWithSelections([
            'logistica' => ['ver', 'actualizar'],
            'epps' => ['ver', 'registrar', 'editar', 'actualizar', 'eliminar', 'configurar', 'devolver'],
        ]);
    }

    private function sessionWithSelections(array $selections): array
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $logisticsTabActions = array_values(PermissionCatalog::logisticsTabActions());

        if (isset($selections['logistica'])
            && in_array('ver', $selections['logistica'], true)
            && count(array_intersect($logisticsTabActions, $selections['logistica'])) === 0
        ) {
            $selections['logistica'] = array_values(array_unique(array_merge(
                $selections['logistica'],
                $logisticsTabActions,
            )));
        }

        $permissions = PermissionCatalog::matrixFromSelections($selections);

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_EPP_TEST '.Str::random(8),
            'permisos' => json_encode($permissions),
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
        ]);

        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'logistica-epp@test.local',
                'permissions' => $permissions,
            ],
        ];
    }
}
