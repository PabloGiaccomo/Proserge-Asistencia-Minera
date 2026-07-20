<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Modules\RQProserge\Services\RQProsergeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RQProsergeApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolRrhhId;

    private string $rolPlannerId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 08:00:00'));

        $this->rolRrhhId = (string) Str::uuid();
        $this->rolPlannerId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->rolRrhhId,
                'nombre' => 'RRHH',
                'permisos' => json_encode(['rq_proserge.read', 'rq_proserge.write']),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->rolPlannerId,
                'nombre' => 'PLANNER',
                'permisos' => json_encode(['rq_mina.read']),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_rrhh_con_scope_si_accede(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-proserge/'.$rqProsergeId);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'RQ_PROSERGE_SHOW_OK');
    }

    public function test_rrhh_sin_scope_no_accede(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $otraMinaId = $this->crearMina();
        $this->asignarScopeUsuario($rrhhId, $otraMinaId);
        $token = $this->crearToken($rrhhId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-proserge/'.$rqProsergeId);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RQ_PROSERGE_NOT_FOUND');
    }

    public function test_no_asigna_trabajador_bloqueado(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);
        $this->bloquearPersonal($personalId, $rrhhId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PERSONAL_BLOCKED')
            ->assertJsonStructure(['ok', 'code', 'message', 'detail', 'data']);
    }

    public function test_asigna_trabajador_con_fechas_de_contrato_sin_exigir_pdf_firmado(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId, estadoPersonal: 'FALTA_CONTRATO', estadoContrato: 'PREPARACION');

        DB::table('personal_mina')
            ->where('personal_id', $personalId)
            ->where('mina_id', $minaId)
            ->update([
                'estado' => 'NO_HABILITADO',
                'estado_habilitacion' => 'HABILITADO',
                'activo' => true,
            ]);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGN_OK');

        $this->assertDatabaseHas('rq_proserge_detalle', [
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'estado' => 'ASIGNADO',
        ]);
    }

    public function test_asigna_trabajador_en_proceso_de_habilitacion_a_rq_proserge(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        DB::table('personal_mina')
            ->where('personal_id', $personalId)
            ->where('mina_id', $minaId)
            ->update([
                'estado' => 'EN_PROCESO',
                'estado_habilitacion' => 'EN_PROCESO',
                'activo' => true,
            ]);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGN_OK');

        $this->assertDatabaseHas('rq_proserge_detalle', [
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
        ]);
    }

    public function test_asigna_trabajador_no_habilitado_en_mina_si_no_tiene_otros_bloqueos(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        DB::table('personal_mina')
            ->where('personal_id', $personalId)
            ->where('mina_id', $minaId)
            ->update([
                'estado' => 'NO_HABILITADO',
                'estado_habilitacion' => 'NO_HABILITADO',
                'activo' => true,
            ]);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGN_OK');

        $this->assertDatabaseHas('rq_proserge_detalle', [
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
        ]);
    }

    public function test_no_asigna_trabajador_con_ultimo_contrato_cerrado_aunque_estado_personal_no_sea_cesado(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId, crearContrato: false, estadoPersonal: 'INACTIVO');
        $this->crearContratoPersonal($personalId, 'CERRADO');

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PERSONAL_CONTRACT_CLOSED');

        $this->assertDatabaseMissing('rq_proserge_detalle', [
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
        ]);
    }

    public function test_busqueda_muestra_trabajador_no_disponible_con_motivo(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $personalId = $this->crearPersonal($minaId, crearContrato: false);

        DB::table('personal')->where('id', $personalId)->update([
            'nombre_completo' => 'ACHIRI TRABAJADOR NO DISPONIBLE',
        ]);

        $rq = \App\Models\RQProserge::query()->findOrFail($rqProsergeId);
        $items = app(RQProsergeService::class)->searchAvailablePersonal($rq, 'achiri', '2026-05-01', '2026-05-03');
        $item = collect($items)->firstWhere('personal_id', $personalId);

        $this->assertNotNull($item);
        $this->assertFalse($item['disponible']);
        $this->assertSame('PERSONAL_WITHOUT_CONTRACT_DATES', $item['motivo_codigo']);
        $this->assertTrue(collect($item['lineas'])->contains(fn (string $line): bool => str_contains(strtolower($line), 'contrato')));
    }

    public function test_busqueda_muestra_trabajador_con_contrato_cerrado_como_no_disponible(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $personalId = $this->crearPersonal($minaId, crearContrato: false, estadoPersonal: 'INACTIVO');
        $this->crearContratoPersonal($personalId, 'CERRADO');

        DB::table('personal')->where('id', $personalId)->update([
            'nombre_completo' => 'PAREDES HUERTA PRUEBA3',
        ]);

        $rq = \App\Models\RQProserge::query()->findOrFail($rqProsergeId);
        $items = app(RQProsergeService::class)->searchAvailablePersonal($rq, 'paredes', '2026-05-01', '2026-05-03');
        $item = collect($items)->firstWhere('personal_id', $personalId);

        $this->assertNotNull($item);
        $this->assertFalse($item['disponible']);
        $this->assertSame('PERSONAL_CONTRACT_CLOSED', $item['motivo_codigo']);
        $this->assertTrue(collect($item['lineas'])->contains(fn (string $line): bool => str_contains(strtolower($line), 'cerrado')));
    }

    public function test_busqueda_muestra_habilitado_con_ficha_pendiente_como_disponible(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $personalId = $this->crearPersonal(
            $minaId,
            estadoPersonal: 'PENDIENTE_COMPLETAR_FICHA',
            estadoContrato: 'PREPARACION'
        );

        DB::table('personal')->where('id', $personalId)->update([
            'nombre_completo' => 'ALARCON GONZALES ROMEL HUGO',
        ]);

        $rq = \App\Models\RQProserge::query()->findOrFail($rqProsergeId);
        $items = app(RQProsergeService::class)->searchAvailablePersonal($rq, 'alarcon', '2026-05-01', '2026-05-03');
        $item = collect($items)->firstWhere('personal_id', $personalId);

        $this->assertNotNull($item);
        $this->assertTrue($item['disponible']);
        $this->assertNull($item['motivo_codigo']);
    }

    public function test_busqueda_muestra_estado_de_habilitacion_de_mina_sin_bloquear(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $personalId = $this->crearPersonal($minaId);

        DB::table('personal')->where('id', $personalId)->update([
            'nombre_completo' => 'ALARCON MINA EN PROCESO',
        ]);
        DB::table('personal_mina')
            ->where('personal_id', $personalId)
            ->where('mina_id', $minaId)
            ->update([
                'estado' => 'EN_PROCESO',
                'estado_habilitacion' => 'EN_PROCESO',
            ]);

        $rq = \App\Models\RQProserge::query()->findOrFail($rqProsergeId);
        $items = app(RQProsergeService::class)->searchAvailablePersonal($rq, 'alarcon mina', '2026-05-01', '2026-05-03');
        $item = collect($items)->firstWhere('personal_id', $personalId);

        $this->assertNotNull($item);
        $this->assertTrue($item['disponible']);
        $this->assertSame('EN_PROCESO', $item['mina_estado']['estado']);
        $this->assertSame('En proceso en mina', $item['mina_estado']['label']);
    }

    public function test_asignacion_fuera_de_fechas_de_parada_se_bloquea(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-04-30',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGNMENT_DATE_OUT_OF_RANGE');
    }

    public function test_no_asigna_trabajador_duplicado_en_rango(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId, $rqMinaId] = $this->crearEscenarioBase(true, true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $otroRqProsergeId = $this->crearRQProserge($rqMinaId, $minaId, $rrhhId);
        $this->crearAsignacionProserge($otroRqProsergeId, $rqMinaDetalleId, $personalId, '2026-05-01', '2026-05-03');

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-02',
            'fecha_fin' => '2026-05-04',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PERSONAL_CONFLICT_RQ');
    }

    public function test_desasignar_libera_y_recalcula_cantidad_atendida(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $asignacionId = $this->crearAsignacionProserge($rqProsergeId, $rqMinaDetalleId, $personalId, '2026-05-01', '2026-05-02');
        DB::table('rq_mina_detalle')->where('id', $rqMinaDetalleId)->update(['cantidad_atendida' => 1]);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/desasignar', [
            'rq_proserge_detalle_id' => $asignacionId,
        ]);

        $response->assertOk()->assertJsonPath('code', 'RQ_PROSERGE_UNASSIGN_OK');

        $this->assertDatabaseMissing('rq_proserge_detalle', ['id' => $asignacionId]);
        $this->assertDatabaseHas('rq_mina_detalle', ['id' => $rqMinaDetalleId, 'cantidad_atendida' => 0]);
    }

    public function test_no_asigna_personal_si_la_parada_ya_finalizo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 08:00:00'));

        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-02',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_PROSERGE_PARADA_FINALIZADA');

        $this->assertDatabaseMissing('rq_proserge_detalle', [
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
        ]);
    }

    public function test_no_desasigna_personal_si_la_parada_ya_finalizo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 08:00:00'));

        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);
        $asignacionId = $this->crearAsignacionProserge($rqProsergeId, $rqMinaDetalleId, $personalId, '2026-05-01', '2026-05-02');

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/desasignar', [
            'rq_proserge_detalle_id' => $asignacionId,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_PROSERGE_PARADA_FINALIZADA');

        $this->assertDatabaseHas('rq_proserge_detalle', [
            'id' => $asignacionId,
            'rq_proserge_id' => $rqProsergeId,
            'personal_id' => $personalId,
        ]);
    }

    public function test_no_actualiza_seguimiento_si_la_parada_ya_finalizo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 08:00:00'));

        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);

        $response = $this->withToken($token)->putJson('/api/v1/rq-proserge/'.$rqProsergeId, [
            'estado' => 'PARCIAL',
            'comentario_rrhh' => 'Cambio fuera de fecha',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_PROSERGE_PARADA_FINALIZADA');
    }

    public function test_respuesta_uniforme_en_error_negocio(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $plannerId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScopeUsuario($plannerId, $minaId);
        $token = $this->crearToken($plannerId);
        $personalId = $this->crearPersonal($minaId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-02',
        ]);

        $response->assertStatus(403)
            ->assertJsonStructure(['ok', 'code', 'message', 'detail', 'data'])
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGN_FORBIDDEN');
    }

    public function test_listado_operativo_oculta_vencidos_y_ordena_por_fecha_y_faltante(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-19 08:00:00'));

        $minaId = $this->crearMina();
        $plannerId = $this->crearUsuario($this->rolPlannerId);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);

        $rqVencido = $this->crearRQProsergeConDetalle($minaId, $plannerId, $rrhhId, '2026-05-01', '2026-06-01', 100);
        $rqCercanoBajo = $this->crearRQProsergeConDetalle($minaId, $plannerId, $rrhhId, '2026-06-20', '2026-06-25', 2);
        $rqCercanoAlto = $this->crearRQProsergeConDetalle($minaId, $plannerId, $rrhhId, '2026-06-21', '2026-06-28', 20);
        $rqFuturo = $this->crearRQProsergeConDetalle($minaId, $plannerId, $rrhhId, '2026-07-15', '2026-07-20', 50);
        $rqRecientePasado = $this->crearRQProsergeConDetalle($minaId, $plannerId, $rrhhId, '2026-06-01', '2026-06-12', 80);

        $usuario = Usuario::query()->findOrFail($rrhhId);
        $items = app(RQProsergeService::class)->listOperationalForUser($usuario, ['mina_id' => $minaId]);
        $ids = $items->pluck('id')->all();

        $this->assertNotContains($rqVencido, $ids);
        $this->assertSame(
            [$rqCercanoAlto, $rqCercanoBajo, $rqFuturo, $rqRecientePasado],
            $ids
        );
    }

    private function crearEscenarioBase(bool $returnDetalle = false, bool $returnRQMinaId = false): array
    {
        $minaId = $this->crearMina();
        $creadorId = $this->crearUsuario($this->rolPlannerId);
        $rqMinaId = $this->crearRQMina($minaId, $creadorId);
        $rqMinaDetalleId = $this->crearRQMinaDetalle($rqMinaId);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $rqProsergeId = $this->crearRQProserge($rqMinaId, $minaId, $rrhhId);

        $output = [$minaId, $rqProsergeId];

        if ($returnDetalle) {
            $output[] = $rqMinaDetalleId;
        }

        if ($returnRQMinaId) {
            $output[] = $rqMinaId;
        }

        return $output;
    }

    private function crearMina(): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'Mina '.Str::upper(Str::random(4)),
            'unidad_minera' => 'UM '.Str::upper(Str::random(3)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function crearUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => null,
        ]);

        return $id;
    }

    private function crearToken(string $usuarioId): string
    {
        $plain = Str::random(80);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    private function asignarScopeUsuario(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function crearRQMina(
        string $minaId,
        string $creadorId,
        string $fechaInicio = '2026-05-01',
        string $fechaFin = '2026-05-05'
    ): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Operacion',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $creadorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearRQProsergeConDetalle(
        string $minaId,
        string $plannerId,
        string $rrhhId,
        string $fechaInicio,
        string $fechaFin,
        int $cantidad
    ): string {
        $rqMinaId = $this->crearRQMina($minaId, $plannerId, $fechaInicio, $fechaFin);
        $this->crearRQMinaDetalle($rqMinaId, $cantidad);

        return $this->crearRQProserge($rqMinaId, $minaId, $rrhhId);
    }

    private function crearRQMinaDetalle(string $rqMinaId, int $cantidad = 3): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina_detalle')->insert([
            'id' => $id,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => $cantidad,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearRQProserge(string $rqMinaId, string $minaId, string $rrhhId): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_proserge')->insert([
            'id' => $id,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $minaId,
            'responsable_rrhh_id' => $rrhhId,
            'estado' => 'BORRADOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearPersonal(
        string $minaId,
        bool $crearContrato = true,
        string $estadoPersonal = 'ACTIVO',
        string $estadoContrato = 'ACTIVO'
    ): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Trabajador '.Str::upper(Str::random(5)),
            'puesto' => 'Tecnico',
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => $estadoPersonal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $id,
            'mina_id' => $minaId,
            'estado' => 'HABILITADO',
            'estado_habilitacion' => 'HABILITADO',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($crearContrato) {
            $this->crearContratoPersonal($id, $estadoContrato);
        }

        return $id;
    }

    private function crearContratoPersonal(string $personalId, string $estado = 'ACTIVO'): void
    {
        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => $estado,
            'tipo_contrato' => 'FIJO',
            'puesto' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-05',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bloquearPersonal(string $personalId, string $usuarioId): void
    {
        DB::table('personal_bloqueo')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'tipo' => 'DESCANSO_MEDICO',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
            'motivo' => 'Bloqueo test',
            'bloqueado_por_id' => $usuarioId,
            'estado' => 'ACTIVO',
            'visible_para_planner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearAsignacionProserge(
        string $rqProsergeId,
        string $rqMinaDetalleId,
        string $personalId,
        string $fechaInicio,
        string $fechaFin
    ): string {
        $id = (string) Str::uuid();

        DB::table('rq_proserge_detalle')->insert([
            'id' => $id,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estado' => 'ASIGNADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
