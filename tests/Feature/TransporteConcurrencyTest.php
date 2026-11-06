<?php

namespace Tests\Feature;

use App\Models\TransporteServicio;
use App\Models\Usuario;
use App\Modules\Transporte\Services\TransportePlanningService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransporteConcurrencyTest extends TestCase
{
    private array $fixtureRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!app()->environment('testing') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || (int) config('database.connections.mysql.port') !== 13376
            || config('database.connections.mysql.database') !== 'proserge_app_test'
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
            $this->markTestSkipped('Configura el MySQL aislado 127.0.0.1:13376/proserge_app_test.');
        }

        $this->assertSame('proserge_app_test:13376', DB::scalar("SELECT CONCAT(DATABASE(), ':', @@port)"));
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtureRows) as [$table, $id]) {
                DB::table($table)->where('id', $id)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_assignments_cannot_take_the_last_seat(): void
    {
        [$actor, $service, , $firstDetail, $secondDetail] = $this->fixture();
        $planning = app(TransportePlanningService::class);

        $workerResult = $this->runWaitingOnService($service, [$actor->id, $service->id, 'assign', $secondDetail], function () use ($actor, $service, $firstDetail, $planning): void {
            $result = $planning->addPasajeros($actor, $service, ['grupo_trabajo_detalle_id' => $firstDetail]);
            $this->assertTrue($result['ok'], $result['code'] ?? '');
        });

        $this->assertSame(['ok' => false, 'code' => 'TRANSPORTE_CAPACITY_EXCEEDED'], $workerResult);
        $this->assertSame([$firstDetail], $service->pasajeros()->where('estado', 'ASIGNADO')->pluck('grupo_trabajo_detalle_id')->all());
        $this->assertSame(1, $service->eventos()->where('tipo', 'PASAJEROS_ASIGNADOS')->count());
    }

    public function test_opposite_relocations_use_stable_parent_order(): void
    {
        [$actor, $first, $second, $firstDetail, $secondDetail] = $this->fixture();
        $second->update(['capacidad' => 2]);
        $planning = app(TransportePlanningService::class);
        $this->assertTrue($planning->addPasajeros($actor, $first, ['grupo_trabajo_detalle_id' => $firstDetail])['ok']);
        $this->assertTrue($planning->addPasajeros($actor, $second, ['grupo_trabajo_detalle_id' => $secondDetail])['ok']);
        $firstPassenger = $first->pasajeros()->firstOrFail();
        $secondPassenger = $second->pasajeros()->firstOrFail();

        $workerResult = $this->runWaitingOnService($first, [$actor->id, $second->id, 'relocate', $secondPassenger->id, $first->id], function () use ($actor, $first, $second, $firstPassenger, $planning): void {
            $result = $planning->reubicarPasajero($actor, $first, $firstPassenger->id, $second->id, 'Traslado concurrente');
            $this->assertTrue($result['ok'], $result['code'] ?? '');
        });

        $this->assertSame(['ok' => true, 'code' => null], $workerResult);
        $this->assertSame('REUBICADO', $firstPassenger->fresh()->estado);
        $this->assertSame('REUBICADO', $secondPassenger->fresh()->estado);
        $this->assertSame([$secondDetail], $first->pasajeros()->where('estado', 'ASIGNADO')->pluck('grupo_trabajo_detalle_id')->all());
        $this->assertSame([$firstDetail], $second->pasajeros()->where('estado', 'ASIGNADO')->pluck('grupo_trabajo_detalle_id')->all());
        foreach ([$first, $second] as $service) {
            $this->assertSame(1, $service->eventos()->where('tipo', 'PASAJERO_RETIRADO')->count());
            $this->assertSame(2, $service->eventos()->where('tipo', 'PASAJEROS_ASIGNADOS')->count());
        }
    }

    private function runWaitingOnService(TransporteServicio $service, array $workerArgs, callable $whileWaiting): array
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/transporte-concurrency-worker.php'), ...$workerArgs], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '13376',
            'DB_DATABASE' => 'proserge_app_test',
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password') ?? '',
            'DB_URL' => '',
            'DB_SOCKET' => '',
        ], timeout: 30);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
        DB::beginTransaction();
        try {
            $owner = (int) DB::scalar('SELECT CONNECTION_ID()');
            TransporteServicio::query()->lockForUpdate()->findOrFail($service->id);
            $worker->start();
            $readyDeadline = microtime(true) + 10;
            while (!str_contains($worker->getOutput(), 'service_lock') && $worker->isRunning() && microtime(true) < $readyDeadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('service_lock', $worker->getOutput(), $worker->getErrorOutput());
            $requester = json_decode(strtok($worker->getOutput(), "\n"), true, flags: JSON_THROW_ON_ERROR)['connection'];

            $deadline = microtime(true) + 4;
            do {
                $blocked = DB::selectOne(
                    'SELECT 1
                     FROM information_schema.INNODB_LOCK_WAITS waits
                     JOIN information_schema.INNODB_TRX requester ON requester.trx_id = waits.requesting_trx_id
                     JOIN information_schema.INNODB_TRX holder ON holder.trx_id = waits.blocking_trx_id
                     WHERE requester.trx_mysql_thread_id = ? AND holder.trx_mysql_thread_id = ?',
                    [$requester, $owner],
                );
                if ($blocked) {
                    break;
                }
                usleep(25000);
            } while ($worker->isRunning() && microtime(true) < $deadline);
            $diagnostic = $blocked ? '' : json_encode(DB::select('SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.INNODB_TRX'));
            $this->assertNotNull($blocked, 'La segunda conexión debe esperar el lock del servicio. Worker '.$worker->getOutput().' '.$worker->getErrorOutput().' '.$diagnostic);

            $whileWaiting();
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());

            $lines = explode("\n", trim($worker->getOutput()));

            return json_decode(trim(end($lines)), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    private function fixture(): array
    {
        $role = $this->insert('roles', [
            'nombre' => 'TRANS_CONC_'.Str::random(8),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['transportes' => ['actualizar']])),
            'estado' => 'ACTIVO',
        ]);
        $actor = $this->insert('usuarios', [
            'email' => Str::uuid().'@test.local',
            'password' => bcrypt('test-secret'),
            'rol_id' => $role,
            'estado' => 'ACTIVO',
        ]);
        $mine = $this->insert('minas', ['nombre' => 'Concurrencia '.Str::random(8), 'unidad_minera' => 'TEST']);
        $this->insert('usuario_mina_scope', ['usuario_id' => $actor, 'mina_id' => $mine]);
        $rq = $this->insert('rq_mina', [
            'mina_id' => $mine, 'area' => 'TEST',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-02',
            'created_by_usuario_id' => $actor,
        ]);
        $group = $this->insert('grupo_trabajo', [
            'rq_mina_id' => $rq, 'fecha' => '2026-08-01', 'turno' => 'DIA',
            'area' => 'TEST', 'servicio' => 'TEST', 'horario_salida' => '06:00',
        ]);

        $details = [];
        $services = [];
        for ($index = 0; $index < 2; $index++) {
            $document = (string) random_int(20000000, 90000000);
            $person = $this->insert('personal', [
                'dni' => $document, 'nombre_completo' => 'Pasajero de prueba',
                'puesto' => 'Operario', 'qr_code' => (string) Str::uuid(),
            ]);
            $details[] = $this->insert('grupo_trabajo_detalle', ['grupo_trabajo_id' => $group, 'personal_id' => $person]);
            $service = $this->insert('transporte_servicios', [
                'rq_mina_id' => $rq, 'tipo' => 'PERSONAL', 'capacidad' => 1, 'fecha' => '2026-08-01',
                'turno' => 'A', 'tramo' => 'IDA', 'estado' => 'BORRADOR',
            ]);
            $this->insert('transporte_servicio_alcances', ['transporte_servicio_id' => $service, 'grupo_trabajo_id' => $group]);
            $services[] = $service;
        }

        sort($services, SORT_STRING);

        return [Usuario::query()->findOrFail($actor), TransporteServicio::query()->findOrFail($services[0]), TransporteServicio::query()->findOrFail($services[1]), ...$details];
    }

    private function insert(string $table, array $fields): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, ...$fields]);
        $this->fixtureRows[] = [$table, $id];

        return $id;
    }
}
