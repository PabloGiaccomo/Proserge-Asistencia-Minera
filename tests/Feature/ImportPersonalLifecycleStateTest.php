<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Modules\Personal\Resources\PersonalIndexResource;
use App\Modules\Personal\Services\ImportPersonalService;
use App\Modules\Personal\Services\PersonalContratoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportPersonalLifecycleStateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_master_import_does_not_create_new_worker_as_active_without_signed_contract(): void
    {
        $dni = '71000001';
        $result = app(ImportPersonalService::class)->import($this->masterUpload([
            [$dni, 'Nuevo Importado', 'Operario', '2026-06-01'],
        ]));

        $this->assertDatabaseHas('personal', [
            'dni' => $dni,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
        ]);
        $this->assertSame(1, $result['activacionesBloqueadas']);
        $this->assertSame('El trabajador no fue activado porque no tiene contrato firmado vigente.', $result['activacionesBloqueadasDetalle'][0]['motivo']);
    }

    public function test_master_import_does_not_keep_existing_worker_active_without_signed_contract(): void
    {
        $dni = '71000002';
        $this->createPersonal($dni, 'ACTIVO');

        app(ImportPersonalService::class)->import($this->masterUpload([
            [$dni, 'Activo Sin Contrato', 'Operario', '2026-06-01'],
        ]));

        $this->assertDatabaseHas('personal', [
            'dni' => $dni,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
        ]);
    }

    public function test_master_import_rejects_old_signed_contract_as_current_activation(): void
    {
        $dni = '71000003';
        $personalId = $this->createPersonal($dni, 'ACTIVO');
        $this->createFicha($personalId, PersonalFicha::ESTADO_APROBADO);
        $this->createActiveContract($personalId, '2026-06-01 09:00:00');
        $this->createSignedContractData($personalId, '2026-05-15 09:00:00');

        app(ImportPersonalService::class)->import($this->masterUpload([
            [$dni, 'Activo Firma Antigua', 'Operario', '2026-06-01'],
        ]));

        $this->assertDatabaseHas('personal', [
            'dni' => $dni,
            'estado' => 'FALTA_CONTRATO',
        ]);
    }

    public function test_master_import_allows_active_when_signed_contract_is_current(): void
    {
        $dni = '71000004';
        $personalId = $this->createPersonal($dni, 'INACTIVO');
        $this->createFicha($personalId, PersonalFicha::ESTADO_APROBADO);
        $this->createActiveContract($personalId, '2026-06-01 09:00:00');
        $this->createSignedContractData($personalId, '2026-06-01 10:00:00');

        $result = app(ImportPersonalService::class)->import($this->masterUpload([
            [$dni, 'Inactivo Firma Vigente', 'Operario', '2026-06-01'],
        ]));

        $this->assertDatabaseHas('personal', [
            'dni' => $dni,
            'estado' => 'ACTIVO',
        ]);
        $this->assertSame(1, $result['reactivados']);
        $this->assertSame(0, $result['activacionesBloqueadas']);
    }

    public function test_contact_import_does_not_change_existing_non_active_state(): void
    {
        $dni = '71000005';
        $this->createPersonal($dni, PersonalFicha::ESTADO_OBSERVADO);

        app(ImportPersonalService::class)->import($this->contactUpload([
            [$dni, 'Observado Contacto', 'Operario', '999888777', 'observado@test.local'],
        ]));

        $this->assertDatabaseHas('personal', [
            'dni' => $dni,
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'correo' => 'observado@test.local',
        ]);
    }

    public function test_personnel_data_import_creates_worker_with_approved_ficha_and_contract_dates(): void
    {
        $user = $this->createUser();

        $result = app(ImportPersonalService::class)->import($this->personnelDataUpload([
            [
                'JUAN CARLOS',
                'IMPORTADO',
                'PRUEBA',
                'MASCULINO',
                'SOLTERO',
                'PERUANO',
                'O+',
                '-',
                'DNI',
                '71000110',
                '1990-01-15',
                'PERU',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                '959111222',
                'juan.importado@test.local',
                'AV. TEST 123',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                'OPERARIO IMPORTADO',
                'SE',
                'BCP',
                '00123456789',
                '00212345678901234567',
                'SECUNDARIA COMPLETA',
                'OPERARIO',
                '-',
                'IE TEST',
                '2010',
                '5 ANOS',
                'SISTEMA PRIVADO DE PENSIONES',
                'AFP PRIMA',
                '2500',
                '42',
                'L',
                '34',
                'M',
                'CONTACTO TEST',
                'HERMANO',
                '959333444',
                '2026-07-01',
                '2026-07-01',
                '2026-09-29',
                '2026-12-31',
            ],
        ]), $user);

        $this->assertSame('datos_personal', $result['tipoImportacion']);
        $this->assertSame(1, $result['nuevos']);

        $personal = DB::table('personal')->where('numero_documento', '71000110')->first();
        $this->assertNotNull($personal);
        $this->assertSame('FALTA_CONTRATO', $personal->estado);
        $this->assertSame('FIJO', $personal->contrato);
        $this->assertSame('OPERARIO IMPORTADO', $personal->puesto);

        $this->assertDatabaseHas('personal_fichas', [
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => '71000110',
        ]);

        $fichaData = json_decode((string) DB::table('personal_fichas')->where('personal_id', $personal->id)->value('datos_json'), true);
        $this->assertSame('JUAN CARLOS', $fichaData['nombres']);
        $this->assertSame('OPERARIO IMPORTADO', $fichaData['puesto']);
        $this->assertSame('00123456789', $fichaData['numero_cuenta']);
        $this->assertSame('00212345678901234567', $fichaData['cci']);
        $this->assertSame('2026-09-29', $fichaData['periodo_prueba_fin']);

        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => '2026-07-01',
            'fecha_fin_contrato' => '2026-12-31',
            'periodo_prueba_fin' => '2026-09-29',
            'sueldo_num' => '2500',
        ]);

        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'PREPARACION',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
        ]);
    }

    public function test_imported_preparation_contract_dates_appear_in_contract_expirations(): void
    {
        $user = $this->createUser();

        app(ImportPersonalService::class)->import($this->personnelDataUpload([
            [
                'JORGE',
                'VENCIMIENTO',
                'IMPORTADO',
                'MASCULINO',
                'SOLTERO',
                'PERUANO',
                'O+',
                '-',
                'DNI',
                '71000112',
                '1991-03-15',
                'PERU',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                '959111555',
                'jorge.vencimiento@test.local',
                'AV. TEST 456',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                'MECANICO IMPORTADO',
                'SE',
                'BCP',
                '00123456780',
                '00212345678901234560',
                'SECUNDARIA COMPLETA',
                'MECANICO',
                '-',
                'IE TEST',
                '2011',
                '5 ANOS',
                'SISTEMA PRIVADO DE PENSIONES',
                'AFP PRIMA',
                '2500',
                '42',
                'L',
                '34',
                'M',
                'CONTACTO TEST',
                'HERMANO',
                '959333555',
                '2026-07-01',
                '2026-07-01',
                '2026-09-29',
                '2026-12-31',
            ],
        ]), $user);

        $contracts = app(PersonalContratoService::class)->listExpiringContracts([
            'mes' => 12,
            'anio' => 2026,
            'cargo' => 'MECANICO IMPORTADO',
        ]);

        $this->assertCount(1, $contracts);
        $this->assertSame('PREPARACION', strtoupper((string) $contracts->first()->estado));
        $this->assertSame('VENCIMIENTO IMPORTADO JORGE', $contracts->first()->personal->nombre_completo);
        $this->assertFalse((bool) $contracts->first()->getAttribute('can_register_decision'));
    }

    public function test_personnel_data_import_updates_existing_worker_and_clears_dash_contract_end(): void
    {
        $personalId = $this->createPersonal('71000111', PersonalFicha::ESTADO_PENDIENTE);
        $this->createFicha($personalId, PersonalFicha::ESTADO_OBSERVADO);

        app(ImportPersonalService::class)->import($this->personnelDataUpload([
            [
                'MARIA',
                'ACTUALIZADA',
                'EXISTENTE',
                'FEMENINO',
                'CASADA',
                'PERUANO',
                'A+',
                '-',
                'DNI',
                '71000111',
                '1992-02-20',
                'PERU',
                'LIMA',
                'LIMA',
                'LIMA',
                '-',
                '-',
                'JR. NUEVO 456',
                'LIMA',
                'LIMA',
                'LIMA',
                'ASISTENTE ACTUALIZADA',
                'INDET',
                'INTERBANK',
                '999888777',
                '-',
                'UNIVERSITARIA TITULADO',
                'ADMINISTRACION',
                'TITULADO',
                'UNIVERSIDAD TEST',
                '2015',
                '8 ANOS',
                'SISTEMA NACIONAL DE PENSIONES',
                '-',
                '3200',
                '-',
                'M',
                '-',
                '-',
                '-',
                '-',
                '-',
                '2026-01-10',
                '2026-01-10',
                '2026-04-10',
                '-',
            ],
        ]));

        $this->assertDatabaseHas('personal', [
            'id' => $personalId,
            'nombre_completo' => 'ACTUALIZADA EXISTENTE MARIA',
            'puesto' => 'ASISTENTE ACTUALIZADA',
            'contrato' => 'INDET',
            'estado' => 'FALTA_CONTRATO',
            'telefono' => null,
            'correo' => null,
        ]);

        $fichaData = json_decode((string) DB::table('personal_fichas')->where('personal_id', $personalId)->value('datos_json'), true);
        $this->assertSame('APROBADO', DB::table('personal_fichas')->where('personal_id', $personalId)->value('estado'));
        $this->assertSame('', $fichaData['cci']);
        $this->assertSame('ONP', $fichaData['sistema_pensionario']);
        $this->assertSame('2026-04-10', $fichaData['periodo_prueba_fin']);

        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personalId,
            'fecha_inicio_contrato' => '2026-01-10',
            'fecha_fin_contrato' => null,
            'periodo_prueba_fin' => '2026-04-10',
        ]);
    }

    public function test_imported_indefinite_worker_without_contract_end_does_not_require_finishing_ficha(): void
    {
        $user = $this->createUser();

        app(ImportPersonalService::class)->import($this->personnelDataUpload([
            [
                'LUIS',
                'INDETERMINADO',
                'SIN FIN',
                'MASCULINO',
                'SOLTERO',
                'PERUANO',
                'O+',
                '-',
                'DNI',
                '71000113',
                '1990-01-15',
                'PERU',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                '959111777',
                '-',
                'AV. SIN FIN 123',
                'AREQUIPA',
                'AREQUIPA',
                'AREQUIPA',
                'OPERARIO INDETERMINADO',
                'INDET',
                'BCP',
                '00123456789',
                '00212345678901234567',
                'SECUNDARIA COMPLETA',
                'OPERARIO',
                '-',
                'IE TEST',
                '2010',
                '5 ANOS',
                'SISTEMA PRIVADO DE PENSIONES',
                'AFP PRIMA',
                '2500',
                '42',
                'L',
                '34',
                'M',
                'CONTACTO TEST',
                'HERMANO',
                '959333777',
                '2026-07-01',
                '2026-07-01',
                '2026-09-29',
                '-',
            ],
        ]), $user);

        $personal = Personal::query()
            ->with(['fichaColaborador', 'contratoDatos', 'contratosLaborales', 'minas'])
            ->where('numero_documento', '71000113')
            ->firstOrFail();

        $row = (new PersonalIndexResource($personal))->toArray(request());

        $this->assertSame('INDET', $personal->contrato);
        $this->assertSame(PersonalFicha::ESTADO_APROBADO, $row['estado_ficha']);
        $this->assertNull($personal->contratoDatos?->fecha_fin_contrato);
        $this->assertNotSame('terminar_ficha', $row['situacion']);
    }

    private function createPersonal(string $dni, string $estado): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $dni,
            'tipo_documento' => 'DNI',
            'numero_documento' => $dni,
            'nombre_completo' => 'TRABAJADOR ' . $dni,
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . $dni,
            'fecha_ingreso' => '2026-06-01',
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createFicha(string $personalId, string $estado): void
    {
        DB::table('personal_fichas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'estado' => $estado,
            'tipo_documento' => 'DNI',
            'numero_documento' => DB::table('personal')->where('id', $personalId)->value('dni'),
            'datos_json' => json_encode(['puesto' => 'Operario', 'contrato' => 'FIJO']),
            'datos_detectados_json' => json_encode(['puesto' => 'Operario', 'contrato' => 'FIJO']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createActiveContract(string $personalId, string $activatedAt): void
    {
        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
            'activado_at' => $activatedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSignedContractData(string $personalId, string $signedAt): void
    {
        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'signed_at' => $signedAt,
            'signed_contract_path' => 'personal_contratos/' . $personalId . '/contrato_firmado.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function masterUpload(array $rows): UploadedFile
    {
        return $this->xlsxUpload(
            ['CONTRATO', 'OCUPACION', 'DNI', 'APELLIDOS Y NOMBRES', 'CARGO', 'FECHA INGRESO'],
            array_map(fn (array $row): array => ['FIJO', 'Operario', $row[0], $row[1], $row[2], $row[3]], $rows),
            'master.xlsx',
        );
    }

    private function contactUpload(array $rows): UploadedFile
    {
        return $this->xlsxUpload(
            ['DNI', 'NOMBRES', 'CARGO', 'CELULAR', 'CORREO'],
            $rows,
            'contactos.xlsx',
        );
    }

    private function personnelDataUpload(array $rows): UploadedFile
    {
        return $this->xlsxUpload(
            [
                'Nombres',
                'Apellido paterno',
                'Apellido materno',
                'SEXO',
                'ESTADO CIVIL',
                'NACIONALIDAD',
                'Grupo sanguineo',
                'Brevete / licencia de conducir',
                'TIPO DE DOCUMENTO ',
                'NUMERO DE DOCUMENTO',
                'FECHA DE NACIMIENTO',
                'PAIS DE NACIMIENTO',
                'DEPARTAMENTO DE NACIMIENTO',
                'PROVINCIA DE NACIMIENTO',
                'DISTRITO DE NACIMIENTO',
                'CELULAR PARTICULAR',
                'CORREO ELECTRONICO',
                'DIRECCION',
                'DEPARTAMENTO',
                'PROVINCIA',
                'DISTRITO',
                'CARGO / PUESTO',
                'CONTRATO',
                'BANCO',
                'CUENTA SUELDO',
                'CCI CUENTA SUELDO',
                'GRADO DE INSTRUCCION',
                'PROFESION Y/O CARRERA',
                'TITULADO',
                'CENTRO DE ESTUDIOS',
                'Año de egreso',
                'AÑOS DE EXPERIENCIA',
                'SISTEMA DE PENSION',
                'Elección del sistema pensionario',
                'REMUNERACION',
                'ZAPATOS',
                'CAMISA',
                'PANTALON',
                'RESPIRADOR',
                'EN CASO DE EMERGENCIA ',
                'PARENTEZCO',
                'CELULAR',
                'FECHA INGRESO',
                'Fecha de contrato',
                'Fecha de término del periodo de prueba',
                'FECHA FIN',
            ],
            $rows,
            'datos_personal.xlsx',
        );
    }

    private function createUser(): \App\Models\Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'IMPORT_PERSONAL_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(['personal' => ['importar', 'crear', 'actualizar']]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => 'import-personal-' . Str::lower(Str::random(6)) . '@test.local',
            'password' => bcrypt('password'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\Usuario::query()->findOrFail($userId);
    }

    private function xlsxUpload(array $headers, array $rows, string $name): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'personal_import_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
