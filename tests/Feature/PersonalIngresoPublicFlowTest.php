<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalIngreso;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionCatalog;
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
            'banco' => 'BCP',
            'numero_cuenta' => '1234567890123',
            'cci' => '00212345678901234567',
            'grado_instruccion' => 'Bachiller',
            'profesion_oficio' => 'ADMINISTRACION',
            'especialidad' => '-',
            'anio_experiencia' => '5',
            'anio_egreso' => '-',
            'carrera' => '-',
            'institucion' => 'UNIVERSIDAD TECNOLOGICA DEL PERU',
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
        $this->assertSame('00212345678901234567', $ingreso->datos_json['cci']);
        $this->assertSame('Bachiller', $ingreso->datos_json['grado_instruccion']);
        $this->assertSame('ADMINISTRACION', $ingreso->datos_json['profesion_oficio']);
        $this->assertSame('5', $ingreso->datos_json['anio_experiencia']);
        $this->assertSame('UNIVERSIDAD TECNOLOGICA DEL PERU', $ingreso->datos_json['institucion']);

        $personal = $service->accept($ingreso, $this->user(), [
            'fecha_inicio_contrato' => '2026-07-01',
            'fecha_fin_contrato' => '2026-12-31',
        ]);

        $this->assertSame('APROBADO', $personal->estado);
        $this->assertTrue((bool) $personal->pendiente_contrato_firmado);
        $this->assertSame('2026-07-01', optional($personal->fresh()->fecha_ingreso)->toDateString());
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'PREPARACION',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
            'archivo_pendiente_regularizacion' => 1,
        ]);
        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => '2026-07-01',
            'fecha_fin_contrato' => '2026-12-31',
        ]);
        $acceptedFichaData = $personal->fresh('fichaColaborador')->fichaColaborador->datos_json;
        $this->assertSame('00212345678901234567', $acceptedFichaData['cci']);
        $this->assertSame('Bachiller', $acceptedFichaData['grado_instruccion']);
        $this->assertSame('ADMINISTRACION', $acceptedFichaData['profesion_oficio']);
        $this->assertSame('5', $acceptedFichaData['anio_experiencia']);
        $this->assertSame('UNIVERSIDAD TECNOLOGICA DEL PERU', $acceptedFichaData['institucion']);
        $this->assertDatabaseHas('personal_ingresos', [
            'id' => $ingreso->id,
            'estado' => PersonalIngreso::ESTADO_ACEPTADA,
            'personal_creado_id' => $personal->id,
        ]);
    }

    public function test_accept_ingreso_can_attach_signed_contract_pdf_from_contract_dates_modal(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);
        $ingreso = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010007']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-contrato.jpg', 120, 120),
            [],
        );

        $personal = $service->accept($ingreso, $this->user(), [
            'fecha_inicio_contrato' => '2026-09-01',
            'fecha_fin_contrato' => '2026-12-31',
            'contrato_pdf' => UploadedFile::fake()->create('contrato-firmado.pdf', 120, 'application/pdf'),
        ])->fresh(['contratosLaborales', 'contratoDatos']);

        $contract = $personal->contratosLaborales->first();

        $this->assertNotNull($contract);
        $this->assertFalse((bool) $personal->pendiente_contrato_firmado);
        $this->assertSame('ACTIVO', $contract->estado);
        $this->assertTrue($contract->hasSignedFile());
        $this->assertSame('contrato-firmado.pdf', $contract->signed_contract_original_name);
        Storage::disk('local')->assertExists($contract->signed_contract_path);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => 'ACTIVO',
            'archivo_pendiente_regularizacion' => 0,
        ]);
    }

    public function test_academic_aliases_are_normalized_to_official_ficha_fields(): void
    {
        $fields = $this->fichaData([
            'grado_instruccion' => '',
            'profesion_oficio' => '',
            'anio_experiencia' => '',
            'anio_egreso' => '',
            'institucion' => '',
            'grado_de_instruccion' => 'Bachiller',
            'profesion_u_oficio' => 'ADMINISTRACION',
            'anos_experiencia' => '5',
            'ano_egreso' => '2020',
            'institucion_educativa' => 'UNIVERSIDAD TECNOLOGICA DEL PERU',
        ]);

        $data = app(PersonalFichaService::class)->normalizeFichaData($fields);

        $this->assertSame('Bachiller', $data['grado_instruccion']);
        $this->assertSame('ADMINISTRACION', $data['profesion_oficio']);
        $this->assertSame('5', $data['anio_experiencia']);
        $this->assertSame('2020', $data['anio_egreso']);
        $this->assertSame('UNIVERSIDAD TECNOLOGICA DEL PERU', $data['institucion']);
    }

    public function test_public_ingreso_submission_uuid_is_idempotent_for_mobile_retries(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);
        $submissionUuid = (string) Str::uuid();

        $first = $service->storeSubmission(
            $this->fichaData([
                'numero_documento' => '70010008',
                'telefono' => '900000001',
            ]),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-uno.jpg', 120, 120),
            [],
            $submissionUuid,
        );

        $second = $service->storeSubmission(
            $this->fichaData([
                'numero_documento' => '70010008',
                'telefono' => '900000002',
            ]),
            [],
            'data:image/png;base64,' . base64_encode('firma-dos'),
            UploadedFile::fake()->image('huella-dos.jpg', 120, 120),
            [],
            $submissionUuid,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PersonalIngreso::query()->where('submission_uuid', $submissionUuid)->count());
        $this->assertSame('900000002', $second->fresh()->datos_json['telefono']);
        $this->assertDatabaseHas('personal_ingresos', [
            'id' => $first->id,
            'submission_uuid' => $submissionUuid,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
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

        $personal = $service->accept($ingreso, $this->user(), [
            'fecha_inicio_contrato' => '2026-08-01',
            'fecha_fin_contrato' => '2026-11-30',
        ]);

        $this->assertSame($existing->id, $personal->id);
        $this->assertSame(1, Personal::query()->where('numero_documento', '70010001')->count());
        $this->assertSame('EXISTENTE DNI ACTUALIZADO', $personal->fresh()->nombre_completo);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $existing->id,
            'estado' => 'PREPARACION',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-11-30',
        ]);
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

    public function test_edit_after_contract_not_signed_does_not_show_ingreso_regularization_warning(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);
        $user = $this->user(['personal' => ['ver', 'editar']]);
        $ingreso = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010006']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-no-firmo.jpg', 120, 120),
            [],
        );

        $personal = $service->markContractNotSigned($ingreso, $user);

        $this->assertSame(PersonalIngresoService::ESTADO_NO_FIRMO_CONTRATO, $personal->estado);

        $this->withSession($this->sessionFor($user))
            ->get(route('personal.edit', $personal->id))
            ->assertOk()
            ->assertDontSee('Usa la vista de Ingresos para copiar el link publico');
    }

    public function test_accepted_ingresos_only_remain_visible_on_processing_day(): void
    {
        Storage::fake('local');

        $service = app(PersonalIngresoService::class);

        $acceptedToday = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010003']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-hoy.jpg', 120, 120),
            [],
        );
        $acceptedToday->forceFill([
            'estado' => PersonalIngreso::ESTADO_ACEPTADA,
            'reviewed_at' => now(),
        ])->save();

        $acceptedYesterday = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010004']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-ayer.jpg', 120, 120),
            [],
        );
        $acceptedYesterday->forceFill([
            'estado' => PersonalIngreso::ESTADO_ACEPTADA,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $pendingOld = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010005']),
            [],
            'data:image/png;base64,' . base64_encode('firma'),
            UploadedFile::fake()->image('huella-pendiente.jpg', 120, 120),
            [],
        );
        $pendingOld->forceFill([
            'submitted_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->save();

        $items = $service->list();

        $this->assertTrue($items->contains(fn (PersonalIngreso $item): bool => $item->id === $acceptedToday->id));
        $this->assertFalse($items->contains(fn (PersonalIngreso $item): bool => $item->id === $acceptedYesterday->id));
        $this->assertTrue($items->contains(fn (PersonalIngreso $item): bool => $item->id === $pendingOld->id));

        $acceptedItems = $service->list(['estado' => PersonalIngreso::ESTADO_ACEPTADA]);

        $this->assertTrue($acceptedItems->contains(fn (PersonalIngreso $item): bool => $item->id === $acceptedToday->id));
        $this->assertFalse($acceptedItems->contains(fn (PersonalIngreso $item): bool => $item->id === $acceptedYesterday->id));
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

    private function user(array $permissions = []): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_INGRESOS_' . Str::upper(Str::random(6)),
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

        return Usuario::query()->findOrFail($userId);
    }

    private function sessionFor(Usuario $user): array
    {
        $plain = 'test-token-' . Str::random(8);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'auth_token' => $plain,
            'user_id' => $user->id,
            'user' => [
                'id' => $user->id,
                'permissions' => DB::table('roles')->where('id', $user->rol_id)->value('permisos'),
            ],
        ];
    }
}
