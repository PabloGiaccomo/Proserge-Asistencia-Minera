<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolePlannerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolePlannerId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $this->rolePlannerId,
            'nombre' => 'PLANNER',
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_dashboard_principal_returns_expected_contract(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->rolePlannerId);
        $this->assignScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/dashboard/principal');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'DASHBOARD_PRINCIPAL_OK')
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'detail',
                'data' => [
                    'resumen',
                    'rq_mina',
                    'rq_proserge',
                    'man_power',
                    'asistencia',
                    'faltas',
                    'evaluaciones',
                    'alertas',
                ],
            ]);
    }

    public function test_dashboard_resumen_applies_date_filters(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->rolePlannerId);
        $this->assignScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $this->createRQMina($minaId, $usuarioId, 'BORRADOR', '2026-07-01 08:00:00');
        $this->createRQMina($minaId, $usuarioId, 'ENVIADO', '2026-08-10 09:00:00');

        $response = $this->withToken($token)->getJson('/api/v1/dashboard/resumen?fecha_desde=2026-08-01&fecha_hasta=2026-08-31');

        $response->assertOk()
            ->assertJsonPath('code', 'DASHBOARD_RESUMEN_OK')
            ->assertJsonPath('data.rq_mina_total', 1);
    }

    public function test_dashboard_resumen_respects_scope_for_non_privileged_roles(): void
    {
        $minaScopeId = $this->createMina();
        $minaOutsideScopeId = $this->createMina();
        $usuarioId = $this->createUsuario($this->rolePlannerId);
        $this->assignScope($usuarioId, $minaScopeId);
        $token = $this->createToken($usuarioId);

        $this->createRQMina($minaScopeId, $usuarioId, 'BORRADOR');
        $this->createRQMina($minaOutsideScopeId, $usuarioId, 'BORRADOR');

        $response = $this->withToken($token)->getJson('/api/v1/dashboard/resumen');

        $response->assertOk()
            ->assertJsonPath('data.rq_mina_total', 1);
    }

    public function test_dashboard_man_power_filters_by_destino_tipo(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->rolePlannerId);
        $this->assignScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $supervisorId = $this->createPersonal($minaId, true);
        $rqMinaId = $this->createRQMina($minaId, $usuarioId, 'ENVIADO');

        $this->createGrupoTrabajo($supervisorId, $rqMinaId, 'MINA', $minaId, $usuarioId);
        $this->createGrupoTrabajo($supervisorId, $rqMinaId, 'OFICINA', (string) Str::uuid(), $usuarioId);

        $minaResponse = $this->withToken($token)->getJson('/api/v1/dashboard/man-power?destino_tipo=MINA');
        $minaResponse->assertOk()->assertJsonPath('data.grupos_total', 1);

        $oficinaResponse = $this->withToken($token)->getJson('/api/v1/dashboard/man-power?destino_tipo=OFICINA');
        $oficinaResponse->assertOk()->assertJsonPath('data.grupos_total', 1);
    }

    public function test_dashboard_rq_proserge_pending_kpi_counts_incomplete_requests(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->rolePlannerId);
        $this->assignScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $rqMinaPendienteId = $this->createRQMina($minaId, $usuarioId, 'ENVIADO');
        $this->createRQMinaDetalle($rqMinaPendienteId, 2, 1);
        $this->createRQProserge($rqMinaPendienteId, $minaId, $usuarioId);

        $rqMinaCompletaId = $this->createRQMina($minaId, $usuarioId, 'ENVIADO');
        $this->createRQMinaDetalle($rqMinaCompletaId, 3, 3);
        $this->createRQProserge($rqMinaCompletaId, $minaId, $usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/dashboard/rq-proserge');

        $response->assertOk()
            ->assertJsonPath('data.requerimientos_total', 2)
            ->assertJsonPath('data.requerimientos_pendientes', 1);
    }

    private function createMina(): string
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

    private function createUsuario(string $rolId): string
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

    private function assignScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function createToken(string $usuarioId): string
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

    private function createRQMina(string $minaId, string $creadorId, string $estado, ?string $createdAt = null): string
    {
        $id = (string) Str::uuid();
        $timestamp = $createdAt ? Carbon::parse($createdAt) : now();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Operacion',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-05',
            'observaciones' => null,
            'estado' => $estado,
            'created_by_usuario_id' => $creadorId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }

    private function createRQMinaDetalle(string $rqMinaId, int $cantidad, int $cantidadAtendida): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina_detalle')->insert([
            'id' => $id,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => $cantidad,
            'cantidad_atendida' => $cantidadAtendida,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createRQProserge(string $rqMinaId, string $minaId, string $rrhhId): string
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

    private function createPersonal(string $minaId, bool $esSupervisor): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Personal '.Str::upper(Str::random(4)),
            'puesto' => $esSupervisor ? 'Supervisor' : 'Tecnico',
            'es_supervisor' => $esSupervisor ? 1 : 0,
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $id,
            'mina_id' => $minaId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createGrupoTrabajo(string $supervisorId, string $rqMinaId, string $destinoTipo, string $destinoId, string $createdById): string
    {
        $id = (string) Str::uuid();

        DB::table('grupo_trabajo')->insert([
            'id' => $id,
            'fecha' => '2026-08-15',
            'supervisor_id' => $supervisorId,
            'mina' => 'Mina test',
            'unidad' => $destinoTipo,
            'destino_tipo' => $destinoTipo,
            'destino_id' => $destinoId,
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => null,
            'servicio' => 'Servicio operativo',
            'area' => 'Area test',
            'horario_salida' => '06:00:00',
            'turno' => 'DIA',
            'estado' => 'BORRADOR',
            'created_by_id' => $createdById,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
