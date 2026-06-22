<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalIngreso;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalIngresoPublicFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_ingreso_lands_in_inbox_before_personal_and_accepts_without_falta_contrato(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);
        $data = $this->fichaData([
            'numero_documento' => '73187777',
            'nombres' => 'Daniel',
            'apellido_paterno' => 'Achahui',
            'apellido_materno' => 'Gomez',
            'puesto' => 'Mecanico',
        ]);

        $ingreso = $service->storeSubmission(
            $data,
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella.jpg', 120, 120),
            [],
        );

        $this->assertDatabaseHas('personal_ingresos', [
            'id' => $ingreso->id,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'numero_documento' => '73187777',
        ]);
        $this->assertDatabaseMissing('personal', ['numero_documento' => '73187777']);

        $personal = $service->accept($ingreso, $this->user());

        $this->assertSame('APROBADO', $personal->estado);
        $this->assertTrue((bool) $personal->pendiente_contrato_firmado);
        $this->assertDatabaseHas('personal_ingresos', [
            'id' => $ingreso->id,
            'estado' => PersonalIngreso::ESTADO_ACEPTADA,
            'personal_creado_id' => $personal->id,
        ]);
    }

    public function test_public_ingreso_with_existing_document_updates_existing_without_duplicate(): void
    {
        Storage::fake('local');

        $existing = $this->createPersonal('70010001');
        $service = app(PersonalIngresoService::class);
        $ingreso = $service->storeSubmission(
            $this->fichaData([
                'numero_documento' => '70010001',
                'nombres' => 'Actualizado',
                'apellido_paterno' => 'Existente',
                'apellido_materno' => 'Dni',
                'telefono' => '999888777',
                'correo' => 'actualizado@test.local',
            ]),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella.jpg', 120, 120),
            [],
        );

        $personal = $service->accept($ingreso, $this->user());

        $this->assertSame($existing->id, $personal->id);
        $this->assertSame(1, Personal::query()->where('numero_documento', '70010001')->count());
        $this->assertSame('EXISTENTE DNI ACTUALIZADO', $personal->fresh()->nombre_completo);
    }

    public function test_contract_not_signed_is_saved_as_distinct_personal_state(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);
        $ingreso = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010002']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella.jpg', 120, 120),
            [],
        );

        $personal = $service->markContractNotSigned($ingreso, $this->user());

        $this->assertSame('NO_FIRMO_CONTRATO', $personal->estado);
        $this->assertFalse((bool) $personal->pendiente_contrato_firmado);
        $this->assertDatabaseHas('personal_ingresos', [
            'id' => $ingreso->id,
            'estado' => PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO,
            'personal_creado_id' => $personal->id,
        ]);
    }

    private function fichaData(array $overrides = []): array
    {
        return [
            ...PersonalFichaCatalog::emptyData(),
            'tipo_documento' => 'DNI',
            'numero_documento' => '70010000',
            'nombres' => 'Juan',
            'apellido_paterno' => 'Perez',
            'apellido_materno' => 'Ramos',
            'sexo' => 'Masculino',
            'estado_civil' => 'Soltero',
            'nacionalidad' => 'Peruana',
            'fecha_nacimiento' => '1995-01-15',
            'telefono' => '900111222',
            'correo' => 'trabajador@test.local',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            ...$overrides,
        ];
    }

    private function createPersonal(string $document): Personal
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'EXISTENTE DNI ORIGINAL',
            'puesto' => 'Operario',
            'ocupacion' => null,
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => null,
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Personal::query()->findOrFail($id);
    }

    private function user(): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_INGRESOS_' . Str::upper(Str::random(6)),
            'permisos' => json_encode([]),
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

        return Usuario::query()->findOrFail($userId);
    }
}
