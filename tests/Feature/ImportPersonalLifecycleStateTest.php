<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Modules\Personal\Services\ImportPersonalService;
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
