<?php

namespace Tests\Feature;

use App\Models\EppEntrega;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\RQMinaActividadTransporte;
use App\Models\Usuario;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticaDashboardServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_scope_counts_only_active_or_enabled_workers_and_splits_expirations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00'));

        $activeWorker = $this->createPersonal('ACTIVO', 'TRABAJADOR ACTIVO');
        $enabledWorker = $this->createPersonal('INACTIVO', 'TRABAJADOR HABILITADO');
        $outsideWorker = $this->createPersonal('INACTIVO', 'TRABAJADOR FUERA');
        $mineId = $this->createMine('BOROO');
        $eppId = $this->createEpp('CASCO DE SEGURIDAD');

        $this->attachMine($enabledWorker, $mineId, PersonalMina::ESTADO_HABILITADO);
        $this->attachMine($outsideWorker, $mineId, PersonalMina::ESTADO_NO_HABILITADO);
        $this->createDelivery($activeWorker, $eppId, '2026-06-01', '2026-07-01');
        $this->createDelivery($enabledWorker, $eppId, '2026-07-01', '2026-07-20');
        $this->createDelivery($outsideWorker, $eppId, '2026-06-01', '2026-07-02');

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'dashboard']);

        $this->assertSame(2, $data['metrics']['workers']);
        $this->assertSame(1, $data['metrics']['expired_epp']);
        $this->assertSame(1, $data['metrics']['expiring_epp']);
        $this->assertArrayNotHasKey('costo_pendiente_estimado', $data['metrics']);
    }

    public function test_proximos_vencimientos_incluye_dias_efectivos_usados_por_paradas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 08:00:00'));

        $worker = $this->createPersonal('ACTIVO', 'TRABAJADOR CON USO EFECTIVO');
        $mineId = $this->createMine('CERRO VERDE');
        $eppId = $this->createEpp('CASCO CON USO');

        $this->attachMine($worker, $mineId, PersonalMina::ESTADO_HABILITADO);
        $this->createDelivery($worker, $eppId, '2026-07-01', '2026-07-20');
        $this->createRqAssignment($worker, $mineId, '2026-07-03', '2026-07-06');

        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'vencimientos']);
        $row = collect($data['expiringDeliveries'])
            ->firstWhere('trabajador', 'TRABAJADOR CON USO EFECTIVO');

        $this->assertNotNull($row);
        $this->assertSame(4, $row['dias_uso_efectivo']);
        $this->assertSame(30, $row['vida_dias']);
        $this->assertSame('4 / 30 dias', $row['uso_efectivo']);
    }

    public function test_logistica_actualiza_requerimiento_de_transporte_de_rq_mina(): void
    {
        $mineId = $this->createMine('BOROO');
        $usuario = $this->createLogisticsUser();
        $transportId = $this->createRqTransportRequirement($mineId, $usuario->id);

        $updated = app(LogisticaDashboardService::class)->updateTransportRequirement($transportId, [
            'origen' => 'ALQUILADO',
            'placas_asignadas' => 'ABC-123; chofer Juan',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'comentario_cambio' => 'Asignado por proveedor local',
            'incidencia_operativa' => '',
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_PENDIENTE,
            'recepcion_observacion' => 'Pendiente de confirmacion final',
        ], $usuario);

        $this->assertSame(RQMinaActividadTransporte::ESTADO_ASIGNADO, $updated->estado_logistico);
        $this->assertSame('ABC-123; chofer Juan', $updated->placas_asignadas);
        $this->assertSame(3, $updated->dias_uso);
        $this->assertDatabaseHas('rq_mina_actividad_transporte_eventos', [
            'transporte_id' => $transportId,
            'estado_anterior' => RQMinaActividadTransporte::ESTADO_REQUERIDO,
            'estado_nuevo' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'usuario_id' => $usuario->id,
        ]);

        $rows = app(LogisticaDashboardService::class)->pageData(['tab' => 'servicios'])['serviceRows'];
        $row = collect($rows)->firstWhere('id', $transportId);

        $this->assertNotNull($row);
        $this->assertSame('Van 15, minibus 35', $row['solicitado']);
        $this->assertSame('ABC-123; chofer Juan', $row['placas_asignadas']);
    }

    private function createPersonal(string $state, string $name): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(43000000, 43999999),
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(43000000, 43999999),
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO',
            'ocupacion' => '',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::uuid(),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $state,
            'telefono_1' => '999999999',
            'telefono_2' => '',
            'correo' => Str::slug($name) . '@test.local',
        ]);
    }

    private function createMine(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name,
            'unidad_minera' => $name,
            'ubicacion' => '',
            'link_ubicacion' => '',
            'color' => '#0d9488',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createEpp(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('epp_registro')->insert([
            'id' => $id,
            'codigo' => 'EPP-' . Str::upper(Str::random(8)),
            'nombre' => $name,
            'categoria' => 'EPP',
            'precio_unitario' => 0,
            'stock' => 10,
            'vida_util_dias' => 30,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function attachMine(Personal $personal, string $mineId, string $state): void
    {
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'mina_id' => $mineId,
            'estado' => $state,
            'estado_habilitacion' => $state,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDelivery(Personal $personal, string $eppId, string $deliveryDate, string $expirationDate): void
    {
        DB::table('epp_entregas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'epp_id' => $eppId,
            'cantidad' => 1,
            'fecha_entrega' => $deliveryDate,
            'fecha_vencimiento_calendario' => $expirationDate,
            'vida_util_dias_snapshot' => 30,
            'estado' => EppEntrega::ESTADO_ENTREGADO,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRqAssignment(Personal $personal, string $mineId, string $startDate, string $endDate): void
    {
        $userId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $rqMinaId = (string) Str::uuid();
        $rqMinaDetalleId = (string) Str::uuid();
        $rqProsergeId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_DASHBOARD_TEST',
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $mineId,
            'area' => 'Operacion',
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_detalle')->insert([
            'id' => $rqMinaDetalleId,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'OPERARIO',
            'cantidad' => 1,
            'cantidad_atendida' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_proserge')->insert([
            'id' => $rqProsergeId,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $mineId,
            'responsable_rrhh_id' => $userId,
            'estado' => 'COMPLETADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_proserge_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personal->id,
            'puesto_asignado' => 'OPERARIO',
            'fecha_inicio' => $startDate,
            'fecha_fin' => $endDate,
            'estado' => 'ASIGNADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLogisticsUser(): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'LOGISTICA_TRANSPORTE_TEST',
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Usuario::query()->findOrFail($userId);
    }

    private function createRqTransportRequirement(string $mineId, string $userId): string
    {
        $rqMinaId = (string) Str::uuid();
        $groupId = (string) Str::uuid();
        $transportId = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $mineId,
            'area' => 'Mina Local - seguimiento',
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $groupId,
            'rq_mina_id' => $rqMinaId,
            'area_operativa' => 'Operacion',
            'modulo' => 'C2',
            'nombre' => 'Parada registrada',
            'observaciones' => null,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_transportes')->insert([
            'id' => $transportId,
            'grupo_id' => $groupId,
            'actividad_id' => null,
            'alcance' => 'Sector C2',
            'unidad_carga' => 'Personal',
            'origen' => null,
            'unidades_transporte' => 'Van 15, minibus 35',
            'placas_asignadas' => null,
            'fecha_inicio' => '2026-07-10',
            'fecha_fin' => '2026-07-12',
            'dias_uso' => 3,
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_REQUERIDO,
            'indicaciones' => 'Salida 5am desde garita',
            'comentario_cambio' => null,
            'incidencia_operativa' => null,
            'recepcion_fecha' => null,
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_PENDIENTE,
            'recepcion_observacion' => null,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $transportId;
    }
}
