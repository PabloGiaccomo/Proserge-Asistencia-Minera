<?php

namespace Tests\Feature;

use App\Models\EppAceptacion;
use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Modules\Epps\Services\EppKardexExcelService;
use App\Modules\Epps\Services\EppKardexService;
use App\Modules\Epps\Services\EppQrCodeService;
use App\Modules\Epps\Services\EppReceiptPdfService;
use App\Modules\Epps\Services\EppService;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EppKardexEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    private Usuario $viewer;

    private Personal $worker;

    private EppOperacion $operation;

    private EppAceptacion $acceptance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $permissions = PermissionCatalog::matrixFromSelections(['epps' => ['ver_evidencia', 'descargar_constancia', 'ver_operaciones']]);
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $role->update(['permisos' => $permissions, 'estado' => 'ACTIVO']);
        $this->viewer = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'secret123', 'rol_id' => $role->id, 'estado' => 'ACTIVO'])->load('rol');
        $this->worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'TRABAJADOR EVIDENCIA EPP', 'puesto' => 'OPERARIO', 'estado' => 'ACTIVO', 'qr_code' => (string) Str::uuid()]);
        $warehouse = EppAlmacen::query()->create(['nombre' => 'ALMACEN EVIDENCIA '.Str::random(6)]);
        $epp = app(EppService::class)->storeCatalog(['nombre' => 'CASCO EVIDENCIA '.Str::random(6), 'codigo' => 'CAS-EV', 'estado' => 'ACTIVO', 'politica_devolucion' => 'RETORNABLE', 'control_inventario' => true]);
        $this->operation = EppOperacion::query()->create(['personal_id' => $this->worker->id, 'almacen_id' => $warehouse->id, 'tipo' => 'ENTREGA', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(), 'version' => 1, 'firmado_at' => now(), 'registrado_por_usuario_id' => $this->viewer->id]);
        EppOperacionDetalle::query()->create(['operacion_id' => $this->operation->id, 'epp_solicitado_id' => $epp->id, 'epp_id' => $epp->id, 'cantidad_solicitada' => 2, 'cantidad_procesada' => 2, 'cantidad_pendiente' => 0, 'estado' => 'ENTREGADO', 'politica_devolucion_snapshot' => 'RETORNABLE', 'control_inventario_snapshot' => true, 'snapshot_json' => ['epp_name' => $epp->nombre]]);
        $payload = EppCanonicalPayload::normalize(['schema_version' => 1, 'operation_id' => $this->operation->id, 'submission_uuid' => $this->operation->submission_uuid, 'revision' => 1, 'type' => 'ENTREGA', 'state' => 'LISTO_PARA_FIRMA', 'declaration_version' => 'DELIVERY_V1', 'worker' => ['id' => $this->worker->id, 'name' => $this->worker->nombre_completo, 'document' => $this->worker->dni], 'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->nombre, 'mine_id' => null], 'details' => [['epp_id' => $epp->id, 'epp_name' => $epp->nombre, 'requested_quantity' => 2, 'processed_quantity' => 2, 'pending_quantity' => 0]], 'pending' => [], 'rejected' => []]);
        $json = EppCanonicalPayload::json($payload);
        $image = imagecreatetruecolor(600, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 30, 250, 450, 30, imagecolorallocate($image, 20, 20, 20));
        ob_start();
        imagepng($image);
        $signature = ob_get_clean();
        imagedestroy($image);
        $path = 'epp/firmas/'.$this->operation->id.'/firma.png';
        Storage::disk('local')->put($path, $signature);
        $this->operation->update(['resumen_json' => $payload, 'resumen_sha256' => hash('sha256', $json)]);
        $this->acceptance = EppAceptacion::query()->create(['operacion_id' => $this->operation->id, 'personal_id' => $this->worker->id, 'tipo_firmante' => 'TRABAJADOR', 'firmante_nombre' => $this->worker->nombre_completo, 'documento_snapshot' => $this->worker->dni, 'operador_usuario_id' => $this->viewer->id, 'firma_path' => $path, 'firma_sha256' => hash('sha256', $signature), 'contenido_json' => $json, 'contenido_sha256' => hash('sha256', $json), 'codigo_verificacion' => 'EV-'.Str::random(30), 'submission_uuid' => $this->operation->submission_uuid, 'firmado_at' => now(), 'firmado_at_utc' => now()->utc(), 'zona_horaria' => 'America/Lima', 'declaracion_version' => 'DELIVERY_V1', 'estado_integridad' => 'FIRMA_VALIDA']);
        EppEntrega::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'epp_id' => $epp->id, 'cantidad' => 1, 'fecha_entrega' => now()->subDay()->toDateString(), 'estado' => 'ENTREGADO', 'es_legacy' => true, 'politica_devolucion_snapshot' => 'RETORNABLE', 'cantidad_pendiente_devolucion' => 1]);
    }

    public function test_kardex_distinguishes_signed_and_legacy_and_links_corrections(): void
    {
        EppOperacion::query()->create(['personal_id' => $this->worker->id, 'almacen_id' => $this->operation->almacen_id, 'tipo' => 'CORRECCION', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(), 'operacion_origen_id' => $this->operation->id, 'version' => 1, 'firmado_at' => now(), 'registrado_por_usuario_id' => $this->viewer->id]);

        $operations = app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'];

        $this->assertContains('FIRMADO_VALIDO', array_column($operations, 'evidence_state'));
        $this->assertContains('LEGACY_SIN_FIRMA', array_column($operations, 'evidence_state'));
        $this->assertSame($this->operation->id, collect($operations)->firstWhere('type', 'CORRECCION')['original_operation_id']);
    }

    public function test_worker_kardex_keeps_visible_operations_when_another_warehouse_is_hidden(): void
    {
        $mine = Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'MINA VISIBLE '.Str::uuid(), 'unidad_minera' => 'UM TEST']);
        $this->operation->almacen->update(['mina_id' => $mine->id]);
        $this->viewer->rol->update(['nombre' => 'LECTOR LIMITADO '.Str::uuid()]);
        UsuarioMinaScope::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $this->viewer->id, 'mina_id' => $mine->id]);
        PersonalMina::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id, 'mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'activo' => true]);
        $hidden = $this->operation->replicate();
        $hidden->id = (string) Str::uuid();
        $hidden->submission_uuid = (string) Str::uuid();
        $hidden->almacen_id = EppAlmacen::query()->create(['nombre' => 'ALMACEN OCULTO '.Str::uuid()])->id;
        $hidden->save();
        $ids = array_column(app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'], 'id');
        $this->assertContains($this->operation->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_tampered_signature_is_invalid(): void
    {
        Storage::disk('local')->put($this->acceptance->firma_path, 'altered');

        $signed = collect(app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'])->firstWhere('id', $this->operation->id);

        $this->assertSame('EVIDENCIA_INVALIDA', $signed['evidence_state']);
    }

    public function test_missing_file_and_altered_content_are_invalid(): void
    {
        Storage::disk('local')->delete($this->acceptance->firma_path);
        $signed = collect(app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'])->firstWhere('id', $this->operation->id);
        $this->assertSame('EVIDENCIA_INVALIDA', $signed['evidence_state']);
        $this->acceptance->update(['contenido_json' => ['altered' => true]]);
        $signed = collect(app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'])->firstWhere('id', $this->operation->id);
        $this->assertSame('EVIDENCIA_INVALIDA', $signed['evidence_state']);
    }

    public function test_evidence_download_enforces_scope(): void
    {
        $this->viewer->rol->update(['nombre' => 'OPERADOR_EVIDENCIA_'.Str::random(8)]);
        $session = ['auth_token' => 'test-token', 'user_id' => $this->viewer->id, 'user' => ['id' => $this->viewer->id, 'rol' => $this->viewer->rol->nombre, 'permissions' => $this->viewer->rol->permisos]];
        $this->withSession($session)->get(route('epps.evidence.receipt', $this->operation))->assertForbidden();
        $this->withSession($session)->get(route('epps.evidence.verify', $this->acceptance->codigo_verificacion))->assertForbidden();
    }

    public function test_pdf_contains_canonical_details_signature_code_and_internal_qr(): void
    {
        $service = app(EppReceiptPdfService::class);
        $html = $service->html($this->operation);

        $this->assertStringContainsString('CASCO EVIDENCIA', $html);
        $this->assertStringContainsString($this->acceptance->codigo_verificacion, $html);
        $this->assertStringContainsString('Firma del trabajador', $html);
        $this->assertStringStartsWith('%PDF', $service->output($this->operation));
        $svg = app(EppQrCodeService::class)->svg(route('epps.evidence.verify', $this->acceptance->codigo_verificacion));
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString($this->worker->dni, $svg);
        $this->assertStringNotContainsString($this->acceptance->firma_sha256, $svg);
    }

    public function test_reports_preserve_signed_names_attributes_and_return_facts(): void
    {
        $payload = $this->acceptance->contenido_json;
        $payload['details'][0]['snapshot'] = ['real' => ['nombre' => 'NOMBRE FIRMADO ORIGINAL']];
        $payload['details'][0]['attributes'] = ['MATERIAL' => 'CUERO ESPECIAL'];
        $payload['details'][0]['return_condition'] = 'DANADO';
        $payload['details'][0]['inventory_destination'] = 'CUARENTENA';
        $payload['pending'] = [['snapshot' => ['solicitado' => ['nombre' => 'PENDIENTE ORIGINAL']], 'requested_quantity' => 3, 'processed_quantity' => 0, 'pending_quantity' => 3]];
        $payload['rejected'] = [['snapshot' => ['solicitado' => ['nombre' => 'ARTICULO RECHAZADO']], 'reason' => 'No corresponde al trabajador']];
        $this->replacePayload($payload);
        $this->operation->detalles->first()->epp->update(['nombre' => 'CATALOGO RENOMBRADO']);
        $operations = app(EppKardexService::class)->forWorker($this->worker, $this->viewer)['operations'];
        $signed = collect($operations)->firstWhere('id', $this->operation->id);
        $this->assertSame('NOMBRE FIRMADO ORIGINAL', $signed['details'][0]['epp']);
        $html = app(EppReceiptPdfService::class)->html($this->operation->fresh());
        foreach (['NOMBRE FIRMADO ORIGINAL', 'CUERO ESPECIAL', 'DANADO', 'CUARENTENA', 'PENDIENTE ORIGINAL', 'ARTICULO RECHAZADO', 'No corresponde al trabajador'] as $fact) {
            $this->assertStringContainsString($fact, $html);
        }
    }

    public function test_excel_keeps_catalog_formula_text_as_literal_string(): void
    {
        $payload = $this->acceptance->contenido_json;
        $payload['details'][0]['epp_name'] = '=SUM';
        $payload['details'][0]['snapshot'] = ['real' => ['nombre' => '=SUM']];
        $payload['details'][0]['processed_quantity'] = 1;
        $this->replacePayload($payload);
        $this->operation->detalles->first()->epp->update(['nombre' => '=SUM']);
        $this->operation->detalles->first()->update(['cantidad_procesada' => 1]);
        $response = app(EppKardexService::class)->downloadExcel($this->worker, $this->viewer);
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'epp-formula-');
        file_put_contents($path, $bytes);
        try {
            $cell = IOFactory::load($path)->getActiveSheet()->getCell('H2');
            $this->assertSame('s', $cell->getDataType());
            $this->assertStringStartsWith('=SUM (1)', $cell->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_formatted_kardex_uses_lima_date_without_appending_technical_observations(): void
    {
        EppEntrega::query()->where('personal_id', $this->worker->id)->whereNull('operacion_id')->update(['fecha_entrega' => '2026-10-08']);
        $this->operation->update(['firmado_at' => '2026-10-10 02:00:00']);
        $this->acceptance->update(['firmado_at_utc' => '2026-10-10 02:00:00']);
        $payload = $this->acceptance->contenido_json;
        $payload['notes'] = 'OBSERVACION GENERAL ALMACEN';
        $payload['input']['observacion'] = 'OBSERVACION GENERAL LOTE';
        $payload['details'][0]['reason'] = 'OBSERVACION ARTICULO';
        $payload['details'][0]['return_condition'] = 'DANADO';
        $payload['details'][0]['inventory_destination'] = 'CUARENTENA';
        $this->replacePayload($payload);
        $book = app(EppKardexExcelService::class)->workbook($this->worker, $this->viewer, '2026-10-09', '2026-10-09');
        $sheet = $book->getSheet(0);
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray()));
        foreach (['OBSERVACION GENERAL ALMACEN', 'OBSERVACION GENERAL LOTE', 'OBSERVACION ARTICULO', 'DANADO', 'CUARENTENA'] as $fact) {
            $this->assertStringNotContainsString($fact, $text);
        }
        $this->assertSame(EppCanonicalPayload::json($payload), EppCanonicalPayload::json($this->acceptance->fresh()->contenido_json));
        $this->assertSame('09/10/2026', $sheet->getCell('B14')->getFormattedValue());
        $this->assertSame('09/10/2026', $sheet->getCell('B15')->getFormattedValue());
        $this->assertNull($sheet->getCell('B16')->getValue());
    }

    public function test_formatted_kardex_does_not_invent_delivery_codes_for_administrative_corrections(): void
    {
        $correction = EppOperacion::query()->create(['personal_id' => $this->worker->id, 'almacen_id' => $this->operation->almacen_id, 'tipo' => 'CORRECCION', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(), 'operacion_origen_id' => $this->operation->id, 'version' => 1, 'created_at' => '2026-10-12 16:00:00', 'registrado_por_usuario_id' => $this->viewer->id, 'motivo' => 'AJUSTE ADMINISTRATIVO DOCUMENTADO']);
        $correction->forceFill(['created_at' => '2026-10-12 16:00:00'])->save();
        $detail = $this->operation->detalles->first()->replicate();
        $detail->operacion_id = $correction->id;
        $detail->motivo = 'AJUSTE ADMINISTRATIVO DOCUMENTADO';
        $detail->save();
        $book = app(EppKardexExcelService::class)->workbook($this->worker, $this->viewer, '2026-10-12', '2026-10-12');
        $sheet = $book->getSheet(0);
        $text = implode('|', array_map(fn ($row) => implode('|', $row), $sheet->toArray()));
        $this->assertStringNotContainsString('AJUSTE ADMINISTRATIVO DOCUMENTADO', $text);
        $this->assertNull($sheet->getCell('C14')->getValue());
        $this->assertNull($sheet->getCell('B14')->getValue());
        $this->assertSame('AJUSTE ADMINISTRATIVO DOCUMENTADO', $correction->fresh()->motivo);
        $this->assertCount(0, collect($sheet->getDrawingCollection())->where('description', 'Firma del trabajador'));
    }

    private function replacePayload(array $payload): void
    {
        $json = EppCanonicalPayload::json($payload);
        $this->acceptance->update(['contenido_json' => $json, 'contenido_sha256' => hash('sha256', $json)]);
        $this->operation->update(['resumen_json' => $payload, 'resumen_sha256' => hash('sha256', $json)]);
    }

    public function test_excel_contains_verification_columns(): void
    {
        $response = app(EppKardexService::class)->downloadExcel($this->worker, $this->viewer);
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'epp-kardex-');
        file_put_contents($path, $bytes);
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $text = implode('|', $sheet->toArray(null, true, true, false)[0]);
            $this->assertStringContainsString('ID operacion', $text);
            $this->assertStringContainsString('Estado evidencia', $text);
            $this->assertStringContainsString('Codigo verificacion', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_evidence_routes_require_permission_and_do_not_expose_private_path(): void
    {
        $session = ['auth_token' => 'test-token', 'user_id' => $this->viewer->id, 'user' => ['id' => $this->viewer->id, 'email' => $this->viewer->email, 'rol' => 'ADMIN', 'permissions' => PermissionCatalog::matrixFromSelections(['epps' => ['ver_evidencia', 'descargar_constancia']])]];

        $this->withSession($session)->get(route('epps.evidence.verify', $this->acceptance->codigo_verificacion))
            ->assertOk()->assertSee($this->acceptance->codigo_verificacion)->assertDontSee($this->acceptance->firma_path);
        $this->withSession($session)->get(route('epps.evidence.receipt', $this->operation))->assertOk()->assertHeader('content-type', 'application/pdf');
        $session['user']['permissions'] = PermissionCatalog::matrixFromSelections([]);
        $this->viewer->rol->update(['nombre' => 'SIN_EVIDENCIA_'.Str::random(8), 'permisos' => $session['user']['permissions']]);
        $session['user']['rol'] = $this->viewer->rol->nombre;
        $this->withSession($session)->get(route('epps.evidence.verify', $this->acceptance->codigo_verificacion))->assertForbidden();
    }
}
