<?php

namespace Tests\Feature;

use App\Models\EppAceptacion;
use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
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
        $signature = 'private-signature-bytes';
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
