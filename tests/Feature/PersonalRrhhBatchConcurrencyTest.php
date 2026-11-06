<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalIngreso;
use App\Models\PersonalPuesto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoSignedBatchService;
use App\Modules\Personal\Services\PersonalIngresoBatchReviewService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PersonalRrhhBatchConcurrencyTest extends TestCase
{
    // Workers need committed fixtures. Each test owns its UUIDs and a private temporary disk.
    private array $actorIds = [];

    private array $roleIds = [];

    private array $personIds = [];

    private array $ingresoIds = [];

    private array $ingresoLotIds = [];

    private array $signedLotIds = [];

    private array $documents = [];

    private ?string $puestoId = null;

    private ?string $diskRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || (int) config('database.connections.mysql.port') !== 13376
            || config('database.connections.mysql.database') !== 'proserge_app_test'
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
            $this->markTestSkipped('Configura MySQL aislado 127.0.0.1:13376/proserge_app_test.');
        }
        $this->assertSame('proserge_app_test:13376', DB::scalar("SELECT CONCAT(DATABASE(), ':', @@port)"));
        config(['database.connections.rrhh_monitor' => config('database.connections.mysql')]);
        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proserge-rrhh-batch-'.Str::uuid();
        File::makeDirectory($this->diskRoot);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        Storage::purge('local');
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupFixtures();
        } finally {
            if ($this->diskRoot && realpath(dirname($this->diskRoot)) === realpath(sys_get_temp_dir())
                && preg_match('/^proserge-rrhh-batch-[a-f0-9-]{36}$/D', basename($this->diskRoot))) {
                File::deleteDirectory($this->diskRoot);
            }
            Storage::purge('local');
            DB::purge('rrhh_monitor');
            parent::tearDown();
        }
    }

    public function test_two_new_hire_rows_with_the_same_document_create_one_worker_and_contract(): void
    {
        $document = $this->newDocument();
        $first = $this->ingresoBatch($this->actor(), $document);
        $second = $this->ingresoBatch($this->actor(), $document);
        $results = $this->race([$first, $second], 'personal_insert', function () use ($document): void {
            // An uncommitted unique-key owner makes both real insertions wait before either can win.
            Personal::query()->create([
                'id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
                'nombre_completo' => 'Barrera temporal', 'puesto' => 'Prueba',
                'qr_code' => (string) Str::uuid(), 'estado' => 'FALTA_CONTRATO',
            ]);
        });

        $this->assertSame(1, collect($results)->where('estado', 'ACEPTADA')->count());
        $this->assertSame(1, Personal::query()->where('dni', $document)->count());
        $person = Personal::query()->where('dni', $document)->sole();
        $this->assertSame(1, $person->contratosLaborales()->count());
        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $person->contratosLaborales()->sole()->estado);
        $this->assertNotSame('ACTIVO', $person->estado);
        $this->assertTrue((bool) $person->pendiente_contrato_firmado);

        foreach ([$first, $second] as $batch) {
            $result = $this->confirm($batch);
            $this->assertContains($result['estado'], ['ACEPTADA', 'REVISAR']);
        }
        $this->assertSame(1, DB::table('personal_ingreso_lote_filas')->whereIn('lote_id', $this->ingresoLotIds)->where('estado', 'ACEPTADA')->count());
        $this->assertSame(1, $person->contratosLaborales()->count());
        $this->assertSame(1, PersonalIngreso::query()->whereKey($this->ingresoIds)->where('estado', PersonalIngreso::ESTADO_ACEPTADA)->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('personal_fichas')));
    }

    public function test_same_pdf_in_independent_batches_can_only_sign_one_contract(): void
    {
        $firstActor = $this->actor();
        $secondActor = $this->actor();
        $pdf = $this->pdf();
        $hash = hash_file('sha256', $pdf->getRealPath());
        $first = $this->signedBatch($firstActor, $this->contract(), $pdf);
        $second = $this->signedBatch($secondActor, $this->contract(), $pdf);
        $guardLot = app(PersonalContratoSignedBatchService::class)->start($firstActor);
        $this->signedLotIds[] = $guardLot;

        $results = $this->race([$first, $second], 'confirmed_hash', function () use ($guardLot, $hash): void {
            DB::table('personal_contrato_firmado_lote_archivos')->insert([
                'id' => (string) Str::uuid(), 'lote_id' => $guardLot, 'operacion_id' => (string) Str::uuid(),
                'orden' => 1, 'nombre' => 'Barrera temporal', 'sha256' => $hash,
                'confirmed_sha256' => $hash, 'estado' => 'GUARDADO', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertSame(1, collect($results)->whereIn('estado', ['GUARDADO', 'FIRMADO_FUTURO'])->count());
        foreach ([$first, $second] as $batch) {
            $this->assertContains($this->confirm($batch)['estado'], ['GUARDADO', 'FIRMADO_FUTURO', 'REVISAR']);
        }
        $contracts = PersonalContrato::query()->whereIn('personal_id', $this->personIds)->get();
        $signed = $contracts->filter->hasSignedFile();
        $this->assertCount(1, $signed);
        $this->assertSame(1, DB::table('personal_contrato_firmado_lote_archivos')->where('confirmed_sha256', $hash)->count());
        $this->assertSame(1, DB::table('personal_contrato_firmado_lote_archivos')->whereIn('lote_id', [$first['lot'], $second['lot']])->where('estado', 'REVISAR')->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('personal_contratos')));
        $this->assertSame($hash, hash_file('sha256', Storage::disk('local')->path($signed->sole()->signed_contract_path)));
        foreach ([$first, $second] as $batch) {
            $path = DB::table('personal_contrato_firmado_lote_archivos')->where('id', $batch['row'])->value('path');
            $this->assertSame($hash, hash_file('sha256', Storage::disk('local')->path($path)));
        }
    }

    public function test_same_new_hire_row_confirmed_twice_returns_one_persisted_result(): void
    {
        $batch = $this->ingresoBatch($this->actor(), $this->newDocument());
        $results = $this->race([$batch, $batch], 'ingreso_lot', function () use ($batch): void {
            DB::table('personal_ingreso_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        });

        $this->assertSame($results[0], $results[1]);
        $this->assertSame('ACEPTADA', $results[0]['estado']);
        $row = DB::table('personal_ingreso_lote_filas')->where('id', $batch['row'])->sole();
        $this->assertSame(1, Personal::query()->where('dni', $this->documents[0])->count());
        $this->assertSame(1, PersonalContrato::query()->where('personal_id', $row->personal_id)->count());
        $this->assertSame($row->contrato_id, PersonalContrato::query()->where('personal_id', $row->personal_id)->sole()->id);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('personal_fichas')));
    }

    public function test_same_signed_pdf_row_confirmed_twice_keeps_one_file_and_result(): void
    {
        $contract = $this->contract();
        $batch = $this->signedBatch($this->actor(), $contract, $this->pdf());
        $results = $this->race([$batch, $batch], 'signed_lot', function () use ($batch): void {
            DB::table('personal_contrato_firmado_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        });

        $this->assertSame($results[0], $results[1]);
        $this->assertSame('FIRMADO_FUTURO', $results[0]['estado']);
        $this->assertTrue($contract->refresh()->hasSignedFile());
        $path = $contract->signed_contract_path;
        $this->assertSame($results[0], $this->confirm($batch));
        $this->assertSame($path, $contract->refresh()->signed_contract_path);
        $this->assertSame($batch['actor']->id, $contract->signed_by_usuario_id);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('personal_contratos')));
        $this->assertSame(1, DB::table('personal_contrato_firmado_lote_archivos')->where('lote_id', $batch['lot'])->count());
    }

    public function test_new_hire_batch_rechecks_permission_after_waiting_for_the_lot_lock(): void
    {
        $batch = $this->ingresoBatch($this->actor(), $this->newDocument());
        $results = $this->race([$batch], 'ingreso_lot', function () use ($batch): void {
            DB::table('personal_ingreso_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        }, function () use ($batch): void {
            DB::table('roles')->where('id', $batch['actor']->rol_id)->update([
                'permisos' => json_encode(PermissionCatalog::emptyMatrix()),
            ]);
        });

        $this->assertSame(['estado' => 'HTTP_ERROR', 'status' => 403], $results[0]);
        $this->assertSame(0, Personal::query()->where('dni', $this->documents[0])->count());
        $this->assertDatabaseHas('personal_ingreso_lote_filas', ['id' => $batch['row'], 'estado' => 'LISTA']);
        $this->assertSame([], Storage::disk('local')->allFiles('personal_fichas'));
    }

    public function test_signed_batch_rechecks_permission_after_waiting_for_the_lot_lock(): void
    {
        $contract = $this->contract();
        $batch = $this->signedBatch($this->actor(), $contract, $this->pdf());
        $results = $this->race([$batch], 'signed_lot', function () use ($batch): void {
            DB::table('personal_contrato_firmado_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        }, function () use ($batch): void {
            DB::table('roles')->where('id', $batch['actor']->rol_id)->update([
                'permisos' => json_encode(PermissionCatalog::emptyMatrix()),
            ]);
        });

        $this->assertSame(['estado' => 'HTTP_ERROR', 'status' => 403], $results[0]);
        $this->assertFalse($contract->fresh()->hasSignedFile());
        $this->assertDatabaseHas('personal_contrato_firmado_lote_archivos', ['id' => $batch['row'], 'estado' => 'PENDIENTE']);
        $this->assertSame([], Storage::disk('local')->allFiles('personal_contratos'));
    }

    public function test_new_hire_batch_rejects_data_changed_while_waiting_for_the_lot_lock(): void
    {
        $batch = $this->ingresoBatch($this->actor(), $this->newDocument());
        $results = $this->race([$batch], 'ingreso_lot', function () use ($batch): void {
            DB::table('personal_ingreso_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        }, function (): void {
            $source = PersonalIngreso::query()->findOrFail($this->ingresoIds[0]);
            $source->update(['datos_json' => [...$source->datos_json, 'correo' => 'corregido@concurrency.test']]);
        });

        $this->assertSame('REVISAR', $results[0]['estado']);
        $this->assertSame(0, Personal::query()->where('dni', $this->documents[0])->count());
        $this->assertDatabaseHas('personal_ingreso_lote_filas', ['id' => $batch['row'], 'estado' => 'REVISAR']);
        $this->assertSame([], Storage::disk('local')->allFiles('personal_fichas'));
    }

    public function test_signed_batch_rejects_contract_changes_while_waiting_for_the_lot_lock(): void
    {
        $contract = $this->contract();
        $batch = $this->signedBatch($this->actor(), $contract, $this->pdf());
        $results = $this->race([$batch], 'signed_lot', function () use ($batch): void {
            DB::table('personal_contrato_firmado_lotes')->where('id', $batch['lot'])->lockForUpdate()->first();
        }, function () use ($contract): void {
            $contract->update(['remuneracion' => '9999']);
        });

        $this->assertSame('REVISAR', $results[0]['estado']);
        $this->assertFalse($contract->fresh()->hasSignedFile());
        $this->assertDatabaseHas('personal_contrato_firmado_lote_archivos', ['id' => $batch['row'], 'estado' => 'REVISAR']);
        $this->assertSame([], Storage::disk('local')->allFiles('personal_contratos'));
    }

    private function race(array $batches, string $barrier, callable $hold, ?callable $commitWhileWaiting = null): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            $owner = (int) DB::scalar('SELECT CONNECTION_ID()');
            $hold();
            foreach ($batches as $batch) {
                $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/personal-rrhh-batch-concurrency-worker.php'),
                    $batch['action'], $batch['actor']->id, $batch['lot'], $batch['revision'], $batch['row'],
                    $this->diskRoot, $barrier,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->diskRoot.'/absent-config.php',
                    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '13376',
                    'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'),
                    'DB_PASSWORD' => config('database.connections.mysql.password') ?? '', 'DB_URL' => '', 'DB_SOCKET' => '',
                    'LOG_CHANNEL' => 'stderr', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
                    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ], timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }

            $requesters = [];
            foreach ($workers as $index => $worker) {
                $deadline = microtime(true) + 10;
                while (! str_contains($worker->getOutput(), '"barrier"') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('"barrier"', $worker->getOutput(), $worker->getErrorOutput());
                $requesters[$index] = json_decode(strtok($worker->getOutput(), "\n"), true, flags: JSON_THROW_ON_ERROR)['connection'];
            }
            foreach ($workers as $index => $worker) {
                $requester = $requesters[$index];
                $deadline = microtime(true) + 4;
                do {
                    $waiting = $this->hasWaitPath($requester, $owner);
                    if ($waiting) {
                        break;
                    }
                    usleep(100000);
                } while ($worker->isRunning() && microtime(true) < $deadline);
                $diagnostic = $waiting ? '' : json_encode([
                    'owner' => $owner,
                    'requester' => $requester,
                    'transactions' => DB::connection('rrhh_monitor')->select('SELECT trx_id, trx_mysql_thread_id, trx_state, trx_query FROM information_schema.INNODB_TRX'),
                    'waits' => DB::connection('rrhh_monitor')->select('SELECT * FROM information_schema.INNODB_LOCK_WAITS'),
                ], JSON_THROW_ON_ERROR);
                $this->assertTrue($waiting, 'El worker debe esperar la barrera MySQL. '.$worker->getOutput().' '.$worker->getErrorOutput().' '.$diagnostic);
            }

            if ($commitWhileWaiting) {
                $commitWhileWaiting();
                DB::commit();
            } else {
                // Roll back the artificial unique-key owner so the domain transactions can compete.
                DB::rollBack();
            }
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['results'][0];
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function hasWaitPath(int $requester, int $owner): bool
    {
        $edges = collect(DB::connection('rrhh_monitor')->select(
            'SELECT requester.trx_mysql_thread_id AS requester, holder.trx_mysql_thread_id AS holder
             FROM information_schema.INNODB_LOCK_WAITS waits
             JOIN information_schema.INNODB_TRX requester ON requester.trx_id = waits.requesting_trx_id
             JOIN information_schema.INNODB_TRX holder ON holder.trx_id = waits.blocking_trx_id',
        ))->groupBy('requester');
        $pending = [$requester];
        $visited = [];
        while ($pending !== []) {
            $connection = array_pop($pending);
            if ($connection === $owner) {
                return true;
            }
            if (isset($visited[$connection])) {
                continue;
            }
            $visited[$connection] = true;
            foreach ($edges->get($connection, []) as $edge) {
                $pending[] = (int) $edge->holder;
            }
        }

        return false;
    }

    private function actor(): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'RRHH_CONCURRENT_'.Str::random(10), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections([
                'personal' => ['subir_contrato_firmado'],
                'personal_ingresos' => ['ver', 'aprobar', 'ver_datos_sensibles'],
            ]),
        ]);
        $this->roleIds[] = $role->id;
        $actor = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@concurrency.test', 'password' => 'synthetic',
            'rol_id' => $role->id, 'estado' => 'ACTIVO',
        ]);
        $this->actorIds[] = $actor->id;

        return $actor;
    }

    private function newDocument(): string
    {
        do {
            $document = (string) random_int(20000000, 89999999);
        } while (Personal::query()->where('dni', $document)->exists());
        $this->documents[] = $document;

        return $document;
    }

    private function ingresoBatch(Usuario $actor, string $document): array
    {
        if ($this->puestoId === null) {
            $position = PersonalPuesto::query()->create([
                'id' => (string) Str::uuid(), 'nombre' => 'OPERARIO CONCURRENCIA '.Str::upper(Str::random(8)), 'activo' => true,
            ]);
            $this->puestoId = $position->id;
        }
        $position = PersonalPuesto::query()->findOrFail($this->puestoId);
        $ingreso = PersonalIngreso::query()->create([
            'id' => (string) Str::uuid(), 'tipo_documento' => 'DNI', 'numero_documento' => $document,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA, 'submitted_at' => now(),
            'datos_json' => [
                'tipo_documento' => 'DNI', 'numero_documento' => $document,
                'nombres' => 'Prueba', 'apellido_paterno' => 'Concurrencia', 'apellido_materno' => 'Sintética',
                'puesto' => $position->nombre, 'correo' => 'sintetico@concurrency.test', 'telefono' => '900000001',
            ],
        ]);
        $this->ingresoIds[] = $ingreso->id;
        $path = 'personal_ingresos/'.$ingreso->id.'/documentos/prueba.pdf';
        Storage::disk('local')->put($path, 'Evidencia sintetica de prueba '.Str::uuid());
        DB::table('personal_ingreso_archivos')->insert([
            'id' => (string) Str::uuid(), 'personal_ingreso_id' => $ingreso->id, 'tipo' => 'dni',
            'nombre_original' => 'prueba.pdf', 'path' => $path, 'mime' => 'application/pdf',
            'size' => Storage::disk('local')->size($path), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $batch = app(PersonalIngresoBatchReviewService::class)->preview($actor, [[
            'ingreso_id' => $ingreso->id, 'fecha_inicio_contrato' => now()->toDateString(),
        ]]);
        $this->ingresoLotIds[] = $batch['id'];
        $this->assertSame('LISTA', $batch['filas'][0]['estado'], $batch['filas'][0]['mensaje'] ?? '');

        return ['action' => 'ingreso', 'actor' => $actor, 'lot' => $batch['id'], 'row' => $batch['filas'][0]['id'], 'revision' => $batch['revision']];
    }

    private function contract(): PersonalContrato
    {
        $person = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => $this->newDocument(), 'nombre_completo' => 'Contrato sintético',
            'puesto' => 'Prueba', 'qr_code' => (string) Str::uuid(), 'estado' => 'FALTA_CONTRATO',
        ]);
        $this->personIds[] = $person->id;

        return PersonalContrato::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $person->id, 'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_PREPARACION, 'fecha_inicio' => now('America/Lima')->addDay()->toDateString(),
        ]);
    }

    private function pdf(): UploadedFile
    {
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(40, 10, 'Prueba de concurrencia '.Str::uuid());

        return UploadedFile::fake()->createWithContent('contrato.pdf', $pdf->Output('S'));
    }

    private function signedBatch(Usuario $actor, PersonalContrato $contract, UploadedFile $pdf): array
    {
        $batches = app(PersonalContratoSignedBatchService::class);
        $lot = $batches->start($actor);
        $this->signedLotIds[] = $lot;
        $row = $batches->stage($actor, $lot, (string) Str::uuid(), $pdf);
        $batches->assign($actor, $lot, $row, $contract->id);
        $preview = $batches->preview($actor, $lot);
        $this->assertTrue($preview['archivos'][0]['listo']);

        return ['action' => 'signed', 'actor' => $actor, 'lot' => $lot, 'row' => $row, 'revision' => $preview['revision']];
    }

    private function confirm(array $batch): array
    {
        $service = $batch['action'] === 'signed' ? PersonalContratoSignedBatchService::class : PersonalIngresoBatchReviewService::class;

        return app($service)->confirm($batch['actor'], $batch['lot'], $batch['revision'], [$batch['row']])[0];
    }

    private function cleanupFixtures(): void
    {
        if (! $this->diskRoot) {
            return;
        }
        $createdPeople = PersonalIngreso::query()->whereKey($this->ingresoIds)->pluck('personal_creado_id')->filter()->all();
        $peopleByReservedDocument = Personal::query()->whereIn('dni', $this->documents)->pluck('id')->all();
        $people = array_unique([...$this->personIds, ...$createdPeople, ...$peopleByReservedDocument]);
        $fichas = DB::table('personal_fichas')->whereIn('personal_id', $people)->pluck('id');
        DB::table('personal_contrato_firmado_lote_archivos')->whereIn('lote_id', $this->signedLotIds)->delete();
        DB::table('personal_contrato_firmado_lotes')->whereIn('id', $this->signedLotIds)->delete();
        DB::table('personal_ingreso_lote_filas')->whereIn('lote_id', $this->ingresoLotIds)->delete();
        DB::table('personal_ingreso_lotes')->whereIn('id', $this->ingresoLotIds)->delete();
        DB::table('personal_ingreso_archivos')->whereIn('personal_ingreso_id', $this->ingresoIds)->delete();
        DB::table('personal_ingresos')->whereIn('id', $this->ingresoIds)->delete();
        DB::table('personal_contratos')->whereIn('personal_id', $people)->delete();
        DB::table('personal_contrato_datos')->whereIn('personal_id', $people)->delete();
        DB::table('personal_ficha_archivos')->whereIn('personal_ficha_id', $fichas)->delete();
        DB::table('personal_documento_estados')->whereIn('personal_ficha_id', $fichas)->delete();
        DB::table('personal_ficha_familiares')->whereIn('personal_ficha_id', $fichas)->delete();
        DB::table('personal_fichas')->whereIn('id', $fichas)->delete();
        DB::table('personal')->whereIn('id', $people)->delete();
        DB::table('usuarios')->whereIn('id', $this->actorIds)->delete();
        DB::table('roles')->whereIn('id', $this->roleIds)->delete();
        DB::table('personal_puestos')->where('id', $this->puestoId)->delete();
    }
}
