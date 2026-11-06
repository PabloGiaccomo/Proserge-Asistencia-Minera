<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalIngreso;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalFichaSensitiveDataPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_personal_resource_hides_bank_and_pension_until_actor_has_sensitive_permission(): void
    {
        $ficha = PersonalFicha::findOrFail($this->createFicha());
        $session = $this->sessionForPermissions(['personal' => ['ver']]);
        $actor = \App\Models\Usuario::query()->findOrFail($session['user_id']);
        $request = \Illuminate\Http\Request::create('/api/v1/personal/'.$ficha->personal_id);
        $request->setUserResolver(fn () => $actor);
        $worker = $ficha->personal->load('fichaColaborador');
        $resource = new \App\Modules\Personal\Resources\PersonalResource($worker);
        $data = $resource->toArray($request);
        self::assertArrayNotHasKey('numero_cuenta', $data['reactivacion_datos']);
        self::assertArrayNotHasKey('costo_hora', $data['reactivacion_datos']);
        self::assertStringNotContainsString('BANCO SENSIBLE PRUEBA', json_encode($data));
        $actor->rol->forceFill(['permisos' => PermissionCatalog::matrixFromSelections(['personal' => ['ver', 'ver_datos_sensibles']])])->save();
        self::assertSame('001122334455', $resource->toArray($request)['reactivacion_datos']['numero_cuenta']);
    }

    public function test_general_editor_does_not_include_sensitive_values_in_the_response(): void
    {
        $ficha = PersonalFicha::findOrFail($this->createFicha(overrides: [
            'sistema_pensionario' => 'Sistema Privado de Pensiones',
            'cuspp' => 'CUSPP-FICHA-PRUEBA',
            'quinta_otros_empleadores_json' => '[{"empresa":"EMPLEADOR-FICHA-PRUEBA","monto":"934562.55"}]',
        ]));
        Storage::fake('local');
        $path = 'tests/ficha-sensitive/fingerprint.png';
        Storage::disk('local')->put($path, 'synthetic fingerprint bytes');
        $ficha->forceFill(['huella_path' => $path])->save();

        $response = $this->withSession($this->sessionForPermissions(['personal' => ['editar', 'actualizar']]))
            ->get(route('personal.edit', $ficha->personal_id))->assertOk();

        foreach (['001122334455', '01122200020049472278', 'CUSPP-FICHA-PRUEBA', '17000',
            'EMPLEADOR-FICHA-PRUEBA', 'data:image/png;base64,signature', $path,
            base64_encode('synthetic fingerprint bytes')] as $value) {
            $response->assertDontSee($value, false);
        }
    }

    public function test_general_editor_preserves_omitted_sensitive_fields(): void
    {
        $ficha = PersonalFicha::findOrFail($this->createFicha());
        $fields = $this->editableFields($ficha->datos_json);

        $this->withSession($this->sessionForPermissions(['personal' => ['editar', 'actualizar']]))
            ->put(route('personal.update', $ficha->personal_id), [
                'estado' => $ficha->estado,
                'fields' => [...$fields, 'correo' => 'nuevo@test.local'],
            ])->assertRedirect(route('personal.show', $ficha->personal_id));

        $fresh = $ficha->fresh();
        $this->assertSame('nuevo@test.local', $fresh->datos_json['correo']);
        $this->assertSame('001122334455', $fresh->datos_json['numero_cuenta']);
        $this->assertSame('01122200020049472278', $fresh->datos_json['cci']);
        $this->assertSame('ONP', $fresh->datos_json['sistema_pensionario']);
        $this->assertSame($ficha->firma_base64, $fresh->firma_base64);
    }

    public function test_general_editor_rejects_sensitive_changes_after_permission_is_revoked(): void
    {
        $ficha = PersonalFicha::findOrFail($this->createFicha(overrides: ['banco' => 'BCP', 'banco_otro' => '']));
        $fields = $this->editableFields($ficha->datos_json);
        Storage::fake('local');
        $session = $this->sessionForPermissions(['personal' => ['editar', 'actualizar', 'ver_datos_sensibles']]);
        $this->withSession($session)->get(route('personal.edit', $ficha->personal_id))->assertSee('001122334455');

        $roleId = DB::table('usuarios')->where('id', $session['user_id'])->value('rol_id');
        DB::table('roles')->where('id', $roleId)->update([
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['personal' => ['editar', 'actualizar']])),
        ]);

        $url = route('personal.update', $ficha->personal_id);
        $payload = ['estado' => $ficha->estado, 'fields' => [...$fields, 'correo' => 'nuevo@test.local']];
        $this->put($url, [...$payload, 'fields' => [...$fields, 'numero_cuenta' => null]])->assertForbidden();
        $this->put($url, [...$payload, 'fields' => [...$fields, 'cuspp' => 'NUEVO-CUSPP']])->assertForbidden();
        $this->put($url, [...$payload, 'firma_base64' => null])->assertForbidden();
        $this->put($url, [...$payload, 'firma_base64' => 'data:image/png;base64,replacement'])->assertForbidden();
        $this->put($url, [...$payload, 'huella' => UploadedFile::fake()->image('replacement.png')])->assertForbidden();

        $this->assertSame($ficha->datos_json, $ficha->fresh()->datos_json);
        $this->assertSame($ficha->firma_base64, $ficha->fresh()->firma_base64);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_ingreso_editor_without_sensitive_permission_cannot_replace_fingerprint(): void
    {
        $ingreso = PersonalIngreso::findOrFail($this->createIngreso());
        Storage::fake('local');
        $this->withSession($this->sessionForPermissions(['personal_ingresos' => ['ver', 'editar', 'actualizar']]))
            ->put(route('personal.ingresos.update', $ingreso->id), [
                'fields' => $this->editableFields($ingreso->datos_json),
                'huella' => UploadedFile::fake()->image('replacement.png'),
            ])->assertForbidden();

        $this->assertSame($ingreso->datos_json, $ingreso->fresh()->datos_json);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_document_download_permission_alone_cannot_download_a_fingerprint(): void
    {
        Storage::fake('local');
        $path = 'tests/ficha-sensitive/fingerprint.png';
        Storage::disk('local')->put($path, 'synthetic fingerprint bytes');
        $archivo = PersonalFichaArchivo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $this->createFicha(),
            'tipo' => 'huella',
            'nombre_original' => 'fingerprint.png',
            'path' => $path,
        ]);
        $url = route('personal.fichas.archivos.download', $archivo->id);

        $this->withSession($this->sessionForPermissions(['personal' => ['descargar_documentos']]))
            ->get($url)->assertForbidden();
        $this->withSession($this->sessionForPermissions(['personal' => ['descargar_documentos', 'ver_datos_sensibles']]))
            ->get($url)->assertDownload('fingerprint.png');
    }

    public function test_legacy_ficha_exports_enforce_sensitive_permission(): void
    {
        Storage::fake('local');
        $this->createFicha(overrides: ['banco' => 'BCP', 'banco_otro' => '']);
        $this->withSession($this->sessionForPermissions(['personal' => ['exportar']]));
        $this->postJson(route('personal.fichas.export.pdf.start'))->assertForbidden();
        $this->postJson(route('personal.fichas.export.pdf.process', Str::uuid()))->assertForbidden();
        $this->get(route('personal.fichas.export.pdf.download', Str::uuid()))->assertForbidden();

        $response = $this->post(route('personal.fichas.export.excel'), [
            'ficha_columns' => ['trabajador', 'numero_cuenta', 'remuneracion'],
        ])->assertOk();
        $sheet = tempnam(sys_get_temp_dir(), 'ficha-test-');
        try {
            file_put_contents($sheet, $response->streamedContent());
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($sheet);
            $this->assertSame(['Trabajador'], $spreadsheet->getActiveSheet()->toArray()[0]);
        } finally {
            unlink($sheet);
        }
    }

    private function editableFields(array $data): array
    {
        PersonalPuesto::query()->firstOrCreate(
            ['nombre' => $data['puesto']],
            ['id' => (string) Str::uuid(), 'activo' => true],
        );

        return array_intersect_key($data, array_flip([
            'tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno', 'apellido_materno',
            'telefono', 'correo', 'puesto', 'contrato',
        ]));
    }

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

    public function test_review_accepts_sensitive_permission_from_personal_ingresos(): void
    {
        $fichaId = $this->createFicha('87651234');
        $session = $this->sessionForPermissions([
            'personal' => ['ver', 'ver_ficha'],
            'personal_ingresos' => ['ver', 'ver_datos_sensibles'],
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
        $response->assertDontSee('Exportar PDF');
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

    public function test_ingreso_review_shows_bcp_account_and_cci_with_sensitive_permission(): void
    {
        $ingresoId = $this->createIngreso('76764482', [
            'banco' => ' bcp ',
            'banco_otro' => '',
            'numero_cuenta' => '9876543210011',
            'cci' => '00298765432100112233',
        ]);
        $session = $this->sessionForPermissions([
            'personal_ingresos' => ['ver', 'ver_datos_sensibles'],
        ]);

        $response = $this
            ->withSession($session)
            ->get(route('personal.ingresos.show', $ingresoId));

        $response->assertOk();
        $response->assertSee('Datos bancarios');
        $response->assertSee('Banco');
        $response->assertSee('Numero de cuenta');
        $response->assertSee('9876543210011');
        $response->assertSee('CCI');
        $response->assertSee('00298765432100112233');
    }

    public function test_fecha_de_nacimiento_se_muestra_como_dia_mes_anio(): void
    {
        $fichaId = $this->createFicha('91001122', ['fecha_nacimiento' => '1990-01-15']);
        $fichaSession = $this->sessionForPermissions([
            'personal' => ['ver', 'ver_ficha'],
        ]);

        $this->withSession($fichaSession)
            ->get(route('personal.fichas.review', $fichaId))
            ->assertOk()
            ->assertSee('15/01/1990')
            ->assertDontSee('1990-01-15');

        $ingresoId = $this->createIngreso('91003344', ['fecha_nacimiento' => '1985-12-03']);
        DB::table('personal_ingresos')->where('id', $ingresoId)->update([
            'familiares_json' => json_encode([[
                'nombres_apellidos' => 'FAMILIAR PRUEBA',
                'parentesco' => 'Hijo 1',
                'fecha_nacimiento' => '2015-01-01',
                'tipo_documento' => 'DNI',
                'numero_documento' => '99998888',
                'telefono' => '999888777',
                'vive_con_trabajador' => true,
                'estudia' => true,
                'contacto_emergencia' => false,
            ]]),
        ]);
        $ingresoSession = $this->sessionForPermissions([
            'personal_ingresos' => ['ver'],
        ]);

        $this->withSession($ingresoSession)
            ->get(route('personal.ingresos.show', $ingresoId))
            ->assertOk()
            ->assertSee('03/12/1985')
            ->assertSee('01/01/2015')
            ->assertDontSee('1985-12-03')
            ->assertDontSee('2015-01-01');
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

    private function createIngreso(string $documentNumber = '12345678', array $overrides = []): string
    {
        $ingresoId = (string) Str::uuid();
        $data = array_replace([
            ...PersonalFichaCatalog::emptyData(),
            'nombres' => 'Javier Francisco',
            'apellido_paterno' => 'Hinojosa',
            'apellido_materno' => 'Aiquipa',
            'tipo_documento' => 'DNI',
            'numero_documento' => $documentNumber,
            'telefono' => '967049572',
            'correo' => 'javier_hinojosa@test.local',
            'puesto' => 'Supervisor de operaciones',
            'contrato' => 'FIJO',
            'banco' => 'BCP',
            'banco_otro' => '',
            'numero_cuenta' => '001122334455',
            'cci' => '01122200020049472278',
            'sistema_pensionario' => 'ONP',
        ], $overrides);

        DB::table('personal_ingresos')->insert([
            'id' => $ingresoId,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $documentNumber,
            'datos_json' => json_encode($data),
            'familiares_json' => json_encode([]),
            'firma_base64' => 'data:image/png;base64,signature',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ingresoId;
    }
}
