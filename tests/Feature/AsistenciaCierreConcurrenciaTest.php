<?php

namespace Tests\Feature;

use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\Asistencia\Services\AsistenciaService;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AsistenciaCierreConcurrenciaTest extends TestCase
{
    private string $primary;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->primary = DB::getDefaultConnection();
        config(['database.connections.attendance_writer' => config('database.connections.'.$this->primary)]);
        DB::connection()->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::connection('attendance_writer')->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->travelTo(CarbonImmutable::parse('2034-01-14 06:50:00', 'America/Lima'));

        $this->ids = array_fill_keys(['personal', 'roles', 'usuarios', 'minas', 'rq_mina', 'grupo_trabajo', 'grupo_trabajo_detalle'], '');
        $this->ids = array_map(fn () => (string) Str::uuid(), $this->ids);
        DB::transaction(function (): void {
            $ids = $this->ids;
            DB::table('personal')->insert(['id' => $ids['personal'], 'dni' => (string) random_int(10000000, 99999999), 'qr_code' => 'TEST-'.$ids['personal'], 'nombre_completo' => 'Responsable concurrencia', 'puesto' => 'Operario']);
            DB::table('roles')->insert(['id' => $ids['roles'], 'nombre' => 'ASIST_'.Str::random(12), 'estado' => 'ACTIVO', 'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['mi_asistencia' => ['ver'], 'asistencias' => ['reabrir']]))]);
            DB::table('usuarios')->insert(['id' => $ids['usuarios'], 'email' => $ids['usuarios'].'@test.local', 'password' => 'unused', 'rol_id' => $ids['roles'], 'personal_id' => $ids['personal']]);
            DB::table('minas')->insert(['id' => $ids['minas'], 'nombre' => 'Mina '.$ids['minas'], 'unidad_minera' => 'Prueba', 'estado' => 'ACTIVO']);
            DB::table('rq_mina')->insert(['id' => $ids['rq_mina'], 'mina_id' => $ids['minas'], 'area' => 'Operaciones', 'fecha_inicio' => '2034-01-14', 'fecha_fin' => '2034-01-14', 'created_by_usuario_id' => $ids['usuarios']]);
            DB::table('grupo_trabajo')->insert(['id' => $ids['grupo_trabajo'], 'fecha' => '2034-01-14', 'servicio' => 'Prueba concurrencia', 'area' => 'Operaciones', 'horario_salida' => '07:00:00', 'estado' => 'PROGRAMADO', 'supervisor_id' => $ids['personal'], 'rq_mina_id' => $ids['rq_mina']]);
            DB::table('grupo_trabajo_detalle')->insert(['id' => $ids['grupo_trabajo_detalle'], 'grupo_trabajo_id' => $ids['grupo_trabajo'], 'personal_id' => $ids['personal'], 'estado_distribucion' => 'ASIGNADO', 'estado_asistencia' => 'AUSENTE']);
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ids !== []) {
                DB::setDefaultConnection($this->primary);
                DB::table('faltas')->where('trabajador_id', $this->ids['personal'])->delete();
                $header = DB::table('asistencia_encabezado')->where('grupo_trabajo_id', $this->ids['grupo_trabajo'])->value('id');
                if ($header) {
                    DB::table('asistencia_detalle')->where('asistencia_id', $header)->delete();
                    DB::table('asistencia_encabezado')->where('id', $header)->delete();
                }
                DB::table('parada_ejecucion_resumen')->where('rq_mina_id', $this->ids['rq_mina'])->delete();
                foreach (array_reverse($this->ids) as $table => $id) {
                    DB::table($table)->where('id', $id)->delete();
                }
                DB::purge('attendance_writer');
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function modes(): array
    {
        return ['individual' => [false], 'masivo' => [true]];
    }

    #[DataProvider('modes')]
    public function test_cierre_al_primer_commit_observa_la_marca_y_no_genera_falta(bool $massive): void
    {
        $closed = null;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$closed): void {
            if ($event->connection->getName() !== $this->primary || $event->connection->transactionLevel() !== 0 || $closed !== null) {
                return;
            }
            $closed = $this->otherConnection(fn (Usuario $user, GrupoTrabajo $group) => app(AsistenciaService::class)->cerrar($user, $group, []));
        });

        $this->assertTrue($this->mark($massive)['ok']);
        $this->assertTrue($closed['ok']);
        $this->assertDatabaseHas('asistencia_detalle', ['grupo_trabajo_detalle_id' => $this->ids['grupo_trabajo_detalle'], 'estado' => 'PRESENTE']);
        $this->assertDatabaseMissing('faltas', ['trabajador_id' => $this->ids['personal'], 'estado' => 'ACTIVA']);
    }

    #[DataProvider('modes')]
    public function test_cierre_antes_del_bloqueo_rechaza_marca_y_conserva_falta(bool $massive): void
    {
        $this->beforeGroupLock(fn (Usuario $user, GrupoTrabajo $group) => $this->assertTrue(app(AsistenciaService::class)->cerrar($user, $group, [])['ok']));
        $this->assertSame('ASISTENCIA_ALREADY_CLOSED', $this->mark($massive)['code']);
        $this->assertDatabaseHas('asistencia_detalle', ['grupo_trabajo_detalle_id' => $this->ids['grupo_trabajo_detalle'], 'estado' => 'AUSENTE']);
        $this->assertDatabaseHas('faltas', ['trabajador_id' => $this->ids['personal'], 'estado' => 'ACTIVA']);
    }

    public function test_reabre_antes_del_bloqueo_y_la_marca_conserva_la_anulacion(): void
    {
        $this->assertTrue($this->otherConnection(fn (Usuario $user, GrupoTrabajo $group) => app(AsistenciaService::class)->cerrar($user, $group, []))['ok']);
        $this->beforeGroupLock(fn (Usuario $user, GrupoTrabajo $group) => $this->assertTrue(app(AsistenciaService::class)->reabrir($user, $group)['ok']));

        $this->assertTrue($this->mark(false)['ok']);
        $this->assertDatabaseHas('asistencia_detalle', ['grupo_trabajo_detalle_id' => $this->ids['grupo_trabajo_detalle'], 'estado' => 'PRESENTE']);
        $this->assertDatabaseHas('faltas', ['trabajador_id' => $this->ids['personal'], 'estado' => 'ANULADA']);
    }

    public function test_cancelacion_antes_del_bloqueo_rechaza_marca_de_modelo_desactualizado(): void
    {
        $this->beforeGroupLock(fn (Usuario $user, GrupoTrabajo $group) => $group->update(['estado' => GrupoTrabajo::ESTADO_CANCELADO]));

        $this->assertSame('ASISTENCIA_GROUP_CANCELLED', $this->mark(false)['code'] ?? null);
        $this->assertDatabaseMissing('asistencia_encabezado', ['grupo_trabajo_id' => $this->ids['grupo_trabajo']]);
    }

    private function beforeGroupLock(callable $operation): void
    {
        $called = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($operation, &$called): void {
            if (!$called && str_contains($sql, '`grupo_trabajo`') && str_contains(strtolower($sql), 'for update') && in_array($this->ids['grupo_trabajo'], $bindings, true)) {
                $called = true;
                $this->otherConnection($operation);
            }
        });
    }

    private function mark(bool $massive): array
    {
        $user = Usuario::query()->findOrFail($this->ids['usuarios']);
        $group = GrupoTrabajo::query()->findOrFail($this->ids['grupo_trabajo']);
        $service = app(AsistenciaService::class);

        return $massive
            ? $service->marcarMasivo($user, $group, ['grupo_trabajo_detalle_ids' => [$this->ids['grupo_trabajo_detalle']], 'estado' => 'PRESENTE'])
            : $service->marcar($user, $group, ['grupo_trabajo_detalle_id' => $this->ids['grupo_trabajo_detalle'], 'estado' => 'PRESENTE']);
    }

    private function otherConnection(callable $operation): mixed
    {
        DB::setDefaultConnection('attendance_writer');
        try {
            return $operation(Usuario::query()->findOrFail($this->ids['usuarios']), GrupoTrabajo::query()->findOrFail($this->ids['grupo_trabajo']));
        } finally {
            DB::setDefaultConnection($this->primary);
        }
    }
}
