<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluacionesWebTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rol_solo_desempeno_entra_directo_sin_otras_pestanas(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['fecha' => '2026-08-21']));

        $response->assertOk()
            ->assertSee('Evaluaciones pendientes del día')
            ->assertDontSee('Evaluación realizada por residentes')
            ->assertDontSee('Seguimiento mensual');
    }

    public function test_encargado_registra_evaluacion_diaria_sobre_20(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId, $detailId, $workerId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'eval-csrf'])
            ->post(route('evaluaciones.desempeno.store'), [
                '_token' => 'eval-csrf',
                'asistencia_detalle_id' => $detailId,
                'desempeno_trabajo' => 4,
                'orden_limpieza' => 4,
                'seguridad_trabajo' => 4,
                'compromiso' => 4,
                'respuesta_emocional' => 4,
                'observaciones' => 'Buen trabajo diario',
            ])
            ->assertRedirect(route('evaluaciones.index', ['tipo' => 'desempeno']));

        $this->assertDatabaseHas('evaluacion_desempeno', [
            'asistencia_detalle_id' => $detailId,
            'trabajador_id' => $workerId,
            'supervisor_id' => $personalId,
            'evaluado_por_usuario_id' => $userId,
            'total' => 20,
            'tuvo_incidencia' => 0,
            'descripcion_incidencia' => null,
        ]);
    }

    public function test_no_permite_duplicar_evaluacion_de_la_misma_asistencia(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $payload = [
            '_token' => 'eval-csrf',
            'asistencia_detalle_id' => $detailId,
            'desempeno_trabajo' => 3,
            'orden_limpieza' => 3,
            'seguridad_trabajo' => 3,
            'compromiso' => 3,
            'respuesta_emocional' => 3,
        ];

        $session = [...$this->sessionFor($userId), '_token' => 'eval-csrf'];
        $this->withSession($session)->post(route('evaluaciones.desempeno.store'), $payload)->assertSessionHasNoErrors();
        $this->withSession($session)->from(route('evaluaciones.index'))->post(route('evaluaciones.desempeno.store'), $payload)
            ->assertSessionHasErrors('evaluacion');

        $this->assertSame(1, DB::table('evaluacion_desempeno')->where('asistencia_detalle_id', $detailId)->count());
    }

    public function test_catalogo_publica_permisos_separados(): void
    {
        $actions = PermissionCatalog::availableModuleActions()['evaluaciones'];

        foreach (['ver_desempeno', 'evaluar_desempeno', 'ver_supervisores', 'evaluar_supervisores', 'ver_residentes', 'evaluar_residentes'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_permisos_especificos_habilitan_supervisor_y_residente(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => [
                'ver_desempeno',
                'evaluar_desempeno',
                'ver_supervisores',
                'evaluar_supervisores',
                'ver_residentes',
                'evaluar_residentes',
            ],
        ]);
        [$mineId] = $this->createClosedAttendance($userId, $personalId);
        $candidateId = $this->createPersonal('SUPERVISOR EVALUADO BUSCABLE');
        $residentWithoutMineId = $this->createPersonal('RESIDENTE BUSCABLE SIN MINA');
        $this->assignScope($userId, $mineId);
        DB::table('personal')->where('id', $residentWithoutMineId)->update(['estado' => 'CESADO']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'supervisores']))
            ->assertOk()
            ->assertSee('Nueva evaluación de supervisor')
            ->assertSee('Bloque A: Competencias Técnicas (Asociadas al Cargo)')
            ->assertSee('Bloque B: Desempeño en SSOMA')
            ->assertSee('Bloque C: Habilidades blandas')
            ->assertSee('Buscar por nombre, DNI o puesto');

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['q' => 'SUPERVISOR EVALUADO']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $candidateId)
            ->assertJsonPath('data.0.nombre', 'SUPERVISOR EVALUADO BUSCABLE');

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'residentes']))
            ->assertOk()
            ->assertSee('Buscar por nombre, DNI o puesto')
            ->assertDontSee('Unidad minera');

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['q' => 'RESIDENTE BUSCABLE']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $residentWithoutMineId)
            ->assertJsonPath('data.0.estado', 'CESADO');

        $session = [...$this->sessionFor($userId), '_token' => 'eval-csrf'];
        $this->withSession($session)->post(route('evaluaciones.supervisores.store'), [
            '_token' => 'eval-csrf',
            'mina_id' => $mineId,
            'evaluado_id' => $candidateId,
            'fecha' => '2026-08-21',
            'respuestas' => ['A1' => 4, 'B1' => 4, 'C1' => 4],
        ])->assertRedirect(route('evaluaciones.index', ['tipo' => 'supervisores']));

        $this->withSession($session)->post(route('evaluaciones.residentes.store'), [
            '_token' => 'eval-csrf',
            'residente_id' => $residentWithoutMineId,
            'periodo_mes' => '2026-08',
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA', 'REPORTE_EVALUACION_DESEMPENO', 'ENTREGA_INFORMES', 'ENTREGA_PROTOCOLOS'],
            'costos_servicio_items' => ['COSTOS_MENSUALES', 'CURVA_S'],
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
            'comentarios' => 'Cumplimiento mensual completo.',
        ])->assertRedirect(route('evaluaciones.index', ['tipo' => 'residentes']));

        $this->assertDatabaseHas('evaluacion_supervisor', [
            'evaluador_id' => $personalId,
            'evaluado_id' => $candidateId,
        ]);
        $this->assertDatabaseHas('evaluacion_residente', [
            'evaluador_id' => $personalId,
            'residente_id' => $residentWithoutMineId,
            'periodo_mes' => '2026-08-01',
            'destino_tipo' => null,
            'destino_id' => null,
            'total' => 20,
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
        ]);
    }

    private function createUser(array $permissions): array
    {
        $roleId = (string) Str::uuid();
        $personalId = $this->createPersonal('ENCARGADO DE ASISTENCIA', true);
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'EVALUADOR_'.Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return [$userId, $personalId, $roleId];
    }

    private function createClosedAttendance(string $userId, string $supervisorId): array
    {
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA EVALUACION', 'unidad_minera' => 'UM EVAL', 'estado' => 'ACTIVO']);
        $workerId = $this->createPersonal('TECNICO EVALUADO');
        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $mineId,
            'area' => 'MANTENIMIENTO',
            'fecha_inicio' => '2026-08-21',
            'fecha_fin' => '2026-08-21',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $groupId,
            'fecha' => '2026-08-21',
            'supervisor_id' => $supervisorId,
            'mina' => 'MINA EVALUACION',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'rq_mina_id' => $rqId,
            'servicio' => 'SERVICIO DIARIO',
            'area' => 'MANTENIMIENTO',
            'horario_salida' => '07:00:00',
            'turno' => 'DIA',
            'estado' => 'CERRADO',
            'created_by_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendanceId = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $attendanceId,
            'grupo_trabajo_id' => $groupId,
            'fecha' => '2026-08-21',
            'hora_ingreso' => '07:00:00',
            'mina_id' => $mineId,
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'supervisor_id' => $supervisorId,
            'estado' => 'CERRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detailId = (string) Str::uuid();
        DB::table('asistencia_detalle')->insert([
            'id' => $detailId,
            'asistencia_id' => $attendanceId,
            'trabajador_id' => $workerId,
            'hora_marcado' => '07:02:00',
            'estado' => 'PRESENTE',
            'marcado_por_id' => $userId,
            'marcado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$mineId, $detailId, $workerId];
    }

    private function createPersonal(string $name, bool $supervisor = false): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name,
            'puesto' => $supervisor ? 'RESPONSABLE' : 'TECNICO',
            'es_supervisor' => $supervisor,
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignScope(string $userId, string $mineId): void
    {
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $userId, 'mina_id' => $mineId]);
    }

    private function sessionFor(string $userId): array
    {
        $user = DB::table('usuarios')->where('id', $userId)->first();
        $role = DB::table('roles')->where('id', $user->rol_id)->first();

        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => $user->email,
                'name' => 'USUARIO EVALUADOR',
                'rol' => $role->nombre,
                'personal_id' => $user->personal_id,
                'permissions' => json_decode($role->permisos, true),
            ],
        ];
    }
}
