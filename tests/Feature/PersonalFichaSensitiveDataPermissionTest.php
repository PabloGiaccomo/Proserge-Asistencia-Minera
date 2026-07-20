<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalFichaSensitiveDataPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_update_permission_does_not_allow_sensitive_ficha_data(): void
    {
        $permissions = PermissionCatalog::matrixFromSelections([
            'personal' => ['ver', 'editar', 'actualizar', 'exportar'],
        ]);

        $this->assertTrue(PermissionMatrix::allows($permissions, 'personal', 'actualizar'));
        $this->assertFalse(PermissionMatrix::allows($permissions, 'personal', 'ver_datos_sensibles'));
    }

    public function test_personal_catalog_exposes_granular_screen_actions(): void
    {
        $actions = PermissionCatalog::availableModuleActions()['personal'] ?? [];

        foreach ([
            'ver_detalle',
            'ver_ingresos',
            'ver_ficha',
            'ver_documentos',
            'ver_contratos',
            'editar_datos_contrato',
            'exportar_excel',
            'importar_master_general',
            'activar_trabajador',
            'cesar_trabajador',
            'descargar_documentos',
            'descargar_formato_contrato',
            'subir_contrato_firmado',
            'gestionar_lista_negra',
            'gestionar_puestos',
        ] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_update_permission_does_not_allow_granular_personal_actions(): void
    {
        $permissions = PermissionCatalog::matrixFromSelections([
            'personal' => ['ver', 'editar', 'actualizar'],
        ]);

        $this->assertFalse(PermissionMatrix::allows($permissions, 'personal', 'descargar_documentos'));
        $this->assertFalse(PermissionMatrix::allows($permissions, 'personal', 'gestionar_puestos'));
        $this->assertFalse(PermissionMatrix::allows($permissions, 'personal', 'cesar_trabajador'));
    }

    public function test_review_hides_sensitive_ficha_sections_without_sensitive_permission(): void
    {
        $fichaId = $this->createFicha();
        $session = $this->sessionForPermissions([
            'personal' => ['ver', 'ver_ficha', 'editar', 'actualizar', 'exportar'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('personal.fichas.review', $fichaId));

        $response->assertOk();
        $response->assertDontSee('Datos bancarios');
        $response->assertDontSee('Sistema pensionario');
        $response->assertDontSee('Firma y huella');
        $response->assertDontSee('BANCO SENSIBLE PRUEBA');
        $response->assertDontSee('01122200020049472278');
        $response->assertDontSee('17000');
        $response->assertDontSee('Exportar PDF');
    }

    public function test_review_shows_sensitive_ficha_sections_with_sensitive_permission(): void
    {
        $fichaId = $this->createFicha('87654321');
        $session = $this->sessionForPermissions([
            'personal' => ['ver', 'ver_ficha', 'exportar', 'ver_datos_sensibles'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('personal.fichas.review', $fichaId));

        $response->assertOk();
        $response->assertSee('Datos bancarios');
        $response->assertSee('Sistema pensionario');
        $response->assertSee('Firma y huella');
        $response->assertSee('BANCO SENSIBLE PRUEBA');
        $response->assertSee('01122200020049472278');
        $response->assertSee('17000');
        $response->assertSee('Exportar PDF');
    }

    public function test_review_shows_bcp_account_and_cci_when_sensitive_permission_is_enabled(): void
    {
        $fichaId = $this->createFicha('77889900', [
            'banco' => ' bcp ',
            'banco_otro' => '',
            'numero_cuenta' => '1234567890123',
            'cci' => '00212345678901234567',
        ]);
        $session = $this->sessionForPermissions([
            'personal' => ['ver', 'ver_ficha', 'ver_datos_sensibles'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('personal.fichas.review', $fichaId));

        $response->assertOk();
        $response->assertSee('Datos bancarios');
        $response->assertSee('Numero de cuenta');
        $response->assertSee('1234567890123');
        $response->assertSee('CCI');
        $response->assertSee('00212345678901234567');
    }

    public function test_ficha_pdf_is_forbidden_without_sensitive_permission(): void
    {
        $fichaId = $this->createFicha('11223344');
        $session = $this->sessionForPermissions([
            'personal' => ['ver', 'exportar'],
        ]);

        $this
            ->withSession($session)
            ->get(route('personal.fichas.pdf', $fichaId))
            ->assertForbidden();
    }

    private function sessionForPermissions(array $permissions): array
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'ROL_FICHA_SENSIBLE_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'auth_token' => 'token-' . $userId,
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'sensibles@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }

    private function createFicha(string $documentNumber = '12345678', array $overrides = []): string
    {
        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $data = array_replace([
            ...PersonalFichaCatalog::emptyData(),
            'nombres' => 'Trabajador',
            'apellido_paterno' => 'Ficha',
            'apellido_materno' => 'Sensible',
            'tipo_documento' => 'DNI',
            'numero_documento' => $documentNumber,
            'telefono' => '999111222',
            'correo' => 'ficha.sensible@test.local',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'banco' => 'Otro',
            'banco_otro' => 'BANCO SENSIBLE PRUEBA',
            'numero_cuenta' => '001122334455',
            'cci' => '01122200020049472278',
            'empleador_razon_social' => 'Empresa sensible prueba',
            'empleador_ruc' => '20539399536',
            'empleador_domicilio_fiscal' => 'Domicilio fiscal sensible prueba',
            'remuneracion' => '17000',
            'sistema_pensionario' => 'ONP',
        ], $overrides);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => $documentNumber,
            'tipo_documento' => 'DNI',
            'numero_documento' => $documentNumber,
            'nombre_completo' => 'TRABAJADOR FICHA SENSIBLE',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-01',
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'telefono' => '999111222',
            'telefono_1' => '999111222',
            'correo' => 'ficha.sensible@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $documentNumber,
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'firma_base64' => 'data:image/png;base64,signature',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $fichaId;
    }
}
