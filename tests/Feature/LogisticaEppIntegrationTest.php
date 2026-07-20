<?php

namespace Tests\Feature;

use App\Models\EppEntrega;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Modules\Epps\Services\EppService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticaEppIntegrationTest extends TestCase
{
    use DatabaseTransactions;

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
            ->assertSee('Registrar entrega')
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
            ->assertSee('data-kardex-download-url="/epps/kardex/descargar"', false);
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
            ->get(route('logistica.index', ['tab' => 'entregas', 'per_page' => 10]))
            ->assertOk()
            ->assertSee('Mostrando 1&ndash;10 de 12 registros', false)
            ->assertSee('<option value="10" selected>10 registros</option>', false)
            ->assertSee('Anterior')
            ->assertSee('<span class="epp-page-link is-current" aria-current="page">1</span>', false)
            ->assertSee('Siguiente')
            ->assertSee('page=2', false);

        $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas', 'per_page' => 10, 'page' => 2]))
            ->assertOk()
            ->assertSee('Mostrando 11&ndash;12 de 12 registros', false)
            ->assertSee('<span class="epp-page-link is-current" aria-current="page">2</span>', false);

        $this
            ->withSession($session)
            ->get(route('logistica.index', ['tab' => 'entregas', 'per_page' => 25]))
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
            'nombre' => 'LOGISTICA_EPP_TEST',
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
