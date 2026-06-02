<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\PersonalContratoFormatoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        $preview = app(PersonalContratoFormatoService::class)->preview('nuevos_inter_2026_06_02', [$personalId]);

        $this->assertSame('NUEVOS INTER - 02/06/2026', $preview['template']['label']);
        $this->assertSame('IDENTIFICADOR', $preview['template']['columns'][0]);
        $this->assertSame('76543210', $preview['rows'][0][0]);
        $this->assertSame('CONTRATO FORMATO TEST', $preview['rows'][0][1]);
        $this->assertSame('AV. PRUEBA 123', $preview['rows'][0][3]);
        $this->assertSame('CERRO COLORADO - AREQUIPA - AREQUIPA', $preview['rows'][0][4]);
        $this->assertSame('ficha-contrato@test.local', $preview['rows'][0][5]);
        $this->assertSame('02 DE JUNIO DEL 2026', $preview['rows'][0][6]);
        $this->assertSame('30 DE JUNIO DEL 2026', $preview['rows'][0][7]);
        $this->assertSame('SOLDADOR 3G', $preview['rows'][0][14]);
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
    }

    private function createWorker(): string
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

        return $personalId;
    }

    private function exportSession(): array
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'EXPORT_CONTRATO_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'personal' => ['ver', 'exportar'],
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
                    'personal' => ['ver', 'exportar'],
                ]),
            ],
        ];
    }
}
