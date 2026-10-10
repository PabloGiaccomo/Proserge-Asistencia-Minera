<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AsistenciaFaltasWebTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboards_web_usan_datos_reales_y_filtros_operativos(): void
    {
        $context = $this->createContext();
        $session = $this->sessionFor($context);

        $this->withSession($session)
            ->get(route('asistencia.index', ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Asistencia operativa')
            ->assertSee('MINA DASHBOARD REAL')
            ->assertSee('SERVICIO DASHBOARD REAL')
            ->assertDontSee('Juan Pérez');

        $this->withSession($session)
            ->get(route('faltas.index', ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Faltas e inasistencias')
            ->assertSee('TRABAJADOR DASHBOARD REAL')
            ->assertSee('MINA DASHBOARD REAL')
            ->assertSee('Pendiente de revisar');
    }

    public function test_formularios_web_envian_campos_del_flujo_real_y_anulacion_exige_motivo(): void
    {
        $context = $this->createContext();
        $session = $this->sessionFor($context);

        $this->withSession($session)
            ->get(route('asistencia.marcar', $context['group']))
            ->assertOk()
            ->assertSee('name="grupo_trabajo_detalle_id"', false)
            ->assertSee('name="estado"', false)
            ->assertDontSee('name="hora_marcado"', false)
            ->assertSee('name="motivo_estado"', false);

        $this->withSession($session)
            ->get(route('asistencia.masivo', $context['group']))
            ->assertOk()
            ->assertDontSee('name="hora_marcado"', false);

        $this->withSession($session)
            ->get(route('faltas.corregir', $context['absence']))
            ->assertOk()
            ->assertSee('name="motivo_correccion"', false)
            ->assertSee('name="hora_marcado"', false);

        $this->withSession($session)
            ->post(route('faltas.anular', $context['absence']), [])
            ->assertSessionHasErrors('motivo_anulacion');

        $this->withSession($session)
            ->post(route('faltas.anular', $context['absence']), ['motivo_anulacion' => 'Registro duplicado de prueba'])
            ->assertRedirect(route('faltas.index'));

        $this->assertDatabaseHas('faltas', [
            'id' => $context['absence'],
            'estado' => 'ANULADA',
            'motivo_anulacion' => 'Registro duplicado de prueba',
        ]);
    }

    public function test_dashboards_rechazan_periodos_excesivos(): void
    {
        $context = $this->createContext();
        $session = $this->sessionFor($context);

        foreach (['asistencia.index', 'faltas.index'] as $route) {
            $this->withSession($session)
                ->get(route($route, ['fecha_desde' => '2025-01-01', 'fecha_hasta' => '2026-09-30']))
                ->assertSessionHasErrors('fecha_hasta');
        }
    }

    private function createContext(): array
    {
        $permissions = PermissionCatalog::matrixFromSelections([
            'asistencias' => ['ver', 'registrar', 'cerrar', 'reabrir'],
            'faltas' => ['ver', 'corregir', 'anular'],
        ]);
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'nombre' => 'PLANNER WEB', 'permisos' => json_encode($permissions), 'estado' => 'ACTIVO']);

        $supervisor = $this->personal('SUPERVISOR DASHBOARD REAL', '70110001');
        $worker = $this->personal('TRABAJADOR DASHBOARD REAL', '70110002');
        $user = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $user,
            'email' => 'attendance-dashboard@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $role,
            'personal_id' => $supervisor,
            'estado' => 'ACTIVO',
        ]);

        $mine = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mine, 'nombre' => 'MINA DASHBOARD REAL', 'unidad_minera' => 'UM REAL', 'estado' => 'ACTIVO']);
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $user, 'mina_id' => $mine]);

        $rq = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rq,
            'mina_id' => $mine,
            'area' => 'OPERACIONES',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-30',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $user,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $group,
            'fecha' => '2026-09-20',
            'supervisor_id' => $supervisor,
            'mina' => 'MINA DASHBOARD REAL',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $mine,
            'rq_mina_id' => $rq,
            'servicio' => 'SERVICIO DASHBOARD REAL',
            'area' => 'OPERACIONES',
            'horario_salida' => '07:00:00',
            'turno' => 'DIA',
            'estado' => 'PROGRAMADO',
            'created_by_id' => $user,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupDetail = (string) Str::uuid();
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => $groupDetail,
            'grupo_trabajo_id' => $group,
            'personal_id' => $worker,
            'puesto_asignado_snapshot' => 'OPERARIO',
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendance = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $attendance,
            'grupo_trabajo_id' => $group,
            'fecha' => '2026-09-20',
            'hora_ingreso' => '07:00:00',
            'mina_id' => $mine,
            'destino_tipo' => 'MINA',
            'destino_id' => $mine,
            'supervisor_id' => $supervisor,
            'estado' => 'CERRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendanceDetail = (string) Str::uuid();
        DB::table('asistencia_detalle')->insert([
            'id' => $attendanceDetail,
            'asistencia_id' => $attendance,
            'grupo_trabajo_detalle_id' => $groupDetail,
            'trabajador_id' => $worker,
            'hora_marcado' => '00:00:00',
            'estado' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $absence = (string) Str::uuid();
        DB::table('faltas')->insert([
            'id' => $absence,
            'trabajador_id' => $worker,
            'fecha' => '2026-09-20',
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'descripcion' => 'Generada por cierre de prueba',
            'estado' => 'ACTIVA',
            'registrada_por_id' => $supervisor,
            'asistencia_encabezado_id' => $attendance,
            'asistencia_detalle_id' => $attendanceDetail,
            'destino_tipo' => 'MINA',
            'destino_id' => $mine,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('permissions', 'user', 'mine', 'group', 'groupDetail', 'absence');
    }

    private function personal(string $name, string $document): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $document,
            'numero_documento' => $document,
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-'.$document,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function sessionFor(array $context): array
    {
        return [
            'auth_token' => 'web-test-token',
            'user_id' => $context['user'],
            'user' => [
                'id' => $context['user'],
                'email' => 'attendance-dashboard@test.local',
                'rol' => 'PLANNER WEB',
                'permissions' => $context['permissions'],
                'mina_scopes' => [$context['mine']],
            ],
        ];
    }
}
