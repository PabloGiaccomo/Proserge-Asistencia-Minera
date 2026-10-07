<?php

namespace Tests\Feature;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Modules\Epps\Services\EppBalanceService;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppOperationConfirmationService;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Epps\Services\EppService;
use App\Modules\Epps\Support\EppSignaturePayload;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EppReturnServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private Personal $worker;

    private EppAlmacen $warehouse;

    private EppRegistro $epp;

    private EppEntrega $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO'])->load('rol');
        $this->worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'PRUEBA DEVOLUCION', 'puesto' => 'OPERARIO', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->warehouse = EppAlmacen::query()->create(['nombre' => 'DEVOLUCION '.Str::uuid()]);
        $this->epp = app(EppService::class)->storeCatalog(['nombre' => 'GUANTE DEVOLUCION', 'requiere_talla' => true, 'tallas' => 'M,L']);
        app(EppInventoryService::class)->openStock($this->warehouse, [['epp_id' => $this->epp->id, 'talla' => 'M', 'cantidad' => 5]], $this->actor, 'Conteo de prueba');
        $operation = $this->prepare('ENTREGA', [['epp_id' => $this->epp->id, 'talla' => 'M', 'cantidad_solicitada' => 5]]);
        $this->delivery = $this->confirm($operation)->entregas->first();
    }

    public function test_returning_two_of_five_leaves_three(): void
    {
        $return = $this->confirm($this->prepare('DEVOLUCION', [$this->line(2)]));
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('ENTREGADO', $this->delivery->fresh()->estado);
        $this->assertSame('RETURN_V1', $return->aceptacion->declaracion_version);
        $this->assertSame(2, $return->aceptacion->contenido_json['details'][0]['processed_quantity']);
        $this->assertSame(3, $return->aceptacion->contenido_json['details'][0]['snapshot']['saldo_restante']);
        $this->assertSame(0, $return->entregas()->count());
        $retry = $this->confirm($return);
        $this->assertSame($return->aceptacion->id, $retry->aceptacion->id);
        $this->assertSame(1, $this->moves()->count());
    }

    public function test_full_return_closes_balance(): void
    {
        $this->confirm($this->prepare('DEVOLUCION', [$this->line(5)]));
        $this->assertSame(0, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('DEVUELTO', $this->delivery->fresh()->estado);
        $this->assertNotNull($this->delivery->fresh()->devuelto_at);
    }

    public function test_return_above_balance_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$this->line(6)]);
    }

    public function test_duplicate_source_lines_are_accumulated_before_validation(): void
    {
        $this->epp->update(['permite_entrega_repetida' => true]);
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$this->line(3), $this->line(3, 'DANADO', 'CUARENTENA')]);
    }

    public function test_duplicate_source_with_different_conditions_returns_exact_total(): void
    {
        $operation = $this->confirm($this->prepare('DEVOLUCION', [$this->line(2), $this->line(1, 'DANADO', 'CUARENTENA')]));
        $this->assertSame(2, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(2, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->existence()->cantidad_cuarentena);
        $this->assertSame(3, (int) $this->moves()->sum('cantidad'));
        $this->assertCount(2, $operation->aceptacion->contenido_json['details']);
    }

    public function test_good_return_reenters_available_stock(): void
    {
        $this->confirm($this->prepare('DEVOLUCION', [$this->line(2)]));
        $this->assertSame(2, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_cuarentena);
        $move = $this->moves()->firstOrFail();
        $this->assertSame([0, 2, 'DISPONIBLE', 2], [$move->saldo_anterior, $move->saldo_posterior, $move->destino, $move->cantidad]);
    }

    public function test_damaged_return_enters_quarantine(): void
    {
        $this->confirm($this->prepare('DEVOLUCION', [$this->line(2, 'DANADO', 'CUARENTENA')]));
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(2, $this->existence()->cantidad_cuarentena);
        $this->assertSame('CUARENTENA', $this->moves()->firstOrFail()->destino);
    }

    public function test_worn_or_incomplete_return_can_be_disposed_without_available_stock(): void
    {
        $this->confirm($this->prepare('DEVOLUCION', [$this->line(1, 'DESGASTADO', 'BAJA'), $this->line(1, 'INCOMPLETO', 'CUARENTENA')]));
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->existence()->cantidad_cuarentena);
        $this->assertSame(1, $this->moves()->where('destino', 'BAJA')->firstOrFail()->cantidad);
    }

    public function test_damaged_return_cannot_be_available(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$this->line(2, 'DANADO', 'DISPONIBLE')]);
    }

    public function test_loss_does_not_add_stock(): void
    {
        $operation = app(EppBalanceService::class)->markLoss($this->delivery, 2, 'Perdida reportada', $this->actor, (string) Str::uuid());
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_cuarentena);
        $this->assertSame(0, $this->moves()->count());
        $this->assertSame(1, $operation->eventos()->where('accion', 'PERDIDA_REGISTRADA')->count());
    }

    public function test_loss_retry_after_lost_response_returns_original_operation(): void
    {
        $key = (string) Str::uuid();
        $service = app(EppBalanceService::class);
        $first = $service->markLoss($this->delivery, 2, 'Perdida reportada', $this->actor, $key);
        $retry = $service->markLoss($this->delivery, 2, 'Perdida reportada', $this->actor, $key);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($key, $retry->submission_uuid);
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(1, EppOperacion::query()->where('operacion_origen_id', $this->delivery->operacion_id)->where('tipo', 'CORRECCION')->count());
        $this->assertSame(1, $retry->eventos()->count());
        $this->assertSame(0, $retry->aceptacion()->count());
        $this->assertSame(0, $this->moves()->count());
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_cuarentena);
    }

    public function test_loss_retries_remain_valid_after_distinct_losses_close_balance(): void
    {
        $service = app(EppBalanceService::class);
        $first = $service->markLoss($this->delivery, 2, 'Primera perdida', $this->actor, $firstKey = (string) Str::uuid());
        $second = $service->markLoss($this->delivery, 3, 'Segunda perdida', $this->actor, $secondKey = (string) Str::uuid());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $service->markLoss($this->delivery, 2, 'Primera perdida', $this->actor, $firstKey)->id);
        $this->assertSame($second->id, $service->markLoss($this->delivery, 3, 'Segunda perdida', $this->actor, $secondKey)->id);
        $this->assertSame(0, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('PERDIDA_OLVIDO', $this->delivery->fresh()->estado);
        $this->assertSame(1, $first->eventos()->count());
        $this->assertSame(1, $second->eventos()->count());
        $this->assertSame(0, $this->moves()->count());
        $this->expectException(InvalidArgumentException::class);
        $service->markLoss($this->delivery, 1, 'Sin saldo', $this->actor, (string) Str::uuid());
    }

    public function test_loss_key_cannot_be_reused_for_different_quantity_reason_delivery_or_actor(): void
    {
        $service = app(EppBalanceService::class);
        $operation = $service->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $key = (string) Str::uuid());
        $otherDelivery = $this->delivery->replicate(['id']);
        $otherDelivery->id = (string) Str::uuid();
        $otherDelivery->save();
        $otherActor = $this->actor->replicate(['id']);
        $otherActor->id = (string) Str::uuid();
        $otherActor->email = Str::uuid().'@test.local';
        $otherActor->save();
        foreach ([[$this->delivery, 2, 'Perdida reportada', $this->actor], [$this->delivery, 1, 'Otro motivo', $this->actor], [$otherDelivery, 1, 'Perdida reportada', $this->actor], [$this->delivery, 1, 'Perdida reportada', $otherActor]] as $arguments) {
            try {
                $service->markLoss(...[...$arguments, $key]);
                $this->fail('La clave pertenece exclusivamente a la perdida original.');
            } catch (InvalidArgumentException) {
                $this->assertSame(4, $this->delivery->fresh()->cantidad_pendiente_devolucion);
                $this->assertSame(1, $operation->eventos()->count());
            }
        }
        $this->assertSame(5, $otherDelivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $this->moves()->count());
    }

    public function test_loss_key_cannot_replay_an_unrelated_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(EppBalanceService::class)->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $this->delivery->operacion->submission_uuid);
    }

    public function test_loss_replay_does_not_depend_on_json_object_key_order(): void
    {
        $service = app(EppBalanceService::class);
        $operation = $service->markLoss($this->delivery, 2, 'Perdida reportada', $this->actor, $key = (string) Str::uuid());
        $operation->update(['resumen_json' => ['cantidad' => 2, 'entrega_origen_id' => $this->delivery->id, 'accion' => 'PERDIDA_REGISTRADA']]);
        $this->assertSame($operation->id, $service->markLoss($this->delivery, 2, 'Perdida reportada', $this->actor, $key)->id);
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(1, $operation->eventos()->count());
    }

    public function test_loss_requires_valid_caller_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(EppBalanceService::class)->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, 'no-uuid');
    }

    public function test_loss_replay_reloads_revoked_actor(): void
    {
        $service = app(EppBalanceService::class);
        $service->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $key = (string) Str::uuid());
        Usuario::query()->whereKey($this->actor->id)->update(['estado' => 'INACTIVO']);
        $this->expectException(AuthorizationException::class);
        $service->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $key);
    }

    public function test_loss_replay_rechecks_current_worker_and_warehouse_scope(): void
    {
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA PERDIDA '.Str::uuid(), 'unidad_minera' => 'UM TEST']);
        $this->warehouse->update(['mina_id' => $mine->id]);
        $workerScope = PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'activo' => true]);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'CORREGIR EPP '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => ['epps' => ['corregir' => true]]]);
        $this->actor->update(['rol_id' => $role->id]);
        $actorScope = UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $this->actor->id, 'mina_id' => $mine->id]);
        $service = app(EppBalanceService::class);
        $operation = $service->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $key = (string) Str::uuid());
        foreach (['worker', 'warehouse'] as $scope) {
            if ($scope === 'worker') {
                $workerScope->update(['activo' => false]);
            } else {
                $workerScope->update(['activo' => true]);
                $actorScope->delete();
            }
            try {
                $service->markLoss($this->delivery, 1, 'Perdida reportada', $this->actor, $key);
                $this->fail('El replay exige alcance vigente sobre trabajador y almacen.');
            } catch (AuthorizationException) {
                $this->assertSame(4, $this->delivery->fresh()->cantidad_pendiente_devolucion);
                $this->assertSame(1, $operation->eventos()->count());
            }
        }
    }

    #[DataProvider('concurrentLossKeys')]
    public function test_concurrent_loss_keys_preserve_exactly_once_balances(bool $sameKey, int $operationCount, int $remaining): void
    {
        $keys = [(string) Str::uuid()];
        $keys[] = $sameKey ? $keys[0] : (string) Str::uuid();
        $root = storage_path('app/testing/loss-'.Str::uuid());
        $sourceSignature = $this->delivery->operacion->aceptacion->firma_path;
        $sourceBytes = Storage::disk('local')->get($sourceSignature);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        Storage::disk('local')->put($sourceSignature, $sourceBytes, ['visibility' => 'private']);
        $processes = [];
        $ready = [];
        $ledgerCount = EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count();
        DB::commit();
        try {
            DB::beginTransaction();
            EppOperacion::query()->lockForUpdate()->findOrFail($this->delivery->operacion_id);
            foreach ([0, 1] as $index) {
                $ready[$index] = $root.'/'.$index.'.ready';
                $process = new Process([PHP_BINARY, base_path('tests/Support/mark-epp-loss.php'), $this->delivery->id, $this->actor->id, $keys[$index], $ready[$index]], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                ]);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($ready[0]) || ! is_file($ready[1])) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    $process->isRunning();
                }
                usleep(10000);
            }
            foreach ($ready as $path) {
                $this->assertFileExists($path);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
            }
            DB::commit();
            foreach ($processes as $process) {
                $process->wait();
            }
            $results = array_map(fn ($process) => json_decode($process->getOutput(), true), $processes);
            $this->assertCount(2, array_filter($results, fn ($result) => isset($result['operacion_id'])), implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
            if ($sameKey) {
                $this->assertSame($results[0]['operacion_id'], $results[1]['operacion_id']);
            } else {
                $this->assertNotSame($results[0]['operacion_id'], $results[1]['operacion_id']);
            }
            foreach ($results as $index => $result) {
                $this->assertSame($keys[$index], $result['submission_uuid']);
                $operation = EppOperacion::query()->findOrFail($result['operacion_id']);
                $this->assertSame(1, $operation->eventos()->count());
                $this->assertSame(0, $operation->aceptacion()->count());
            }
            $this->assertSame($operationCount, EppOperacion::query()->where('operacion_origen_id', $this->delivery->operacion_id)->where('tipo', 'CORRECCION')->count());
            $this->assertSame($remaining, $this->delivery->fresh()->cantidad_pendiente_devolucion);
            $this->assertSame(0, $this->existence()->cantidad_disponible);
            $this->assertSame(0, $this->existence()->cantidad_cuarentena);
            $this->assertSame($ledgerCount, EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($ready as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            // Preserve committed append-only ledger/evidence; retire only this synthetic fixture.
            $this->worker->update(['estado' => 'CESADO']);
            $this->warehouse->update(['estado' => 'INACTIVO']);
            $this->actor->update(['estado' => 'INACTIVO']);
            $this->epp->update(['estado' => 'INACTIVO']);
            DB::beginTransaction();
        }
    }

    public static function concurrentLossKeys(): array
    {
        return ['same key retries once' => [true, 1, 3], 'different keys legitimate losses' => [false, 2, 1]];
    }

    public function test_consumables_never_appear_as_returnable(): void
    {
        $this->delivery->update(['politica_devolucion_snapshot' => 'CONSUMIBLE']);
        $this->assertFalse(app(EppBalanceService::class)->forWorker($this->worker, $this->actor)->contains('id', $this->delivery->id));
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$this->line(1)]);
    }

    public function test_ceased_worker_shows_only_positive_returnable_balances(): void
    {
        $this->worker->update(['estado' => 'CESADO']);
        $this->assertSame([$this->delivery->id], app(EppBalanceService::class)->forWorker($this->worker, $this->actor)->pluck('id')->all());
        $this->confirm($this->prepare('DEVOLUCION', [$this->line(5)]));
        $this->assertCount(0, app(EppBalanceService::class)->forWorker($this->worker, $this->actor));
        $this->assertSame('CESADO', $this->worker->fresh()->estado);
    }

    public function test_stale_prepared_return_revalidates_balance_at_confirmation(): void
    {
        $first = $this->prepare('DEVOLUCION', [$this->line(3)]);
        $second = $this->prepare('DEVOLUCION', [$this->line(3)]);
        $this->confirm($first);
        try {
            $this->confirm($second);
            $this->fail('El saldo ya no alcanza.');
        } catch (InvalidArgumentException) {
            $this->assertSame(2, $this->delivery->fresh()->cantidad_pendiente_devolucion);
            $this->assertSame(3, $this->existence()->cantidad_disponible);
            $this->assertSame(0, $second->aceptacion()->count());
        }
    }

    public function test_return_from_another_worker_is_rejected(): void
    {
        $other = $this->worker->replicate(['id']);
        $other->id = (string) Str::uuid();
        $other->fill(['dni' => (string) random_int(10000000, 99999999), 'qr_code' => (string) Str::uuid()])->save();
        $this->delivery->update(['personal_id' => $other->id]);
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$this->line(1)]);
    }

    public function test_concurrent_returns_cannot_exceed_balance(): void
    {
        $operations = [$this->prepare('DEVOLUCION', [$this->line(3)]), $this->prepare('DEVOLUCION', [$this->line(3)])];
        $root = storage_path('app/testing/returns-'.Str::uuid());
        $sourceSignature = $this->delivery->operacion->aceptacion->firma_path;
        $sourceBytes = Storage::disk('local')->get($sourceSignature);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        Storage::disk('local')->put($sourceSignature, $sourceBytes, ['visibility' => 'private']);
        $processes = [];
        $ready = [];
        DB::commit();
        try {
            DB::beginTransaction();
            EppAlmacen::query()->lockForUpdate()->findOrFail($this->warehouse->id);
            foreach ($operations as $index => $operation) {
                $ready[$index] = $root.'/'.$index.'.ready';
                $process = new Process([PHP_BINARY, base_path('tests/Support/confirm-epp-operation.php'), $operation->id, $this->actor->id, $ready[$index], $root], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                ]);
                $process->setInput(json_encode($this->signature($operation)->data, JSON_THROW_ON_ERROR));
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($ready[0]) || ! is_file($ready[1])) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    $process->isRunning();
                }
                usleep(10000);
            }
            foreach ($ready as $path) {
                $this->assertFileExists($path);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning());
            }
            DB::commit();
            foreach ($processes as $process) {
                $process->wait();
            }
            $results = array_map(fn ($process) => json_decode($process->getOutput(), true), $processes);
            $this->assertCount(1, array_filter($results, fn ($result) => isset($result['aceptacion_id'])), implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
            foreach ($results as $index => $result) {
                if ($result === null) {
                    $this->assertStringContainsString('saldo retornable vigente', $processes[$index]->getErrorOutput().$processes[$index]->getOutput());
                }
            }
            $this->assertSame(2, $this->delivery->fresh()->cantidad_pendiente_devolucion);
            $this->assertSame(3, $this->existence()->cantidad_disponible);
            $this->assertSame(3, (int) $this->moves()->sum('cantidad'));
            $this->assertSame(1, EppOperacion::query()->whereIn('id', collect($operations)->pluck('id'))->where('estado', 'FIRMADO')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($ready as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            // Preserve committed append-only ledger/evidence; retire synthetic actors and catalog.
            $this->worker->update(['estado' => 'CESADO']);
            $this->warehouse->update(['estado' => 'INACTIVO']);
            $this->actor->update(['estado' => 'INACTIVO']);
            $this->epp->update(['estado' => 'INACTIVO']);
            DB::beginTransaction();
        }
    }

    public function test_return_form_and_store_prepare_exact_selected_balance(): void
    {
        $session = ['auth_token' => 'test-token', 'user_id' => $this->actor->id, 'user' => ['id' => $this->actor->id]];
        $this->withSession($session)->get(route('epps.operations.returns.create', ['personal_id' => $this->worker->id, 'almacen_id' => $this->warehouse->id]))
            ->assertOk()->assertSee('Devolucion multiple')->assertSee('Saldo por devolver: 5');
        $response = $this->withSession($session)->post(route('epps.operations.returns.store'), ['tipo' => 'DEVOLUCION', 'personal_id' => $this->worker->id, 'almacen_id' => $this->warehouse->id, 'submission_uuid' => $uuid = (string) Str::uuid(), 'lines' => [$this->line(2)]]);
        $operation = EppOperacion::query()->where('submission_uuid', $uuid)->firstOrFail();
        $response->assertRedirect(route('epps.operations.review', $operation));
        $this->withSession($session)->get(route('epps.operations.review', $operation))->assertOk()->assertSee('Confirmo haber devuelto')->assertSee('Saldo restante: 3')->assertDontSee('Confirmo haber recibido');
        $this->assertSame(5, $this->delivery->fresh()->cantidad_pendiente_devolucion);
    }

    public function test_ceased_dashboard_lists_only_positive_returnable_quantities(): void
    {
        $name = 'CESE SALDO '.Str::uuid();
        $this->worker->update(['estado' => 'CESADO', 'nombre_completo' => $name]);
        $this->delivery->update(['cantidad_pendiente_devolucion' => 3]);
        foreach ([['CONSUMIBLE', 5, 'ENTREGADO'], ['RETORNABLE', 0, 'DEVUELTO']] as [$policy, $balance, $state]) {
            $copy = $this->delivery->replicate(['id']);
            $copy->id = (string) Str::uuid();
            $copy->fill(['politica_devolucion_snapshot' => $policy, 'cantidad_pendiente_devolucion' => $balance, 'estado' => $state, 'fecha_entrega' => now()->addDay()])->save();
        }
        $data = app(LogisticaDashboardService::class)->pageData(['tab' => 'cesados', 'q' => $name]);
        $row = collect($data['ceasedRows'])->firstWhere('personal_id', $this->worker->id);
        $this->assertNotNull($row);
        $this->assertCount(1, $row['items']);
        $this->assertSame(3, $row['items'][0]['cantidad']);
        $this->assertFalse($row['items'][0]['resuelto']);
    }

    public function test_return_overflow_rolls_back_acceptance_and_balance(): void
    {
        $this->existence()->update(['cantidad_disponible' => 4294967294, 'cantidad_reservada' => 1]);
        $operation = $this->prepare('DEVOLUCION', [$this->line(1)]);
        try {
            $this->confirm($operation);
            $this->fail('No debe desbordar disponible mas reservado.');
        } catch (InvalidArgumentException) {
            $this->assertSame(5, $this->delivery->fresh()->cantidad_pendiente_devolucion);
            $this->assertSame(0, $operation->aceptacion()->count());
            $this->assertSame(0, $this->moves()->count());
        }
    }

    public function test_changed_balance_requires_review_even_when_quantity_still_fits(): void
    {
        $first = $this->prepare('DEVOLUCION', [$this->line(1)]);
        $second = $this->prepare('DEVOLUCION', [$this->line(1)]);
        $this->confirm($first);
        try {
            $this->confirm($second);
            $this->fail('El saldo restante mostrado debe revisarse nuevamente.');
        } catch (InvalidArgumentException) {
            $this->assertSame(4, $this->delivery->fresh()->cantidad_pendiente_devolucion);
            $this->assertSame(0, $second->aceptacion()->count());
        }
    }

    public function test_legacy_return_uses_authorized_reconciled_receiving_warehouse(): void
    {
        $this->delivery->update(['operacion_id' => null, 'operacion_detalle_id' => null, 'es_legacy' => true, 'estado_integridad' => 'LEGACY_SIN_FIRMA']);
        $this->assertTrue(app(EppBalanceService::class)->forWorker($this->worker, $this->actor)->contains('id', $this->delivery->id));
        $operation = $this->confirm($this->prepare('DEVOLUCION', [$this->line(2)]));
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('LEGACY_SIN_FIRMA', $this->delivery->fresh()->estado_integridad);
        $this->assertNull($this->delivery->fresh()->operacion_id);
        $this->assertSame($this->delivery->id, $operation->aceptacion->contenido_json['details'][0]['original_delivery_id']);
        $this->assertSame(2, $this->existence()->cantidad_disponible);
    }

    public function test_return_preserves_source_policy_variant_and_inventory_after_catalog_change(): void
    {
        $this->epp->update(['estado' => 'INACTIVO', 'nombre' => 'NOMBRE POSTERIOR', 'politica_devolucion' => 'CONSUMIBLE', 'control_inventario' => false, 'tallas' => ['L']]);
        $return = $this->confirm($this->prepare('DEVOLUCION', [$this->line(2)]));
        $signed = $return->aceptacion->contenido_json['details'][0];
        $this->assertSame('RETORNABLE', $signed['return_policy']);
        $this->assertTrue($signed['inventory_control']);
        $this->assertSame('GUANTE DEVOLUCION', $signed['snapshot']['real']['nombre']);
        $this->assertSame(2, $this->existence()->cantidad_disponible);
    }

    public function test_other_condition_requires_description_and_quarantine_or_disposal(): void
    {
        $line = $this->line(1, 'OTRO', 'CUARENTENA');
        $operation = $this->confirm($this->prepare('DEVOLUCION', [$line + ['motivo' => 'Contaminado, requiere revision']]));
        $this->assertSame('OTRO', $operation->aceptacion->contenido_json['details'][0]['return_condition']);
        $this->assertSame(1, $this->existence()->cantidad_cuarentena);
        $this->expectException(InvalidArgumentException::class);
        $this->prepare('DEVOLUCION', [$line]);
    }

    public function test_apply_return_reloads_processed_detail_on_stale_retry(): void
    {
        $operation = $this->prepare('DEVOLUCION', [$this->line(2)]);
        $stale = $operation->detalles()->firstOrFail();
        $this->confirm($operation);
        $delivery = app(EppBalanceService::class)->applyReturn($stale, $this->actor);
        $this->assertSame(3, $delivery->cantidad_pendiente_devolucion);
        $this->assertSame(2, $this->existence()->cantidad_disponible);
        $this->assertSame(1, $this->moves()->count());
    }

    public function test_multiple_source_deliveries_keep_independent_remaining_balances(): void
    {
        app(EppInventoryService::class)->adjust($this->warehouse, $this->epp, ['TALLA' => 'M'], 2, $this->actor, 'Ingreso de prueba conciliado');
        $second = $this->confirm($this->prepare('ENTREGA', [['epp_id' => $this->epp->id, 'talla' => 'M', 'cantidad_solicitada' => 2, 'motivo' => 'Segunda entrega justificada']]))->entregas->first();
        $return = $this->confirm($this->prepare('DEVOLUCION', [$this->line(2), array_replace($this->line(2), ['entrega_origen_id' => $second->id])]));
        $this->assertSame(3, $this->delivery->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $second->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('DEVUELTO', $second->fresh()->estado);
        $this->assertSame(4, $this->existence()->cantidad_disponible);
        $this->assertSame(4, (int) $this->moves()->sum('cantidad'));
        $this->assertSame(4, collect($return->aceptacion->contenido_json['details'])->sum('processed_quantity'));
    }

    public function test_balance_lookup_requires_direct_epp_grant_even_with_worker_and_warehouse_scope(): void
    {
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA SALDOS '.Str::uuid(), 'unidad_minera' => 'UM TEST']);
        $this->warehouse->update(['mina_id' => $mine->id]);
        PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'activo' => true]);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SIN EPP '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => []]);
        $user = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $user->id, 'mina_id' => $mine->id]);
        $this->expectException(AuthorizationException::class);
        app(EppBalanceService::class)->forWorker($this->worker, $user);
    }

    private function line(int $quantity, string $condition = 'BUENO', string $destination = 'DISPONIBLE'): array
    {
        return ['epp_id' => $this->epp->id, 'talla' => 'M', 'entrega_origen_id' => $this->delivery->id, 'cantidad_solicitada' => $quantity, 'condicion_devolucion' => $condition, 'destino_inventario' => $destination];
    }

    private function prepare(string $type, array $lines): EppOperacion
    {
        $service = app(EppOperationService::class);
        $draft = $service->createDraft($this->actor, $this->worker, $this->warehouse, $type, (string) Str::uuid());

        return $service->prepareForSignature($service->syncDetails($draft, $lines, $this->actor), $this->actor);
    }

    private function signature(EppOperacion $operation): EppSignaturePayload
    {
        $image = imagecreatetruecolor(600, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetthickness($image, 3);
        $pen = imagecolorallocate($image, 10, 20, 30);
        imageline($image, 40, 160, 170, 50, $pen);
        imageline($image, 170, 50, 110, 210, $pen);
        imageline($image, 110, 210, 400, 100, $pen);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return EppSignaturePayload::fromArray(['submission_uuid' => $operation->submission_uuid, 'personal_id' => $operation->personal_id, 'review_hash' => $operation->resumen_sha256, 'review_version' => $operation->version, 'firma_data_url' => 'data:image/png;base64,'.base64_encode($bytes), 'ink' => ['point_count' => 40, 'bounds' => ['min_x' => 40, 'min_y' => 50, 'max_x' => 400, 'max_y' => 210]], 'tipo_firmante' => 'TRABAJADOR']);
    }

    private function confirm(EppOperacion $operation): EppOperacion
    {
        return app(EppOperationConfirmationService::class)->confirm($operation, $this->signature($operation), $this->actor);
    }

    private function existence(): EppExistencia
    {
        return EppExistencia::query()->where('almacen_id', $this->warehouse->id)->firstOrFail();
    }

    private function moves()
    {
        return EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id)->whereIn('tipo', ['DEVOLUCION', 'BAJA']);
    }
}
