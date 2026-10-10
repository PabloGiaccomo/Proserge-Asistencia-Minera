<?php

namespace Tests\Feature;

use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Modules\Epps\Services\EppAcceptanceService;
use App\Modules\Epps\Services\EppKardexExcelService;
use App\Modules\Epps\Services\EppKardexService;
use App\Modules\Epps\Services\EppService;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class EppExistingWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $actor;

    private Personal $worker;

    private array $session;

    private array $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $this->actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => bcrypt('secret'), 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
        $this->worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'PRUEBA FIRMA FLUJO EXISTENTE', 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
        $product = app(EppService::class)->storeCatalog(['nombre' => 'CASCO FLUJO EXISTENTE', 'vida_util_dias' => 365, 'requiere_color' => true, 'colores' => 'BLANCO,ROJO']);
        $this->delivery = ['personal_id' => $this->worker->id, 'epp_id' => $product->id, 'cantidad' => 2, 'color' => 'BLANCO', 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid()];
        $this->session = ['auth_token' => 'test-token', 'user_id' => $this->actor->id, 'user' => ['id' => $this->actor->id, 'email' => $this->actor->email, 'permissions' => PermissionMatrix::effectivePermissions($this->actor)]];
    }

    public function test_kardex_pages_grouped_rows_on_server_without_technical_history_or_truncating_excel(): void
    {
        $vest = app(EppService::class)->storeCatalog(['nombre' => 'CHALECO PAGINADO', 'vida_util_dias' => 180]);
        $input = array_replace($this->delivery, ['items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 25, 'color' => 'BLANCO'],
            ['epp_id' => $vest->id, 'cantidad' => 20],
        ]]);
        $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $url = route('epps.kardex.show', ['personal_id' => $this->worker->id]);
        $first = $this->get($url)->assertOk();
        $first->assertJsonCount(20, 'data.kardex.rows')->assertJsonPath('data.kardex.pagination', [
            'current_page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2, 'from' => 1, 'to' => 20,
        ]);
        $this->assertArrayNotHasKey('operations', $first->json('data'));
        $this->assertArrayNotHasKey('items', $first->json('data'));
        $this->assertSame(0, $operation->eventos()->where('accion', 'EVIDENCIA_CONSULTADA')->count());
        $second = $this->get($url.'&page=2')->assertOk()->assertJsonCount(5, 'data.kardex.rows');
        $second->assertJsonPath('data.kardex.rows.0.number', 21)->assertJsonPath('data.kardex.pagination.from', 21)->assertJsonPath('data.kardex.pagination.to', 25);
        foreach ($second->json('data.kardex.rows') as $row) {
            $this->assertSame(['N'], array_values($row['codes']));
        }
        foreach ([50, 100] as $limit) {
            $this->get($url.'&per_page='.$limit)->assertOk()->assertJsonCount(25, 'data.kardex.rows')->assertJsonPath('data.kardex.pagination.per_page', $limit);
        }
        $this->get($url.'&page=999')->assertOk()->assertJsonPath('data.kardex.pagination.current_page', 2);
        foreach (['per_page=101', 'per_page=0', 'per_page=100000', 'page=0', 'page=-1', 'page=oops', 'per_page[]=20'] as $invalid) {
            $this->getJson($url.'&'.$invalid)->assertUnprocessable();
        }
        $book = app(EppKardexExcelService::class)->workbook($this->worker, $this->actor, '2026-10-09', '2026-10-09');
        $sheet = $book->getSheet(0);
        $this->assertSame('N', $sheet->getCell('C38')->getValue());
        $this->assertNull($sheet->getCell('D38')->getValue());
        $this->assertNull($sheet->getCell('B39')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_kardex_page_limit_empty_history_and_worker_scope_are_enforced(): void
    {
        $url = route('epps.kardex.show', ['personal_id' => $this->worker->id]);
        $this->withSession($this->session)->get($url.'&page=99')->assertOk()
            ->assertJsonCount(0, 'data.kardex.rows')->assertJsonPath('data.kardex.pagination', [
                'current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0,
            ]);
        app(EppService::class)->deliver(array_replace($this->delivery, ['cantidad' => 125]), $this->actor);
        $this->get($url.'&per_page=100')->assertOk()->assertJsonCount(100, 'data.kardex.rows')->assertJsonPath('data.kardex.pagination.total', 125);
        $this->get($url.'&per_page=100&page=2')->assertOk()->assertJsonCount(25, 'data.kardex.rows')->assertJsonPath('data.kardex.rows.0.number', 101);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'KARDEX SOLO LECTURA '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['ver']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->get($url.'&page=2')->assertForbidden();
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA KARDEX '.Str::uuid(), 'unidad_minera' => 'UM TEST', 'estado' => 'ACTIVO']);
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $this->actor->id, 'mina_id' => $mine->id]);
        PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'activo' => true]);
        $this->get($url.'&page=2')->assertOk()->assertJsonCount(20, 'data.kardex.rows');
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections([])]);
        $this->get($url)->assertForbidden();
    }

    public function test_catalog_return_selects_worker_balances_and_signs_multiple_partial_returns(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $deliveryOperation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $deliveryOperation), $this->signature($deliveryOperation))->assertSessionHasNoErrors();
        $first = $deliveryOperation->entregas()->firstOrFail();
        $second = app(EppService::class)->deliver(array_replace($this->delivery, ['cantidad' => 3, 'color' => 'ROJO']), $this->actor);
        $second->update(['es_legacy' => true, 'cantidad_original' => 3, 'cantidad_pendiente_devolucion' => 3]);
        $originalHash = $deliveryOperation->fresh()->aceptacion->contenido_sha256;
        $this->get('/logistica?tab=entregas')->assertOk()->assertSee('Registrar devolucion');
        $this->get(route('epps.catalog-returns.create', ['personal_id' => $this->worker->id]))->assertOk()->assertSee('BLANCO')->assertSee('ROJO')->assertDontSee('name="almacen_id"', false);
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['entrega_id' => $first->id, 'cantidad' => 1], ['entrega_id' => $second->id, 'cantidad' => 3],
        ]];
        $this->post(route('epps.catalog-returns.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $return = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $this->assertSame(2, $first->fresh()->cantidad_pendiente_devolucion);
        $this->get(route('epps.delivery-signature.review', $return))->assertOk()->assertSee('Cantidad: 1')->assertSee('Cantidad: 3')->assertSee('Saldo restante: 1')->assertSee('Confirmo haber devuelto');
        $signature = $this->signature($return);
        $this->post(route('epps.delivery-signature.confirm', $return), $signature)->assertSessionHasNoErrors();
        $this->assertSame(1, $first->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('ENTREGADO', $first->fresh()->estado);
        $this->assertSame(0, $second->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('DEVUELTO', $second->fresh()->estado);
        $this->assertSame($deliveryOperation->id, $first->fresh()->operacion_id);
        $this->assertSame($originalHash, $deliveryOperation->fresh()->aceptacion->contenido_sha256);
        $return->refresh()->load('aceptacion');
        $this->assertTrue(app(EppAcceptanceService::class)->verify($return->aceptacion)['valid']);
        $this->assertSame(2, count($return->aceptacion->contenido_json['details']));
        $this->post(route('epps.delivery-signature.confirm', $return), $signature)->assertSessionHasNoErrors();
        $this->assertSame(1, $first->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('FIRMADO_VALIDO', collect(app(EppKardexService::class)->forWorker($this->worker, $this->actor)['operations'])->firstWhere('id', $return->id)['evidence_state']);
        $this->get(route('epps.evidence.receipt', $return))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_catalog_return_rejects_excess_duplicates_other_workers_and_consumables(): void
    {
        $first = app(EppService::class)->deliver($this->delivery, $this->actor);
        $first->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'items' => [['entrega_id' => $first->id, 'cantidad' => 3]]];
        foreach (['excess', 'duplicate', 'other_worker', 'consumable'] as $case) {
            $input = $data + ['submission_uuid' => (string) Str::uuid()];
            if ($case === 'duplicate') {
                $input['items'] = [['entrega_id' => $first->id, 'cantidad' => 1], ['entrega_id' => $first->id, 'cantidad' => 1]];
            } elseif ($case === 'other_worker') {
                $other = $this->worker->replicate();
                $other->id = (string) Str::uuid();
                $other->dni = (string) random_int(10000000, 99999999);
                $other->qr_code = (string) Str::uuid();
                $other->save();
                $input['personal_id'] = $other->id;
                $input['items'][0]['cantidad'] = 1;
            } elseif ($case === 'consumable') {
                $first->update(['politica_devolucion_snapshot' => 'CONSUMIBLE']);
                $input['items'][0]['cantidad'] = 1;
            }
            $this->withSession($this->session)->post(route('epps.catalog-returns.store'), $input)->assertSessionHasErrors();
            $this->assertFalse(EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->exists());
        }
    }

    public function test_stale_catalog_return_rejects_every_line_without_signature_or_deduction(): void
    {
        $first = app(EppService::class)->deliver($this->delivery, $this->actor);
        $second = app(EppService::class)->deliver($this->delivery, $this->actor);
        foreach ([$first, $second] as $source) {
            $source->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        }
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['entrega_id' => $first->id, 'cantidad' => 1], ['entrega_id' => $second->id, 'cantidad' => 1],
        ]];
        $this->withSession($this->session)->post(route('epps.catalog-returns.store'), $data)->assertSessionHasNoErrors();
        $return = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $second->update(['cantidad_pendiente_devolucion' => 1]);
        $this->post(route('epps.delivery-signature.confirm', $return), $this->signature($return))->assertSessionHasErrors();
        $this->assertSame(2, $first->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(1, $second->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $return->aceptacion()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('epp/firmas/'.$return->id));
    }

    public function test_catalog_return_can_be_confirmed_with_scope_and_confirmation_permission_only(): void
    {
        $source = app(EppService::class)->deliver($this->delivery, $this->actor);
        $source->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [['entrega_id' => $source->id, 'cantidad' => 1]]];
        $this->withSession($this->session)->post(route('epps.catalog-returns.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SOLO CONFIRMAR '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['confirmar_devolucion']])]);
        $confirmer = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => bcrypt('secret'), 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA DEVOLUCION '.Str::uuid(), 'unidad_minera' => 'UM TEST', 'estado' => 'ACTIVO']);
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $confirmer->id, 'mina_id' => $mine->id]);
        PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'activo' => true]);
        $session = ['auth_token' => 'test-token', 'user_id' => $confirmer->id, 'user' => ['id' => $confirmer->id, 'email' => $confirmer->email, 'permissions' => PermissionMatrix::effectivePermissions($confirmer)]];
        $this->withSession($session)->get(route('epps.delivery-signature.review', $operation))->assertOk();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $source->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame($confirmer->id, $operation->fresh()->confirmado_por_usuario_id);
        $this->get(route('epps.catalog-returns.create'))->assertForbidden();
    }

    public function test_catalog_return_revalidates_permissions_and_worker_scope(): void
    {
        $source = app(EppService::class)->deliver($this->delivery, $this->actor);
        $source->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [['entrega_id' => $source->id, 'cantidad' => 1]]];
        $this->withSession($this->session)->post(route('epps.catalog-returns.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'DEVOLUCION SIN SCOPE '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['registrar', 'confirmar_devolucion']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->get(route('epps.catalog-returns.create', ['personal_id' => $this->worker->id]))->assertForbidden();
        $this->post(route('epps.catalog-returns.store'), $data)->assertForbidden();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertForbidden();
        $this->assertSame(2, $source->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(0, $operation->aceptacion()->count());
    }

    public function test_catalog_return_preserves_unselected_balances_and_can_finish_with_individual_close(): void
    {
        $sources = collect([1, 2])->map(function () {
            $source = app(EppService::class)->deliver($this->delivery, $this->actor);
            $source->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);

            return $source;
        });
        $data = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [['entrega_id' => $sources[0]->id, 'cantidad' => 1]]];
        $this->withSession($this->session)->post(route('epps.catalog-returns.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $this->post(route('epps.catalog-returns.store'), $data)->assertRedirect(route('epps.delivery-signature.review', $operation));
        EppRegistro::query()->findOrFail($this->delivery['epp_id'])->update(['estado' => 'INACTIVO']);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame(2, $sources[1]->fresh()->cantidad_pendiente_devolucion);
        $this->post(route('epps.entregas.close', $sources[0]->id), ['estado' => 'DEVUELTO', 'devuelto_at' => '2026-10-09'])->assertSessionHasNoErrors();
        $closing = EppOperacion::query()->where('personal_id', $this->worker->id)->where('tipo', 'DEVOLUCION')->where('id', '!=', $operation->id)->firstOrFail();
        $this->assertSame(1, $closing->resumen_json['details'][0]['processed_quantity']);
        $this->post(route('epps.delivery-signature.confirm', $closing), $this->signature($closing))->assertSessionHasNoErrors();
        $this->assertSame(0, $sources[0]->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('DEVUELTO', $sources[0]->fresh()->estado);
    }

    public function test_existing_delivery_form_prepares_signature_without_warehouse_or_stock(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors()->assertRedirect();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->assertNull($operation->almacen_id);
        $this->assertSame(0, $operation->entregas()->count());
        $this->get(route('epps.delivery-signature.review', $operation))->assertOk()->assertSee('CASCO FLUJO EXISTENTE')->assertSee('BLANCO')->assertSee('2026-10-09')->assertSee('Firma del trabajador')->assertDontSee('Seleccionar almacen');
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors()->assertRedirect('/logistica?tab=entregas');
        $operation->refresh()->load('aceptacion');
        $this->assertSame('FIRMADO', $operation->estado);
        $this->assertSame(2, $operation->entregas()->firstOrFail()->cantidad);
        $this->assertSame('2026-10-09', $operation->entregas()->first()->fecha_entrega->toDateString());
        $this->assertTrue(app(EppAcceptanceService::class)->verify($operation->aceptacion)['valid']);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame(1, $operation->entregas()->count());
        $kardex = app(EppKardexService::class)->forWorker($this->worker, $this->actor);
        $this->assertSame('FIRMADO_VALIDO', collect($kardex['operations'])->firstWhere('id', $operation->id)['evidence_state']);
        $this->get(route('epps.evidence.verify', $operation->aceptacion->codigo_verificacion))->assertOk()->assertSee('alt="Firma del trabajador"', false);
        $this->get('/logistica?tab=entregas&q='.$this->worker->dni)->assertOk()->assertSee('Ver firma')->assertSee('Constancia');
    }

    public function test_delivery_tab_keeps_the_existing_modal_and_has_no_duplicate_warehouse_actions(): void
    {
        $this->withSession($this->session)->get('/logistica?tab=entregas')->assertOk()
            ->assertSee('data-epp-open-modal="eppDeliveryModal"', false)
            ->assertDontSee('Entrega multiple con firma')->assertDontSee('Nueva entrega multiple')->assertDontSee('Inventario por almacén');
    }

    public function test_multiple_catalog_items_are_visible_and_registered_with_one_acceptance(): void
    {
        $vest = app(EppService::class)->storeCatalog(['nombre' => 'CHALECO LOTE FIRMA', 'vida_util_dias' => 180, 'requiere_talla' => true, 'tallas' => 'M,L']);
        $data = ['personal_id' => $this->worker->id, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 2, 'color' => 'BLANCO'],
            ['epp_id' => $vest->id, 'cantidad' => 3, 'talla' => 'L', 'observacion' => 'Entrega conjunta'],
        ]];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $this->assertSame(2, $operation->detalles()->count());
        $this->assertSame(0, $operation->entregas()->count());
        $this->get(route('epps.delivery-signature.review', $operation))->assertOk()->assertSeeInOrder(['CASCO FLUJO EXISTENTE', 'Cantidad: 2', 'BLANCO', 'CHALECO LOTE FIRMA', 'Cantidad: 3', 'Talla: L', 'Entrega conjunta', 'Firma del trabajador']);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $operation->refresh()->load('aceptacion');
        $this->assertSame(2, $operation->entregas()->count());
        $this->assertSame(1, $operation->aceptacion()->count());
        $this->assertSame(2, count($operation->aceptacion->contenido_json['details']));
        $this->assertSame(3, $operation->entregas()->where('epp_id', $vest->id)->firstOrFail()->cantidad);
        $this->assertTrue(app(EppAcceptanceService::class)->verify($operation->aceptacion)['valid']);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame(2, $operation->entregas()->count());
        $this->get(route('epps.evidence.verify', $operation->aceptacion->codigo_verificacion))->assertOk()->assertSee('CHALECO LOTE FIRMA')->assertSee('CASCO FLUJO EXISTENTE');
        $this->get(route('epps.evidence.receipt', $operation))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_change_to_second_item_prevents_all_deliveries_and_the_signature(): void
    {
        $vest = app(EppService::class)->storeCatalog(['nombre' => 'CHALECO CAMBIO LOTE', 'vida_util_dias' => 180]);
        $data = ['personal_id' => $this->worker->id, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 2, 'color' => 'BLANCO'], ['epp_id' => $vest->id, 'cantidad' => 1],
        ]];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $vest->update(['requiere_talla' => true, 'tallas' => ['L']]);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasErrors();
        $this->assertSame(0, $operation->entregas()->count());
        $this->assertSame(0, $operation->aceptacion()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('epp/firmas/'.$operation->id));
    }

    public function test_invalid_second_item_does_not_leave_a_partial_review(): void
    {
        $data = ['personal_id' => $this->worker->id, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 2, 'color' => 'BLANCO'], ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 1, 'color' => 'INVALIDO'],
        ]];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $data)->assertSessionHas('error');
        $this->assertSame(0, EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->count());
    }

    public function test_change_leaves_original_open_until_signature_and_does_not_duplicate_on_retry(): void
    {
        $original = app(EppService::class)->deliver($this->delivery, $this->actor);
        $original->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $data = $this->delivery + ['_epp_replaces_entrega_id' => $original->id, '_epp_replacement_fecha' => '2026-10-09', '_epp_replacement_motivo' => 'Desgaste'];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $this->assertSame('ENTREGADO', $original->fresh()->estado);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame('CAMBIADO', $original->fresh()->estado);
        $this->assertSame(0, $original->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(1, $operation->entregas()->count());
    }

    public function test_confirmation_rejects_modified_summary_and_missing_signature_without_delivery(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $signature = $this->signature($operation);
        $signature['review_hash'] = str_repeat('a', 64);
        $this->post(route('epps.delivery-signature.confirm', $operation), $signature)->assertSessionHasErrors();
        $this->post(route('epps.delivery-signature.confirm', $operation), [])->assertSessionHasErrors();
        $this->assertSame(0, $operation->entregas()->count());
        $this->assertSame(0, $operation->aceptacion()->count());
    }

    public function test_preparation_retry_returns_same_review_and_changed_input_is_rejected(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->post(route('epps.entregas.store'), $this->delivery)->assertRedirect(route('epps.delivery-signature.review', $operation))->assertSessionMissing('error');
        $this->post(route('epps.entregas.store'), array_replace($this->delivery, ['cantidad' => 3]))->assertSessionHas('error');
        $this->assertSame(1, EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->count());
    }

    public function test_previous_delivery_can_be_closed_from_its_row_only_after_signature(): void
    {
        $original = app(EppService::class)->deliver($this->delivery, $this->actor);
        $original->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $this->withSession($this->session)->get('/logistica?tab=entregas&q='.$this->worker->dni)->assertOk()->assertSee('data-epp-open-modal="eppCloseModal'.$original->id.'"', false);
        $this->post(route('epps.entregas.close', $original->id), ['estado' => 'DEVUELTO', 'devuelto_at' => '2026-10-09', 'motivo_cambio' => 'Internamiento'])->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('personal_id', $this->worker->id)->firstOrFail();
        $this->assertSame('ENTREGADO', $original->fresh()->estado);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame('DEVUELTO', $original->fresh()->estado);
        $this->assertSame(0, $original->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame(1, EppEntrega::query()->where('personal_id', $this->worker->id)->count());
        $this->get(route('epps.evidence.receipt', $operation))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_catalog_change_invalidates_signature_without_creating_delivery(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        EppRegistro::query()->findOrFail($this->delivery['epp_id'])->update(['colores' => ['ROJO']]);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasErrors();
        $this->assertSame(0, $operation->entregas()->count());
    }

    public function test_original_delivery_change_invalidates_the_reviewed_return(): void
    {
        $original = app(EppService::class)->deliver($this->delivery, $this->actor);
        $original->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $this->withSession($this->session)->post(route('epps.entregas.close', $original->id), ['estado' => 'DEVUELTO', 'devuelto_at' => '2026-10-09'])->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('personal_id', $this->worker->id)->firstOrFail();
        $original->update(['cantidad_pendiente_devolucion' => 1]);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasErrors();
        $this->assertSame(1, $original->fresh()->cantidad_pendiente_devolucion);
        $this->assertSame('ENTREGADO', $original->fresh()->estado);
    }

    public function test_permission_revocation_blocks_confirmation(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'SIN FIRMA '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => []]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertForbidden();
        $this->assertSame(0, $operation->entregas()->count());
    }

    public function test_signature_is_scoped_to_worker_and_never_uses_posted_quantities(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $signature = $this->signature($operation);
        $signature['personal_id'] = (string) Str::uuid();
        $this->post(route('epps.delivery-signature.confirm', $operation), $signature)->assertSessionHasErrors();
        $signature = $this->signature($operation) + ['cantidad' => 999, 'epp_id' => (string) Str::uuid()];
        $this->post(route('epps.delivery-signature.confirm', $operation), $signature)->assertSessionHasNoErrors();
        $this->assertSame(2, $operation->entregas()->firstOrFail()->cantidad);
        $role = Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'FIRMA SIN SCOPE '.Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections(['epps' => ['registrar', 'confirmar_entrega', 'ver_evidencia', 'descargar_constancia']])]);
        $this->actor->update(['rol_id' => $role->id]);
        $this->post(route('epps.entregas.store'), array_replace($this->delivery, ['submission_uuid' => (string) Str::uuid()]))->assertForbidden();
        $this->get(route('epps.evidence.verify', $operation->fresh()->aceptacion->codigo_verificacion))->assertForbidden();
        $this->get(route('epps.evidence.receipt', $operation))->assertForbidden();
    }

    public function test_add_another_reopens_the_existing_form_after_signing_without_reusing_submission(): void
    {
        $data = $this->delivery + ['_epp_add_another' => '1'];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $data)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $data['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors()->assertSessionHas('_old_input.personal_id', $this->worker->id)->assertSessionMissing('_old_input.submission_uuid');
    }

    public function test_new_required_characteristics_invalidate_pending_delivery(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        EppRegistro::query()->findOrFail($this->delivery['epp_id'])->update(['requiere_talla' => true, 'tallas' => ['M', 'L']]);
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasErrors();
        $this->assertSame(0, $operation->entregas()->count());
    }

    public function test_signature_file_is_prepared_once_before_confirmation_transaction(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $filesBefore = null;
        Event::listen(TransactionBeginning::class, function () use ($operation, &$filesBefore): void {
            $filesBefore ??= Storage::disk('local')->allFiles('epp/firmas/'.$operation->id);
        });
        try {
            $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        } finally {
            Event::forget(TransactionBeginning::class);
        }
        $this->assertCount(1, $filesBefore);
        $this->assertSame($filesBefore[0], $operation->fresh()->aceptacion->firma_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('epp/firmas/'.$operation->id));
    }

    public function test_return_uses_original_signed_item_snapshot_after_catalog_edit(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $originalOperation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $originalOperation), $this->signature($originalOperation))->assertSessionHasNoErrors();
        $original = $originalOperation->entregas()->firstOrFail();
        EppRegistro::query()->findOrFail($this->delivery['epp_id'])->update(['nombre' => 'NOMBRE NUEVO NO ENTREGADO', 'politica_devolucion' => 'CONSUMIBLE', 'vida_util_dias' => 3]);
        $this->post(route('epps.entregas.close', $original->id), ['estado' => 'DEVUELTO', 'devuelto_at' => '2026-10-09'])->assertSessionHasNoErrors();
        $return = EppOperacion::query()->where('personal_id', $this->worker->id)->where('tipo', 'DEVOLUCION')->firstOrFail();
        $this->assertSame('CASCO FLUJO EXISTENTE', $return->resumen_json['details'][0]['snapshot']['real']['nombre']);
        $this->assertSame(365, $return->resumen_json['details'][0]['snapshot']['real']['vida_util_dias']);
        $this->assertSame('RETORNABLE', $return->resumen_json['details'][0]['return_policy']);
        $this->post(route('epps.delivery-signature.confirm', $return), $this->signature($return))->assertSessionHasNoErrors();
    }

    public function test_delivery_displays_and_freezes_full_declaration_before_signing(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $response = $this->get(route('epps.delivery-signature.review', $operation))->assertOk();
        $response->assertSee('DECLARO HABER RECIBIDO LOS ELEMENTOS DE PROTECCION PERSONAL');
        $response->assertSee('debiendo asumir responsabilidad económica');
        $this->assertLessThan(strpos($response->getContent(), '<canvas'), strpos($response->getContent(), 'DECLARO HABER RECIBIDO'));
        $this->assertStringContainsString('e. Asimismo declara haberlos recibido', $operation->resumen_json['declaration_text']);
        $hash = $operation->resumen_sha256;
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $this->assertSame($hash, $operation->fresh()->aceptacion->contenido_sha256);
        $this->assertSame($operation->resumen_json['declaration_text'], $operation->fresh()->aceptacion->contenido_json['declaration_text']);
    }

    public function test_kardex_excel_uses_official_template_groups_dates_and_keeps_unsigned_history(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        app(EppService::class)->deliver(array_replace($this->delivery, ['cantidad' => 3, 'color' => 'ROJO']), $this->actor);
        app(EppService::class)->deliver(array_replace($this->delivery, ['fecha_entrega' => '2026-10-08', 'color' => 'ROJO']), $this->actor);
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-09', 'fecha_hasta' => '2026-10-09']))->assertOk();
        $book = $this->readKardexExcel($response->streamedContent());
        $sheet = $book->getSheetByName('Anterior');
        $this->assertNotNull($sheet, 'La descarga debe conservar el formulario, no la tabla tecnica.');
        $this->assertSame('SGC-FOR-59', $sheet->getCell('AD1')->getValue());
        $this->assertSame('Posterior', $book->getSheet(1)->getTitle());
        $this->assertSame('Casco ……………..…', $sheet->getCell('C12')->getValue());
        $this->assertSame('09/10/2026', $sheet->getCell('B14')->getFormattedValue());
        $this->assertNull($sheet->getCell('B19')->getValue());
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray(null, true, true, false)));
        $this->assertStringNotContainsString('PERIODO:', $text);
        $this->assertStringNotContainsString('FIRMA DEL TRABAJADOR ', $text);
        $this->assertSame(40, $sheet->getHighestRow());
        $this->assertSame('08/10/2026', $sheet->getCell('AE6')->getFormattedValue());
        foreach (range(14, 18) as $row) {
            $this->assertSame('N', $sheet->getCell('C'.$row)->getValue());
            $this->assertSame('09/10/2026', $sheet->getCell('B'.$row)->getFormattedValue());
        }
        $this->assertTrue($sheet->getStyle('C14')->getAlignment()->getWrapText());
        $this->assertSame(22.0, $sheet->getRowDimension(17)->getRowHeight());
        $signatures = collect($sheet->getDrawingCollection())->filter(fn ($drawing) => $drawing->getDescription() === 'Firma del trabajador - cierre');
        $this->assertCount(1, $signatures);
        $this->assertSame('AE34', $signatures->first()->getCoordinates());
    }

    public function test_kardex_excel_defaults_to_previous_calendar_month_and_validates_range(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Lima'));
        try {
            app(EppService::class)->deliver(array_replace($this->delivery, ['fecha_entrega' => '2026-09-30']), $this->actor);
            app(EppService::class)->deliver($this->delivery, $this->actor);
            $response = $this->withSession($this->session)->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id]))->assertOk();
            $book = $this->readKardexExcel($response->streamedContent());
            $this->assertSame('30/09/2026', $book->getSheetByName('Anterior')->getCell('B14')->getFormattedValue());
            $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-10', 'fecha_hasta' => '2026-10-01']))->assertSessionHasErrors('fecha_hasta');
        } finally {
            $this->travelBack();
        }
    }

    public function test_formatted_kardex_lists_partial_returns_on_their_own_operational_dates(): void
    {
        $this->withSession($this->session)->post(route('epps.entregas.store'), $this->delivery)->assertSessionHasNoErrors();
        $original = EppOperacion::query()->where('submission_uuid', $this->delivery['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $original), $this->signature($original))->assertSessionHasNoErrors();
        $source = $original->entregas()->firstOrFail();
        foreach (['2026-10-12', '2026-10-14'] as $date) {
            $input = ['personal_id' => $this->worker->id, 'devuelto_at' => $date, 'submission_uuid' => (string) Str::uuid(), 'items' => [['entrega_id' => $source->id, 'cantidad' => 1]]];
            $this->post(route('epps.catalog-returns.store'), $input)->assertSessionHasNoErrors();
            $return = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
            $this->post(route('epps.delivery-signature.confirm', $return), $this->signature($return))->assertSessionHasNoErrors();
        }
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-14']))->assertOk();
        $sheet = $this->readKardexExcel($response->streamedContent())->getSheetByName('Anterior');
        $this->assertSame('12/10/2026', $sheet->getCell('B14')->getFormattedValue());
        $this->assertSame('14/10/2026', $sheet->getCell('B15')->getFormattedValue());
        $this->assertSame('D', $sheet->getCell('C14')->getValue());
        $this->assertSame('D', $sheet->getCell('C15')->getValue());
        $this->assertCount(1, collect($sheet->getDrawingCollection())->filter(fn ($image) => $image->getDescription() === 'Firma del trabajador - cierre'));
    }

    public function test_formatted_kardex_uses_latest_worker_signature_in_footer_and_never_witness_signature(): void
    {
        $operations = [];
        foreach (['BLANCO', 'ROJO', 'BLANCO'] as $index => $color) {
            $input = array_replace($this->delivery, ['color' => $color, 'submission_uuid' => (string) Str::uuid()]);
            $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
            $op = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
            $this->post(route('epps.delivery-signature.confirm', $op), $this->signature($op))->assertSessionHasNoErrors();
            $op->refresh()->load('aceptacion');
            $op->aceptacion->update(['firmado_at_utc' => '2026-10-09 '.(10 + $index).':00:00']);
            $operations[] = $op;
        }
        $operations[2]->aceptacion->update(['tipo_firmante' => 'TESTIGO_AUTORIZADO']);
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-09', 'fecha_hasta' => '2026-10-09']))->assertOk();
        $sheet = $this->readKardexExcel($response->streamedContent())->getSheetByName('Anterior');
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray(null, true, true, false)));
        $names = collect($sheet->getDrawingCollection())->map->getName()->implode('|');
        $this->assertStringContainsString($operations[1]->aceptacion->id, $names);
        $this->assertStringNotContainsString($operations[0]->aceptacion->id, $names);
        $this->assertStringNotContainsString($operations[2]->aceptacion->id, $names);
        $this->assertStringNotContainsString('FIRMA DE TESTIGO', $text);
        $this->assertCount(1, collect($sheet->getDrawingCollection())->filter(fn ($image) => $image->getDescription() === 'Firma del trabajador - cierre'));
    }

    public function test_formatted_kardex_never_truncates_unknown_items_or_more_than_twenty_dates(): void
    {
        foreach (range(1, 21) as $index) {
            $product = app(EppService::class)->storeCatalog(['nombre' => $index === 1 ? '=SUM' : 'ARTICULO ESPECIAL NUMERO '.$index, 'vida_util_dias' => 365]);
            app(EppService::class)->deliver(array_replace($this->delivery, ['epp_id' => $product->id, 'fecha_entrega' => '2026-09-'.str_pad($index, 2, '0', STR_PAD_LEFT)]), $this->actor);
        }
        $response = $this->withSession($this->session)->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-30']))->assertOk();
        $book = $this->readKardexExcel($response->streamedContent());
        $this->assertGreaterThan(2, $book->getSheetCount());
        $all = collect($book->getAllSheets())->flatMap(fn ($sheet) => collect($sheet->toArray(null, true, true, false))->flatten())->filter()->implode('|');
        foreach (range(2, 21) as $index) {
            $this->assertStringContainsString('ARTICULO ESPECIAL NUMERO '.$index, $all);
        }
        $this->assertSame('21/09/2026', $book->getSheetByName('Anterior')->getCell('B55')->getFormattedValue());
        $literal = null;
        foreach ($book->getAllSheets() as $sheet) {
            $header = $sheet->getTitle() === 'Anterior' ? 12 : 5;
            for ($col = 3; $col <= 30; $col++) {
                if ($sheet->getCell([$col, $header])->getValue() === '=SUM') {
                    $literal = $sheet->getCell([$col, $header]);
                }
            }
        }
        $this->assertNotNull($literal);
        $this->assertSame('s', $literal->getDataType());
    }

    public function test_formatted_kardex_preserves_both_sides_of_a_change_in_the_selected_period(): void
    {
        $original = app(EppService::class)->deliver($this->delivery, $this->actor);
        $original->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $input = array_replace($this->delivery, ['color' => 'ROJO', 'fecha_entrega' => '2026-10-12', '_epp_replaces_entrega_id' => $original->id, '_epp_replacement_motivo' => 'Desgaste']);
        $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-12']))->assertOk();
        $sheet = $this->readKardexExcel($response->streamedContent())->getSheetByName('Anterior');
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray(null, true, true, false)));
        foreach (range(14, 17) as $row) {
            $this->assertSame('C', $sheet->getCell('C'.$row)->getValue());
        }
        $this->assertSame('BLANCO', $operation->fresh()->aceptacion->contenido_json['original_snapshot']['color']);
        $this->assertSame('ROJO', $operation->fresh()->aceptacion->contenido_json['details'][0]['color']);
    }

    public function test_formatted_kardex_separates_replacement_close_date_from_new_delivery_date(): void
    {
        $original = app(EppService::class)->deliver($this->delivery, $this->actor);
        $original->update(['es_legacy' => true, 'cantidad_original' => 2, 'cantidad_pendiente_devolucion' => 2]);
        $input = array_replace($this->delivery, ['color' => 'ROJO', 'fecha_entrega' => '2026-10-14', '_epp_replaces_entrega_id' => $original->id, '_epp_replacement_fecha' => '2026-10-12', '_epp_replacement_motivo' => 'Desgaste', '_epp_replacement_observacion' => 'CIERRE EN FECHA DISTINTA']);
        $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-14']))->assertOk();
        $sheet = $this->readKardexExcel($response->streamedContent())->getSheet(0);
        $this->assertSame('12/10/2026', $sheet->getCell('B14')->getFormattedValue());
        $this->assertSame('14/10/2026', $sheet->getCell('B16')->getFormattedValue());
        $this->assertSame('C', $sheet->getCell('C14')->getValue());
        $this->assertSame('C', $sheet->getCell('C15')->getValue());
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray()));
        $this->assertStringNotContainsString('CIERRE EN FECHA DISTINTA', $text);
        $this->assertSame('CIERRE EN FECHA DISTINTA', $operation->fresh()->aceptacion->contenido_json['input']['_epp_replacement_observacion']);
        $response = $this->get(route('epps.evidence.excel', ['personal_id' => $this->worker->id, 'fecha_desde' => '2026-10-12', 'fecha_hasta' => '2026-10-12']))->assertOk();
        $sheet = $this->readKardexExcel($response->streamedContent())->getSheet(0);
        $this->assertSame('C', $sheet->getCell('C14')->getValue());
        $this->assertSame('12/10/2026', $sheet->getCell('B15')->getFormattedValue());
        $this->assertNull($sheet->getCell('B16')->getValue());
    }

    public function test_kardex_groups_signed_batch_quantities_identically_in_screen_and_excel(): void
    {
        $vest = app(EppService::class)->storeCatalog(['nombre' => 'CHALECO AGRUPADO', 'vida_util_dias' => 180]);
        $input = ['personal_id' => $this->worker->id, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 3, 'color' => 'BLANCO'],
            ['epp_id' => $vest->id, 'cantidad' => 2],
        ]];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $hash = $operation->fresh()->aceptacion->contenido_sha256;
        $matrix = $this->get(route('epps.kardex.show', ['personal_id' => $this->worker->id]))->assertOk()->json('data.kardex');
        $this->assertCount(3, $matrix['rows']);
        $this->assertSame([2, 2, 1], array_map(fn ($row) => count($row['codes']), $matrix['rows']));
        foreach ($matrix['rows'] as $row) {
            $this->assertSame(['N'], array_values(array_unique($row['codes'])));
        }
        $sheet = app(EppKardexExcelService::class)->workbook($this->worker, $this->actor, '2026-10-09', '2026-10-09')->getSheet(0);
        $this->assertSame('N', $sheet->getCell('C14')->getValue());
        $this->assertSame('N', $sheet->getCell('D14')->getValue());
        $this->assertSame('N', $sheet->getCell('D15')->getValue());
        $this->assertSame('N', $sheet->getCell('C16')->getValue());
        $this->assertNull($sheet->getCell('D16')->getValue());
        $this->assertNull($sheet->getCell('B17')->getValue());
        $signatures = collect($sheet->getDrawingCollection())->filter(fn ($drawing) => str_starts_with($drawing->getDescription(), 'Firma del trabajador'));
        $this->assertCount(1, $signatures);
        $this->assertSame(['AE34'], $signatures->map->getCoordinates()->values()->all());
        $this->assertSame($hash, $operation->fresh()->aceptacion->contenido_sha256);

        // A return and a later delivery on the same day are separate signed batches.
        $returnInput = ['personal_id' => $this->worker->id, 'devuelto_at' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => $operation->entregas()->get()->map(fn ($delivery) => ['entrega_id' => $delivery->id, 'cantidad' => 1])->all()];
        $this->post(route('epps.catalog-returns.store'), $returnInput)->assertSessionHasNoErrors();
        $return = EppOperacion::query()->where('submission_uuid', $returnInput['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $return), $this->signature($return))->assertSessionHasNoErrors();
        $input['submission_uuid'] = (string) Str::uuid();
        $input['items'][0]['cantidad'] = 1;
        $input['items'][1]['cantidad'] = 1;
        $this->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $next = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $next), $this->signature($next))->assertSessionHasNoErrors();
        $matrix = $this->get(route('epps.kardex.show', ['personal_id' => $this->worker->id]))->assertOk()->json('data.kardex');
        $this->assertCount(5, $matrix['rows']);
        $returns = collect($matrix['rows'])->filter(fn ($row) => in_array('D', $row['codes'], true));
        $this->assertCount(1, $returns);
        $this->assertSame(['D', 'D'], array_values($returns->first()['codes']));
        $sheet = app(EppKardexExcelService::class)->workbook($this->worker, $this->actor, '2026-10-09', '2026-10-09')->getSheet(0);
        $this->assertNotNull($sheet->getCell('B18')->getValue());
        $this->assertNull($sheet->getCell('B19')->getValue());
        $cells = collect(range(14, 18))->map(fn ($row) => [$sheet->getCell('C'.$row)->getValue(), $sheet->getCell('D'.$row)->getValue()]);
        $this->assertSame(4, $cells->filter(fn ($row) => $row[0] === 'N')->count());
        $this->assertSame(3, $cells->filter(fn ($row) => $row[1] === 'N')->count());
        $this->assertSame(1, $cells->filter(fn ($row) => $row === ['D', 'D'])->count());
    }

    public function test_kardex_never_overwrites_two_items_that_share_one_template_column(): void
    {
        $otherHelmet = app(EppService::class)->storeCatalog(['nombre' => 'CASCO SEGUNDO MODELO', 'vida_util_dias' => 365]);
        $input = ['personal_id' => $this->worker->id, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid(), 'items' => [
            ['epp_id' => $this->delivery['epp_id'], 'cantidad' => 1, 'color' => 'BLANCO'],
            ['epp_id' => $otherHelmet->id, 'cantidad' => 1],
        ]];
        $this->withSession($this->session)->post(route('epps.entregas.store'), $input)->assertSessionHasNoErrors();
        $operation = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->firstOrFail();
        $this->post(route('epps.delivery-signature.confirm', $operation), $this->signature($operation))->assertSessionHasNoErrors();
        $matrix = $this->get(route('epps.kardex.show', ['personal_id' => $this->worker->id]))->assertOk()->json('data.kardex');
        $this->assertCount(2, $matrix['rows']);
        $this->assertCount(1, $matrix['items']);
        $sheet = app(EppKardexExcelService::class)->workbook($this->worker, $this->actor, '2026-10-09', '2026-10-09')->getSheet(0);
        $this->assertSame('N', $sheet->getCell('C14')->getValue());
        $this->assertSame('N', $sheet->getCell('C15')->getValue());
        $this->assertNull($sheet->getCell('B16')->getValue());
    }

    private function readKardexExcel(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'kardex-formato-');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function signature(EppOperacion $operation): array
    {
        $image = imagecreatetruecolor(600, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $pen = imagecolorallocate($image, 10, 20, 30);
        imagesetthickness($image, 3);
        imageline($image, 40, 160, 170, 50, $pen);
        imageline($image, 170, 50, 110, 210, $pen);
        imageline($image, 110, 210, 400, 100, $pen);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return ['submission_uuid' => $operation->submission_uuid, 'personal_id' => $operation->personal_id, 'review_hash' => $operation->resumen_sha256, 'review_version' => $operation->version, 'firma_data_url' => 'data:image/png;base64,'.base64_encode($bytes), 'ink' => ['point_count' => 40, 'bounds' => ['min_x' => 40, 'min_y' => 50, 'max_x' => 400, 'max_y' => 210]], 'confirmacion' => '1'];
    }
}
