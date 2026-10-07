<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppInventarioConfirmacion;
use App\Models\EppInventarioMovimiento;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppInventoryConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EppInventoryConfirmationConcurrencyTest extends TestCase
{
    // Independent MySQL processes need committed fixtures; cleanup never touches the inventory ledger.
    public function test_two_mysql_processes_confirm_one_token_with_one_warehouse_and_same_result(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->assertTrue(Schema::hasTable('epp_inventario_confirmaciones'));
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO'])->load('rol');
        $warehouseId = (string) Str::uuid();
        $preview = null;
        $processes = [];
        $readyPaths = [];
        try {
            $preview = app(EppInventoryConfirmationService::class)->preview($actor, 'almacen', ['new_warehouse_id' => $warehouseId, 'nombre' => 'CONCURRENCIA '.Str::uuid(), 'motivo' => 'Confirmacion simultanea de prueba']);
            DB::beginTransaction();
            EppInventarioConfirmacion::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $readyPaths[$i] = storage_path('app/testing/epp-confirm-'.$preview->id.'-'.$i.'.ready');
                $process = new Process([PHP_BINARY, base_path('tests/Support/confirm-epp-inventory.php'), $preview->id, $actor->id, 'almacen', $readyPaths[$i]], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                ]);
                $process->setTimeout(20)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($readyPaths[0]);
            $this->assertFileExists($readyPaths[1]);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'La confirmacion debe esperar el lock del token.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(['count' => 1, 'almacen_id' => $warehouseId], $results[0]);
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, EppAlmacen::query()->whereKey($warehouseId)->count());
            $this->assertNotNull($preview->fresh()->confirmado_at);
            $this->assertSame(0, EppInventarioMovimiento::query()->where('almacen_id', $warehouseId)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($readyPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $preview?->delete();
            EppAlmacen::query()->whereKey($warehouseId)->delete();
            $actor->delete();
            if ($role->wasRecentlyCreated) {
                $role->delete();
            }
        }
    }
}
