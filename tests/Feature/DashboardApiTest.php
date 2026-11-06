<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
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
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'inicio' => ['ver', 'dashboards'],
                'rq_mina' => ['dashboards'],
                'rq_proserge' => ['dashboards'],
                'man_power' => ['dashboards'],
            ])),
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

    public function test_inicio_solo_no_expone_datos_en_secciones_ni_indicadores_compuestos(): void
    {
        $this->setDashboardPermissions(['inicio' => ['ver']]);
        $user = $this->createUsuario($this->rolePlannerId);
        $mine = $this->createMina();
        $this->assignScope($user, $mine);
        $worker = $this->createPersonal($mine, false);
        $this->createEvaluation($mine, $worker, $user, 19);
        $this->createRQMina($mine, $user, 'BORRADOR');
        $this->withToken($this->createToken($user));

        foreach (['evaluaciones', 'rq-mina', 'rq-proserge', 'man-power', 'asistencia', 'faltas'] as $section) {
            $this->getJson('/api/v1/dashboard/'.$section)->assertForbidden()->assertJsonPath('code', 'DASHBOARD_FORBIDDEN');
        }
        $main = $this->getJson('/api/v1/dashboard/principal')->assertOk();
        foreach (['evaluaciones', 'rq_mina', 'rq_proserge', 'man_power', 'asistencia', 'faltas'] as $section) {
            $main->assertJsonPath('data.'.$section, null);
        }
        $this->assertNotEmpty($main->json('data.resumen'));
        $this->assertNotEmpty($main->json('data.alertas'));
        foreach (array_merge($main->json('data.resumen'), $main->json('data.alertas')) as $metric) {
            $this->assertNull($metric);
        }
    }

    public function test_dashboard_evaluaciones_filtra_tipo_propietario_mina_y_puntaje_valido(): void
    {
        $this->setDashboardPermissions(['inicio' => ['ver'], 'evaluaciones' => ['dashboards', 'ver_desempeno']]);
        $owner = $this->createUsuario($this->rolePlannerId);
        $other = $this->createUsuario($this->rolePlannerId);
        $mine = $this->createMina();
        $outside = $this->createMina();
        $this->assignScope($owner, $mine);
        $worker = $this->createPersonal($mine, false);
        $privateWorker = $this->createPersonal($mine, false);
        $outsideWorker = $this->createPersonal($outside, false);
        $this->createEvaluation($mine, $worker, $owner, 12);
        $this->createEvaluation($mine, $worker, $owner, 99);
        $this->createEvaluation($mine, $privateWorker, $other, 20);
        $this->createEvaluation($outside, $outsideWorker, $owner, 18);
        $this->withToken($this->createToken($owner));

        $this->getJson('/api/v1/dashboard/evaluaciones')->assertOk()
            ->assertJsonPath('data.evaluaciones_desempeno_total', 2)
            ->assertJsonPath('data.evaluaciones_supervisor_total', null)
            ->assertJsonPath('data.evaluaciones_residente_total', null)
            ->assertJsonPath('data.promedio_general_desempeno', 12)
            ->assertJsonCount(1, 'data.ranking_top')
            ->assertJsonPath('data.ranking_top.0.trabajador_id', $worker)
            ->assertJsonMissing(['trabajador_id' => $privateWorker])
            ->assertJsonMissing(['trabajador_id' => $outsideWorker]);
        $this->getJson('/api/v1/dashboard/evaluaciones?mina_id='.$outside)->assertOk()
            ->assertJsonPath('data.evaluaciones_desempeno_total', 0)->assertJsonCount(0, 'data.ranking_top');
        $this->getJson('/api/v1/dashboard/resumen')->assertOk()
            ->assertJsonPath('data.evaluaciones_desempeno_total', 2)->assertJsonPath('data.evaluaciones_supervisor_total', null);
    }

    public function test_dashboard_evaluaciones_respeta_roles_adicionales_activos_sin_ampliar_propietario(): void
    {
        $this->setDashboardPermissions(['inicio' => ['ver'], 'evaluaciones' => ['dashboards', 'ver_desempeno']]);
        $user = $this->createUsuario($this->rolePlannerId);
        $other = $this->createUsuario($this->rolePlannerId);
        $outside = $this->createMina();
        $worker = $this->createPersonal($outside, false);
        $this->createEvaluation($outside, $worker, $user, 12);
        $this->createEvaluation($outside, $worker, $other, 19);
        $role = DB::table('roles')->where('nombre', 'GERENTE')->value('id') ?? (string) Str::uuid();
        DB::table('roles')->updateOrInsert(['id' => $role], ['nombre' => 'GERENTE', 'permisos' => json_encode(PermissionCatalog::emptyMatrix()), 'estado' => 'INACTIVO']);
        DB::table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user, 'rol_id' => $role]);
        $this->withToken($this->createToken($user));

        $this->getJson('/api/v1/dashboard/evaluaciones')->assertOk()->assertJsonPath('data.evaluaciones_desempeno_total', 0);
        DB::table('roles')->where('id', $role)->update(['estado' => 'ACTIVO']);
        $this->getJson('/api/v1/dashboard/evaluaciones')->assertOk()
            ->assertJsonPath('data.evaluaciones_desempeno_total', 1)->assertJsonPath('data.promedio_general_desempeno', 12);
    }

    private function setDashboardPermissions(array $permissions): void
    {
        DB::table('roles')->where('id', $this->rolePlannerId)->update([
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
        ]);
    }

    private function createEvaluation(string $mine, string $worker, string $owner, int $score): void
    {
        DB::table('evaluacion_desempeno')->insert([
            'id' => (string) Str::uuid(), 'fecha' => '2026-08-19', 'mina_id' => $mine,
            'destino_tipo' => 'MINA', 'destino_id' => $mine, 'trabajador_id' => $worker,
            'evaluado_por_usuario_id' => $owner, 'origen' => 'ASISTENCIA', 'total' => $score,
            'desempeno_trabajo' => 2, 'orden_limpieza' => 2, 'compromiso' => 2,
            'respuesta_emocional' => 2, 'seguridad_trabajo' => 2,
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
