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
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppAcceptanceService;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppOperationConfirmationService;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Epps\Services\EppService;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Modules\Epps\Support\EppSignaturePayload;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EppAcceptanceServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private Personal $worker;

    private EppAlmacen $warehouse;

    private EppRegistro $epp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = $this->user($role);
        $this->worker = $this->worker('TRABAJADOR FIRMA EPP');
        $this->warehouse = EppAlmacen::query()->create(['nombre' => 'FIRMA '.Str::uuid()]);
        $this->epp = app(EppService::class)->storeCatalog(['nombre' => 'GUANTE FIRMADO', 'requiere_talla' => true, 'tallas' => 'M,L', 'vida_util_dias' => 30]);
    }

    public function test_signature_covers_only_processed_quantity(): void
    {
        $operation = $this->prepare(10, 5, true);
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $this->assertSame('FIRMADO_PARCIAL', $confirmed->estado);
        $this->assertSame(5, data_get($confirmed->aceptacion->contenido_json, 'details.0.processed_quantity'));
        $this->assertSame(10, data_get($confirmed->aceptacion->contenido_json, 'details.0.requested_quantity'));
        $this->assertSame(5, data_get($confirmed->aceptacion->contenido_json, 'pending.0.pending_quantity'));
        $this->assertSame(5, $confirmed->entregas()->first()->cantidad);
        $this->assertSame(5, $confirmed->entregas()->first()->cantidad_pendiente_devolucion);
        $this->assertSame('ENTREGADO', $confirmed->detalles->first()->estado);
        $this->assertSame(0, $this->existence()->cantidad_disponible);
        $this->assertSame(0, $this->existence()->cantidad_reservada);
        $this->assertSame('CONSUMIDA', $confirmed->reservas->first()->estado);
        $outbound = $this->moves()->where('tipo', 'SALIDA')->firstOrFail();
        $this->assertSame(5, $outbound->cantidad);
        $this->assertSame(0, $outbound->saldo_anterior);
        $this->assertSame(0, $outbound->saldo_posterior);
        $this->assertSame(5, $outbound->snapshot_json['reservada_anterior']);
        $this->assertSame(0, $outbound->snapshot_json['reservada_posterior']);
        $this->assertSame(3, $this->moves()->count());
        $acceptance = $confirmed->aceptacion;
        $this->assertSame($operation->resumen_sha256, $acceptance->contenido_sha256);
        $this->assertSame(EppCanonicalPayload::json($operation->resumen_json), $acceptance->getRawOriginal('contenido_json'));
        $this->assertStringStartsWith('epp/firmas/'.$operation->id.'/', $acceptance->firma_path);
        Storage::disk('local')->assertExists($acceptance->firma_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($acceptance->firma_path)), $acceptance->firma_sha256);
        $this->assertSame(['valid' => true, 'reasons' => []], app(EppAcceptanceService::class)->verify($acceptance));
    }

    public function test_retry_does_not_duplicate_effects(): void
    {
        $operation = $this->prepare();
        $signature = $this->signature($operation);
        $first = $this->confirm($operation, $signature);
        $file = Storage::disk('local')->get($first->aceptacion->firma_path);
        $this->travel(20)->minutes();
        try {
            $retry = $this->confirm($operation->fresh(), $signature);
        } finally {
            $this->travelBack();
        }
        $this->assertSame($first->aceptacion->id, $retry->aceptacion->id);
        $this->assertSame(1, $retry->entregas()->count());
        $this->assertSame(1, $retry->aceptacion()->count());
        $this->assertSame(1, $retry->eventos()->where('accion', 'OPERACION_FIRMADA')->count());
        $this->assertSame(3, $this->moves()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas'));
        $this->assertSame($file, Storage::disk('local')->get($first->aceptacion->firma_path));
    }

    public function test_stale_review_hash_is_rejected(): void
    {
        $operation = $this->prepare();
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['review_hash' => str_repeat('0', 64)])));
    }

    public function test_stale_review_revision_is_rejected(): void
    {
        $operation = $this->prepare();
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['review_version' => $operation->version - 1])));
    }

    public function test_submission_from_another_operation_is_rejected(): void
    {
        $operation = $this->prepare();
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['submission_uuid' => (string) Str::uuid()])));
    }

    public function test_invalid_or_oversized_png_is_rejected(): void
    {
        $operation = $this->prepare();
        foreach (['data:image/jpeg;base64,'.base64_encode('invalid'), 'data:image/png;base64,%%%', 'data:image/png;base64,'.base64_encode(str_repeat('x', 1048577)), $this->png(299, 300), $this->png(600, 2401), $this->png(600, 300, false)] as $image) {
            $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['firma_data_url' => $image])));
        }
    }

    public function test_header_only_png_is_rejected(): void
    {
        $operation = $this->prepare();
        $png = base64_decode(substr($this->png(), strlen('data:image/png;base64,')));
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['firma_data_url' => 'data:image/png;base64,'.base64_encode(substr($png, 0, 33))])));
    }

    public function test_empty_or_out_of_bounds_ink_metadata_is_rejected(): void
    {
        $operation = $this->prepare();
        foreach ([['point_count' => 0, 'bounds' => ['min_x' => 0, 'min_y' => 0, 'max_x' => 0, 'max_y' => 0]], ['point_count' => 20, 'bounds' => ['min_x' => 20, 'min_y' => 20, 'max_x' => 700, 'max_y' => 100]]] as $ink) {
            $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['ink' => $ink])));
        }
    }

    public function test_worker_mismatch_is_rejected(): void
    {
        $operation = $this->prepare();
        $other = $this->worker('OTRO TRABAJADOR');
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation, ['personal_id' => $other->id])));
    }

    public function test_expired_reservation_cannot_confirm(): void
    {
        $operation = $this->prepare();
        $this->travelTo($operation->reserva_expires_at);
        try {
            $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
        } finally {
            $this->travelBack();
        }
    }

    public function test_expired_individual_reservation_cannot_confirm(): void
    {
        $operation = $this->prepare();
        $operation->reservas()->first()->update(['expires_at' => now()->subMinute()]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_changed_detail_requires_a_new_review(): void
    {
        $operation = $this->prepare();
        $operation->detalles()->first()->update(['cantidad_procesada' => 1, 'cantidad_pendiente' => 1]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_changed_return_policy_snapshot_requires_a_new_review(): void
    {
        $operation = $this->prepare();
        $operation->detalles()->first()->update(['politica_devolucion_snapshot' => 'CONSUMIBLE']);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_reservation_quantity_mismatch_is_rejected(): void
    {
        $operation = $this->prepare();
        $operation->reservas()->first()->update(['cantidad' => 1]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_insufficient_reserved_balance_is_rejected(): void
    {
        $operation = $this->prepare();
        $this->existence()->update(['cantidad_reservada' => 1]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_failed_transaction_removes_signature_file(): void
    {
        $operation = $this->prepare();
        $dispatcher = EppEntrega::getEventDispatcher();
        EppEntrega::setEventDispatcher(clone $dispatcher);
        EppEntrega::creating(function (): void {
            $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas'));
            throw new RuntimeException('Fallo simulado al crear entrega');
        });
        try {
            $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)), RuntimeException::class);
        } finally {
            EppEntrega::setEventDispatcher($dispatcher);
        }
    }

    public function test_private_visibility_failure_removes_only_the_new_signature(): void
    {
        $operation = $this->prepare();
        $disk = Storage::disk('local');
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('setVisibility')->with(\Mockery::type('string'), 'private')->andReturn(false);
        Storage::set('local', $failingDisk);
        try {
            $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)), RuntimeException::class);
        } finally {
            Storage::set('local', $disk);
        }
    }

    public function test_file_is_saved_before_confirmation_transaction_starts(): void
    {
        $operation = $this->prepare();
        $observed = false;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$observed): void {
            $observed = true;
            $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas'));
        });
        $this->confirm($operation, $this->signature($operation));
        $this->assertTrue($observed);
    }

    public function test_altered_file_hash_is_invalid(): void
    {
        $operation = $this->prepare();
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $acceptance = $confirmed->aceptacion;
        Storage::disk('local')->put($acceptance->firma_path, 'evidencia alterada');
        $result = app(EppAcceptanceService::class)->verify($acceptance->fresh());
        $this->assertFalse($result['valid']);
        $this->assertContains('EVIDENCIA_INVALIDA', $result['reasons']);
        $this->assertContains('FIRMA_HASH_NO_COINCIDE', $result['reasons']);
        $this->assertSame('FIRMA_INVALIDA', $acceptance->fresh()->estado_integridad);
        $this->assertSame(1, $operation->entregas()->count());
        $this->assertSame(3, $this->moves()->count());
    }

    public function test_missing_file_is_reported_without_repeating_delivery(): void
    {
        $operation = $this->prepare();
        $signature = $this->signature($operation);
        $confirmed = $this->confirm($operation, $signature);
        Storage::disk('local')->delete($confirmed->aceptacion->firma_path);
        $retry = $this->confirm($operation->fresh(), $signature);
        $result = app(EppAcceptanceService::class)->verify($retry->aceptacion);
        $this->assertFalse($result['valid']);
        $this->assertContains('ARCHIVO_FALTANTE', $result['reasons']);
        $this->assertSame('ARCHIVO_FALTANTE', $retry->aceptacion->fresh()->estado_integridad);
        $this->assertCount(0, Storage::disk('local')->allFiles('epp/firmas'));
        $this->assertSame(1, $retry->entregas()->count());
        $this->assertSame(3, $this->moves()->count());
    }

    public function test_altered_canonical_bytes_are_invalid(): void
    {
        $operation = $this->prepare();
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $acceptance = $confirmed->aceptacion;
        $acceptance->update(['contenido_json' => str_replace('"processed_quantity":2', '"processed_quantity":1', $acceptance->getRawOriginal('contenido_json'))]);
        $result = app(EppAcceptanceService::class)->verify($acceptance->fresh());
        $this->assertFalse($result['valid']);
        $this->assertContains('CONTENIDO_HASH_NO_COINCIDE', $result['reasons']);
        $this->assertSame(3, $this->moves()->count());
        $this->assertSame(2, $operation->entregas()->first()->cantidad);
    }

    public function test_acceptance_stores_server_and_utc_times(): void
    {
        config(['app.timezone' => 'America/Lima']);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 15:20:30', 'America/Lima'));
        try {
            $operation = $this->prepare();
            $this->app->instance('request', Request::create('/epps/operations/sign', 'POST', server: ['REMOTE_ADDR' => '192.0.2.55', 'HTTP_USER_AGENT' => 'EPP Test Browser']));
            $acceptance = $this->confirm($operation, $this->signature($operation))->aceptacion;
            $this->assertSame('2026-10-07 15:20:30', $acceptance->firmado_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-07 20:20:30', $acceptance->firmado_at_utc->format('Y-m-d H:i:s'));
            $this->assertSame('UTC', $acceptance->firmado_at_utc->getTimezone()->getName());
            $this->assertSame('America/Lima', $acceptance->zona_horaria);
            $this->assertSame('EPP Test Browser', $acceptance->user_agent);
            $this->assertSame(hash_hmac('sha256', '192.0.2.55', config('app.key')), $acceptance->ip_sha256);
            $this->assertSame('DELIVERY_V1', $acceptance->declaracion_version);
            $this->assertStringNotContainsString('base64', json_encode($acceptance->getAttributes()));
            $this->assertArrayNotHasKey('firma_path', $acceptance->toArray());
        } finally {
            $this->travelBack();
        }
    }

    public function test_signer_snapshot_is_immutable(): void
    {
        $operation = $this->prepare();
        $originalDocument = $this->worker->dni;
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $this->worker->update(['nombre_completo' => 'NOMBRE NUEVO', 'dni' => '99999999']);
        $this->epp->update(['nombre' => 'CATALOGO NUEVO', 'politica_devolucion' => 'CONSUMIBLE']);
        $acceptance = $confirmed->aceptacion->fresh();
        $this->assertSame('TRABAJADOR FIRMA EPP', $acceptance->firmante_nombre);
        $this->assertSame($originalDocument, $acceptance->documento_snapshot);
        $this->assertSame('TRABAJADOR FIRMA EPP', $acceptance->contenido_json['worker']['name']);
        $this->assertSame('GUANTE FIRMADO', $acceptance->contenido_json['details'][0]['snapshot']['real']['nombre']);
        $this->assertSame(['valid' => true, 'reasons' => []], app(EppAcceptanceService::class)->verify($acceptance));
    }

    public function test_consumable_has_history_and_zero_return_balance(): void
    {
        $this->epp->update(['politica_devolucion' => 'CONSUMIBLE', 'permite_entrega_repetida' => true]);
        $operation = $this->prepare();
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $delivery = $confirmed->entregas()->firstOrFail();
        $this->assertSame(2, $delivery->cantidad);
        $this->assertSame(2, $delivery->cantidad_original);
        $this->assertSame(0, $delivery->cantidad_pendiente_devolucion);
        $this->assertSame('CONSUMIDO', $delivery->estado);
        $this->assertSame('CONSUMIDO', $confirmed->detalles->first()->estado);
        $this->assertFalse($delivery->es_legacy);
        $this->assertSame('CONSUMIBLE', $delivery->politica_devolucion_snapshot);
    }

    public function test_uncontrolled_delivery_does_not_write_inventory(): void
    {
        $this->epp->update(['control_inventario' => false]);
        $service = app(EppOperationService::class);
        $draft = $service->createDraft($this->actor, $this->worker, $this->warehouse, 'ENTREGA', (string) Str::uuid());
        $operation = $service->prepareForSignature($service->syncDetails($draft, [['epp_id' => $this->epp->id, 'cantidad_solicitada' => 2, 'talla' => 'M']], $this->actor), $this->actor);
        $confirmed = $this->confirm($operation, $this->signature($operation));
        $this->assertSame(2, $confirmed->entregas()->firstOrFail()->cantidad);
        $this->assertSame(0, $this->moves()->count());
        $this->assertSame(0, $operation->reservas()->count());
    }

    public function test_confirmation_rechecks_current_permissions(): void
    {
        $operation = $this->prepare();
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SIN CONFIRMAR '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['logistica' => ['administrar']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)), AuthorizationException::class);
    }

    public function test_deactivated_catalog_cannot_confirm(): void
    {
        $operation = $this->prepare();
        $this->epp->update(['estado' => 'INACTIVO']);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_changed_warehouse_mine_requires_a_new_review(): void
    {
        $operation = $this->prepare();
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'CONTEXTO FIRMA '.Str::uuid(), 'unidad_minera' => 'PRUEBA', 'estado' => 'ACTIVO']);
        $this->warehouse->update(['mina_id' => $mine->id]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
    }

    public function test_witness_requires_exception_permission(): void
    {
        $operation = $this->prepare();
        [$witness, $approver] = $this->witnessUsers(false);
        $signature = $this->signature($operation, ['tipo_firmante' => 'TESTIGO_AUTORIZADO', 'testigo_usuario_id' => $witness->id, 'motivo_excepcion' => 'Imposibilidad fisica de firmar']);
        $this->assertRejected($operation, fn () => app(EppOperationConfirmationService::class)->confirmWithWitness($operation, $signature, $approver), AuthorizationException::class);
    }

    public function test_authorized_witness_is_identified_and_not_attributed_to_worker(): void
    {
        $operation = $this->prepare();
        [$witness, $approver] = $this->witnessUsers();
        $signature = $this->signature($operation, ['tipo_firmante' => 'TESTIGO_AUTORIZADO', 'testigo_usuario_id' => $witness->id, 'motivo_excepcion' => 'Imposibilidad fisica de firmar']);
        $confirmed = app(EppOperationConfirmationService::class)->confirmWithWitness($operation, $signature, $approver);
        $acceptance = $confirmed->aceptacion;
        $this->assertSame('TESTIGO AUTORIZADO', $acceptance->firmante_nombre);
        $this->assertSame($witness->personal->dni, $acceptance->documento_snapshot);
        $this->assertSame($this->worker->id, $acceptance->personal_id);
        $this->assertSame($witness->id, $acceptance->testigo_usuario_id);
        $this->assertSame($approver->id, $acceptance->aprobador_usuario_id);
        $this->assertSame($this->actor->id, $acceptance->operador_usuario_id);
        $this->assertSame('Imposibilidad fisica de firmar', $acceptance->motivo_excepcion);
        $this->assertSame('TESTIGO_AUTORIZADO', $acceptance->tipo_firmante);
        $this->assertSame($acceptance->id, app(EppOperationConfirmationService::class)->confirmWithWitness($operation->fresh(), $signature, $approver)->aceptacion->id);
        $this->assertSame(3, $this->moves()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas'));
    }

    public function test_witness_needs_identity_reason_real_signature_and_separate_approver(): void
    {
        $operation = $this->prepare();
        [$witness, $approver] = $this->witnessUsers();
        $base = ['tipo_firmante' => 'TESTIGO_AUTORIZADO', 'testigo_usuario_id' => $witness->id, 'motivo_excepcion' => 'Imposibilidad fisica de firmar'];
        foreach ([['testigo_usuario_id' => null], ['motivo_excepcion' => ''], ['firma_data_url' => $this->png(600, 300, false)]] as $override) {
            $this->assertRejected($operation, fn () => app(EppOperationConfirmationService::class)->confirmWithWitness($operation, $this->signature($operation, array_replace($base, $override)), $approver));
        }
        $this->assertRejected($operation, fn () => app(EppOperationConfirmationService::class)->confirmWithWitness($operation, $this->signature($operation, array_replace($base, ['testigo_usuario_id' => $approver->id])), $approver));
    }

    public function test_refusal_never_becomes_a_witness_exception(): void
    {
        $operation = $this->prepare();
        $signature = $this->signature($operation);
        app(EppOperationService::class)->reject($operation, $this->actor, 'El trabajador no desea firmar');
        [$witness, $approver] = $this->witnessUsers();
        $this->assertRejected($operation->fresh(), fn () => app(EppOperationConfirmationService::class)->confirmWithWitness($operation, $this->signature($operation, ['tipo_firmante' => 'TESTIGO_AUTORIZADO', 'testigo_usuario_id' => $witness->id, 'motivo_excepcion' => 'Negativa del trabajador']), $approver));
        $this->assertSame('RECHAZADO', $operation->fresh()->estado);
        $this->assertSame(5, $this->existence()->cantidad_disponible);
    }

    public function test_bad_retry_never_deletes_committed_evidence(): void
    {
        $operation = $this->prepare();
        $signature = $this->signature($operation);
        $confirmed = $this->confirm($operation, $signature);
        $path = $confirmed->aceptacion->firma_path;
        $bytes = Storage::disk('local')->get($path);
        $this->assertRejected($operation->fresh(), fn () => $this->confirm($operation->fresh(), $this->signature($operation, ['review_hash' => str_repeat('0', 64)])));
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->assertSame($confirmed->aceptacion->id, $this->confirm($operation->fresh(), $signature)->aceptacion->id);
    }

    public function test_spanish_review_must_be_prepared_again_without_duplicate_reservations(): void
    {
        $operation = $this->prepare();
        $legacy = ['operacion_id' => $operation->id, 'version' => $operation->version, 'personal' => ['id' => $this->worker->id], 'detalles' => [['cantidad_procesada' => 2]]];
        $operation->update(['resumen_json' => $legacy, 'resumen_sha256' => hash('sha256', EppCanonicalPayload::json($legacy))]);
        $this->assertRejected($operation, fn () => $this->confirm($operation, $this->signature($operation)));
        $prepared = app(EppOperationService::class)->prepareForSignature($operation, $this->actor);
        $this->assertSame(2, $prepared->resumen_json['details'][0]['processed_quantity']);
        $this->assertSame($operation->version + 1, $prepared->version);
        $this->assertSame(1, $prepared->reservas()->count());
        $this->assertSame(2, $this->moves()->count());
        $this->assertSame('FIRMADO', $this->confirm($prepared, $this->signature($prepared))->estado);
    }

    public function test_two_mysql_confirmations_keep_one_delivery_and_the_winning_evidence(): void
    {
        $operation = $this->prepare();
        $signature = $this->signature($operation);
        $root = storage_path('app/testing/epp-signatures-'.$operation->id);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        DB::commit();
        $processes = $readyPaths = [];
        try {
            DB::beginTransaction();
            EppOperacion::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $readyPaths[$i] = storage_path('app/testing/epp-sign-'.$operation->id.'-'.$i.'.ready');
                $process = new Process([PHP_BINARY, base_path('tests/Support/confirm-epp-operation.php'), $operation->id, $this->actor->id, $readyPaths[$i], $root], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'proserge_app_test', 'DB_USERNAME' => config('database.connections.mysql.username'),
                    'DB_PASSWORD' => config('database.connections.mysql.password'),
                ]);
                $process->setInput(json_encode($signature->data, JSON_THROW_ON_ERROR))->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1])) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    $process->isRunning(); // Pump stdin on Windows until the child reaches its transaction.
                }
                usleep(10000);
            }
            foreach ($readyPaths as $index => $path) {
                $this->assertFileExists($path, $processes[$index]->getErrorOutput().$processes[$index]->getOutput());
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Ambas confirmaciones deben esperar el bloqueo de operacion.');
            }
            $this->assertCount(2, Storage::disk('local')->allFiles('epp/firmas'));
            DB::commit();
            $acceptanceIds = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('FIRMADO', $result['estado']);
                $acceptanceIds[] = $result['aceptacion_id'];
            }
            $this->assertSame($acceptanceIds[0], $acceptanceIds[1]);
            $this->assertSame(1, $operation->aceptacion()->count());
            $this->assertSame(1, $operation->entregas()->count());
            $this->assertSame(3, $this->moves()->count());
            $this->assertSame(0, $this->existence()->cantidad_reservada);
            $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas'));
            $this->assertSame(['valid' => true, 'reasons' => []], app(EppAcceptanceService::class)->verify($operation->aceptacion()->firstOrFail()));
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
            if ($operation->fresh()->estado === 'LISTO_PARA_FIRMA') {
                app(EppOperationService::class)->reject($operation, $this->actor, 'Limpieza de reserva de prueba concurrente');
            }
            // Keep committed signed fixtures, the append-only ledger and synthetic evidence.
            $this->worker->update(['estado' => 'CESADO']);
            $this->warehouse->update(['estado' => 'INACTIVO']);
            $this->epp->update(['estado' => 'INACTIVO']);
            $this->actor->update(['estado' => 'INACTIVO']);
            DB::beginTransaction();
        }
    }

    private function confirm(EppOperacion $operation, EppSignaturePayload $signature): EppOperacion
    {
        return app(EppOperationConfirmationService::class)->confirm($operation, $signature, $this->actor);
    }

    private function signature(EppOperacion $operation, array $overrides = []): EppSignaturePayload
    {
        return EppSignaturePayload::fromArray(array_replace([
            'submission_uuid' => $operation->submission_uuid,
            'personal_id' => $operation->personal_id,
            'review_hash' => $operation->resumen_sha256,
            'review_version' => $operation->version,
            'firma_data_url' => $this->png(),
            'ink' => ['point_count' => 40, 'bounds' => ['min_x' => 40, 'min_y' => 50, 'max_x' => 400, 'max_y' => 210]],
            'tipo_firmante' => 'TRABAJADOR',
        ], $overrides));
    }

    private function png(int $width = 600, int $height = 300, bool $withInk = true): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if ($withInk) {
            $pen = imagecolorallocate($image, 10, 20, 30);
            imagesetthickness($image, 3);
            imageline($image, 40, 160, 170, 50, $pen);
            imageline($image, 170, 50, 110, 210, $pen);
            imageline($image, 110, 210, 400, 100, $pen);
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function prepare(int $requested = 2, int $stock = 5, bool $partial = false): EppOperacion
    {
        app(EppInventoryService::class)->openStock($this->warehouse, [['epp_id' => $this->epp->id, 'cantidad' => $stock, 'talla' => 'M']], $this->actor, 'Conteo de prueba');
        $service = app(EppOperationService::class);
        $draft = $service->createDraft($this->actor, $this->worker, $this->warehouse, 'ENTREGA', (string) Str::uuid());

        return $service->prepareForSignature($service->syncDetails($draft, [['epp_id' => $this->epp->id, 'cantidad_solicitada' => $requested, 'talla' => 'M', 'aceptar_parcial' => $partial]], $this->actor), $this->actor);
    }

    private function assertRejected(EppOperacion $operation, callable $attempt, string $exceptionClass = InvalidArgumentException::class): void
    {
        $before = [$operation->fresh()->getAttributes(), $operation->detalles()->orderBy('id')->get()->toArray(), $operation->reservas()->orderBy('id')->get()->toArray(), EppExistencia::query()->where('almacen_id', $this->warehouse->id)->get()->toArray(), $operation->entregas()->count(), $operation->aceptacion()->count(), $this->moves()->count(), Storage::disk('local')->allFiles('epp/firmas')];
        $caught = null;
        try {
            $attempt();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught, 'La confirmacion invalida debe rechazarse.');
        $this->assertInstanceOf($exceptionClass, $caught, $caught->getMessage());
        $after = [$operation->fresh()->getAttributes(), $operation->detalles()->orderBy('id')->get()->toArray(), $operation->reservas()->orderBy('id')->get()->toArray(), EppExistencia::query()->where('almacen_id', $this->warehouse->id)->get()->toArray(), $operation->entregas()->count(), $operation->aceptacion()->count(), $this->moves()->count(), Storage::disk('local')->allFiles('epp/firmas')];
        $this->assertSame($before, $after, 'Rechazar debe conservar inventario, reservas, entrega, operacion y evidencia previa.');
    }

    private function existence(): EppExistencia
    {
        return EppExistencia::query()->where('almacen_id', $this->warehouse->id)->firstOrFail();
    }

    private function moves(): Builder
    {
        return EppInventarioMovimiento::query()->where('almacen_id', $this->warehouse->id);
    }

    private function worker(string $name): Personal
    {
        return Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => $name, 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
    }

    private function user(Rol $role, ?Personal $worker = null): Usuario
    {
        return Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'test-secret', 'estado' => 'ACTIVO', 'rol_id' => $role->id, 'personal_id' => $worker?->id])->load('rol', 'personal');
    }

    private function witnessUsers(bool $permission = true): array
    {
        $role = Rol::query()->firstOrCreate(['nombre' => $permission ? 'SUPERADMIN' : 'SIN EXCEPCION '.Str::uuid()], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['epps' => $permission ? ['confirmar_entrega', 'aprobar_excepcion_firma'] : ['confirmar_entrega']])]);

        return [$this->user($this->actor->rol, $this->worker('TESTIGO AUTORIZADO')), $this->user($role, $this->worker('APROBADOR EPP'))];
    }
}
