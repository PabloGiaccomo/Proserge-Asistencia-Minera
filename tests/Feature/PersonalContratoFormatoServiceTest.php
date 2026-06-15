<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\PersonalContratoFormatoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalContratoFormatoServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_preview_uses_template_columns_and_selected_worker_data(): void
    {
        $personalId = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '76543210',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543210',
            'nombre_completo' => 'CONTRATO FORMATO TEST',
            'puesto' => 'Soldador',
            'ocupacion' => 'Tecnico',
            'contrato' => 'INTER',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-02',
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'contrato-formato@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'estado' => 'APROBADO',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543210',
            'datos_json' => json_encode([
                'numero_documento' => '76543210',
                'correo' => 'ficha-contrato@test.local',
                'domicilio_direccion' => 'AV. PRUEBA 123',
                'domicilio_distrito' => 'CERRO COLORADO',
                'domicilio_provincia' => 'AREQUIPA',
                'domicilio_departamento' => 'AREQUIPA',
                'puesto' => 'SOLDADOR 3G',
                'fecha_ingreso' => '2026-06-02',
                'fecha_fin_contrato' => '2026-06-30',
            ]),
            'datos_detectados_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'fecha_inicio_contrato' => '2026-07-01',
            'fecha_fin_contrato' => '2026-07-31',
            'periodo_prueba_inicio' => '2026-07-01',
            'periodo_prueba_fin' => '2026-07-15',
            'puesto' => 'SUPERVISOR CONTRATO',
            'sueldo_num' => '3500',
            'sueldo_texto' => 'TRES MIL QUINIENTOS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(PersonalContratoFormatoService::class)->preview('nuevos_inter_2026_06_02', [$personalId]);

        $this->assertSame('NUEVOS INTER - 02/06/2026', $preview['template']['label']);
        $this->assertSame('IDENTIFICADOR', $preview['template']['columns'][0]);
        $this->assertSame('76543210', $preview['rows'][0][0]);
        $this->assertSame('CONTRATO FORMATO TEST', $preview['rows'][0][1]);
        $this->assertSame('AV. PRUEBA 123', $preview['rows'][0][3]);
        $this->assertSame('CERRO COLORADO - AREQUIPA - AREQUIPA', $preview['rows'][0][4]);
        $this->assertSame('ficha-contrato@test.local', $preview['rows'][0][5]);
        $this->assertSame('01 DE JULIO DEL 2026', $preview['rows'][0][6]);
        $this->assertSame('31 DE JULIO DEL 2026', $preview['rows'][0][7]);
        $this->assertSame('SUPERVISOR CONTRATO', $preview['rows'][0][14]);
    }

    public function test_contract_format_routes_return_templates_preview_and_download(): void
    {
        $session = $this->exportSession();
        $personalId = $this->createWorker();

        $this->withSession($session)
            ->getJson('/personal/formatos-contrato')
            ->assertOk()
            ->assertJsonPath('templates.0.id', 'nuevos_inter_2026_06_02');

        $this->withSession($session)
            ->postJson('/personal/formatos-contrato/preview', [
                'template_id' => 'nuevos_inter_2026_06_02',
                'personal_ids' => [$personalId],
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.0', '76543210');

        $this->withSession($session)
            ->post('/personal/formatos-contrato/descargar', [
                'template_id' => 'nuevos_inter_2026_06_02',
                'personal_ids' => [$personalId],
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertNotNull(
            DB::table('personal_contrato_datos')->where('personal_id', $personalId)->value('downloaded_at')
        );
    }

    public function test_signed_contract_upload_activates_worker_after_download(): void
    {
        Storage::fake('local');

        $session = $this->updateSession();
        $personalId = $this->createWorker('FALTA_CONTRATO');

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'downloaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($session)
            ->post('/personal/' . $personalId . '/contrato-firmado', [
                'contrato_pdf' => UploadedFile::fake()->create('contrato_firmado.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect('/personal');

        $contract = DB::table('personal_contrato_datos')->where('personal_id', $personalId)->first();

        $this->assertSame('ACTIVO', DB::table('personal')->where('id', $personalId)->value('estado'));
        $this->assertNotNull($contract->signed_at);
        $this->assertSame('contrato_firmado.pdf', $contract->signed_contract_original_name);
        Storage::disk('local')->assertExists($contract->signed_contract_path);
    }

    public function test_contract_data_update_redirects_to_personal_list(): void
    {
        $session = $this->updateSession();
        $personalId = $this->createWorker('FALTA_CONTRATO');

        DB::table('personal_puestos')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'nombre' => 'Operario contrato',
            'funciones' => null,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($session)
            ->put('/personal/' . $personalId . '/datos-contrato', [
                'fecha_inicio_contrato' => '2026-06-03',
                'fecha_fin_contrato' => '2026-12-31',
                'periodo_prueba_inicio' => '2026-06-03',
                'periodo_prueba_fin' => '2026-09-02',
                'puesto' => 'Operario contrato',
                'sueldo_num' => '2500',
                'sueldo_texto' => 'DOS MIL QUINIENTOS',
            ])
            ->assertRedirect('/personal');

        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personalId,
            'fecha_inicio_contrato' => '2026-06-03',
            'fecha_fin_contrato' => '2026-12-31',
            'periodo_prueba_inicio' => '2026-06-03',
            'periodo_prueba_fin' => '2026-09-02',
            'puesto' => 'Operario contrato',
            'sueldo_num' => '2500',
            'sueldo_texto' => 'DOS MIL QUINIENTOS',
        ]);
        $this->assertSame('Operario contrato', DB::table('personal')->where('id', $personalId)->value('puesto'));
    }

    public function test_signed_contract_can_be_downloaded_from_documents_view(): void
    {
        Storage::fake('local');

        $session = $this->updateSession();
        $personalId = $this->createWorker('ACTIVO');
        $path = 'personal_contratos/' . $personalId . '/contrato_firmado_test.pdf';
        Storage::disk('local')->put($path, 'PDF firmado de prueba');

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'signed_at' => now(),
            'signed_contract_path' => $path,
            'signed_contract_original_name' => 'contrato_firmado_test.pdf',
            'signed_contract_mime' => 'application/pdf',
            'signed_contract_size' => strlen('PDF firmado de prueba'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($session)
            ->get('/personal/' . $personalId . '/documentos/contrato-firmado')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('PDF firmado de prueba');
    }

    private function createWorker(string $estado = 'ACTIVO'): string
    {
        $personalId = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '76543210',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543210',
            'nombre_completo' => 'CONTRATO FORMATO TEST',
            'puesto' => 'Soldador',
            'ocupacion' => 'Tecnico',
            'contrato' => 'INTER',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-02',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'contrato-formato@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'estado' => 'APROBADO',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543210',
            'datos_json' => json_encode([
                'numero_documento' => '76543210',
                'correo' => 'ficha-contrato@test.local',
                'domicilio_direccion' => 'AV. PRUEBA 123',
                'domicilio_distrito' => 'CERRO COLORADO',
                'domicilio_provincia' => 'AREQUIPA',
                'domicilio_departamento' => 'AREQUIPA',
                'puesto' => 'SOLDADOR 3G',
                'fecha_ingreso' => '2026-06-02',
                'fecha_fin_contrato' => '2026-06-30',
            ]),
            'datos_detectados_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personalId;
    }

    private function exportSession(): array
    {
        return $this->sessionWithPersonalPermissions(['ver', 'exportar']);
    }

    private function updateSession(): array
    {
        return $this->sessionWithPersonalPermissions(['ver', 'actualizar']);
    }

    private function sessionWithPersonalPermissions(array $actions): array
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'EXPORT_CONTRATO_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'personal' => $actions,
            ])),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'export@test.local',
                'permissions' => PermissionCatalog::matrixFromSelections([
                    'personal' => $actions,
                ]),
            ],
        ];
    }
}
