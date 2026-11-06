<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoArchivo;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalIngresoPurpose;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalIngresoPublicFlowTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('optionalForeignBirthplaceValues')]
    public function test_public_ingreso_accepts_optional_foreign_birthplace(?string $value, bool $omit = false): void
    {
        Storage::fake('local');
        PersonalPuesto::query()->firstOrCreate(
            ['nombre' => 'OPERARIO NACIMIENTO OPCIONAL'],
            ['id' => (string) Str::uuid(), 'activo' => true],
        );
        $data = $this->fichaData([
            'numero_documento' => '70010092',
            'puesto' => 'OPERARIO NACIMIENTO OPCIONAL',
            'pais_nacimiento' => 'Otro',
            'pais_nacimiento_otro' => 'Chile',
            'lugar_nacimiento_extranjero' => $value,
            'domicilio_direccion' => 'Direccion de prueba',
            'banco' => 'BCP',
            'numero_cuenta' => '1234567890123',
            'grado_instruccion' => 'Secundaria completa',
        ]);
        if ($omit) {
            unset($data['lugar_nacimiento_extranjero']);
        }

        $this->withSession(['personal_ingreso_public_key_date' => now()->toDateString()])
            ->post(route('personal.ingresos.public.submit'), [
                'fields' => $data,
                'firma_base64' => 'data:image/png;base64,' . base64_encode('firma'),
                'huella' => UploadedFile::fake()->image('huella.jpg', 120, 120),
                'declaraciones' => collect(PersonalFichaCatalog::declarationCheckboxes())
                    ->mapWithKeys(fn ($_label, string $key): array => [$key => '1'])->all(),
            ])->assertRedirect(route('personal.ingresos.public.show'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $ingreso = PersonalIngreso::query()->where('numero_documento', $data['numero_documento'])->firstOrFail();
        $this->assertSame($value ?? '', $ingreso->datos_json['lugar_nacimiento_extranjero']);
        $this->assertSame('Chile', $ingreso->datos_json['pais_nacimiento_otro']);
    }

    public static function optionalForeignBirthplaceValues(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'omitted' => ['', true],
            'provided' => ['Ciudad / Region'],
        ];
    }

    public function test_optional_foreign_birthplace_still_rejects_oversized_text(): void
    {
        $this->withSession(['personal_ingreso_public_key_date' => now()->toDateString()])
            ->post(route('personal.ingresos.public.submit'), [
                'fields' => ['lugar_nacimiento_extranjero' => str_repeat('a', 192)],
            ])->assertSessionHasErrors('fields.lugar_nacimiento_extranjero');
    }

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

    public function test_public_ingreso_rejects_reusing_an_update_submission_uuid(): void
    {
        Storage::fake('local');
        $worker = $this->createPersonal('70010081');
        $uuid = (string) Str::uuid();
        $update = PersonalIngreso::query()->create([
            'id' => (string) Str::uuid(),
            'submission_uuid' => $uuid,
            'proposito' => PersonalIngresoPurpose::ACTUALIZACION,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $worker->dni,
            'personal_existente_id' => $worker->id,
        ]);
        $before = $update->fresh()->getRawOriginal();
        try {
            app(PersonalIngresoService::class)->storeSubmission(
                $this->fichaData(['numero_documento' => '70010082']),
                [],
                'data:image/png;base64,reintento',
                UploadedFile::fake()->image('huella-intrusion.jpg', 120, 120),
                ['cv_documentado' => UploadedFile::fake()->createWithContent('intrusion.pdf', 'Documento intrusión')],
                $uuid,
            );
            $this->fail('Una actualización no puede recibir el envío de un alta.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame($before, $update->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('personal_ingreso_archivos', ['personal_ingreso_id' => $update->id]);
        $this->assertDatabaseMissing('personal_archivo_versiones', ['owner_id' => $update->id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('processedIngresoStates')]
    public function test_public_ingreso_retry_preserves_processed_data_and_all_file_bytes(string $state): void
    {
        Storage::fake('local');
        $uuid = (string) Str::uuid();
        $service = app(PersonalIngresoService::class);
        $first = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010083']),
            [],
            'data:image/png;base64,original',
            UploadedFile::fake()->image('huella-original.jpg', 120, 120),
            ['cv_documentado' => UploadedFile::fake()->createWithContent('original.pdf', 'Documento original')],
            $uuid,
        );
        $first->forceFill(['estado' => $state, 'reviewed_at' => now()])->save();
        $before = $first->getRawOriginal();
        $files = $first->archivos()->orderBy('id')->get()->toArray();
        $bytes = collect(Storage::disk('local')->allFiles())->mapWithKeys(
            fn (string $path): array => [$path => Storage::disk('local')->get($path)],
        )->all();

        $retry = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010084', 'correo' => 'reintento@test.local']),
            [['nombres_apellidos' => 'Familiar reemplazo']],
            'data:image/png;base64,reintento',
            UploadedFile::fake()->image('huella-reintento.jpg', 120, 120),
            ['cv_documentado' => UploadedFile::fake()->createWithContent('reintento.pdf', 'Documento reemplazo')],
            $uuid,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($before, $retry->getRawOriginal());
        $this->assertSame($files, $retry->archivos()->orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('personal_archivo_versiones', ['owner_id' => $first->id]);
        $this->assertSame(array_keys($bytes), Storage::disk('local')->allFiles());
        foreach ($bytes as $path => $content) {
            $this->assertSame($content, Storage::disk('local')->get($path));
        }
    }

    public static function processedIngresoStates(): array
    {
        return [[PersonalIngreso::ESTADO_ACEPTADA], [PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO]];
    }

    public function test_public_retry_rechecks_processing_before_replacing_files(): void
    {
        Storage::fake('local');
        $uuid = (string) Str::uuid();
        $service = app(PersonalIngresoService::class);
        $first = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010085']),
            [],
            'data:image/png;base64,original',
            UploadedFile::fake()->image('huella-original.jpg', 120, 120),
            [],
            $uuid,
        );
        $path = $first->huella_path;
        $retrieved = 0;
        PersonalIngreso::retrieved(function (PersonalIngreso $ingreso) use ($first, &$retrieved): void {
            if ($ingreso->id === $first->id && ++$retrieved === 2) {
                DB::table('personal_ingresos')->where('id', $first->id)->update(['estado' => PersonalIngreso::ESTADO_ACEPTADA]);
            }
        });
        try {
            $retry = $service->storeSubmission(
                $this->fichaData(['numero_documento' => '70010085']),
                [],
                'data:image/png;base64,original',
                UploadedFile::fake()->image('huella-reintento.jpg', 120, 120),
                ['cv_documentado' => UploadedFile::fake()->createWithContent('reintento.pdf', 'Documento tardío')],
                $uuid,
            );
        } finally {
            PersonalIngreso::flushEventListeners();
        }

        $this->assertSame(PersonalIngreso::ESTADO_ACEPTADA, $retry->estado);
        $this->assertSame($path, $retry->huella_path);
        $this->assertSame([$path], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('personal_archivo_versiones', ['owner_id' => $first->id]);
    }

    public function test_required_fingerprint_failure_rolls_back_the_entire_new_submission(): void
    {
        Storage::fake('local');
        $uuid = (string) Str::uuid();
        PersonalIngresoArchivo::creating(fn () => throw new \RuntimeException('Fallo sintético de referencia'));
        try {
            app(PersonalIngresoService::class)->storeSubmission(
                $this->fichaData(['numero_documento' => '70010086']),
                [],
                'data:image/png;base64,original',
                UploadedFile::fake()->image('huella.jpg', 120, 120),
                [],
                $uuid,
            );
            $this->fail('El envío debe reportar el fallo de huella.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('huella', $exception->errors());
        } finally {
            PersonalIngresoArchivo::flushEventListeners();
        }

        $this->assertDatabaseMissing('personal_ingresos', ['submission_uuid' => $uuid]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_required_fingerprint_failure_preserves_the_previous_retry_data(): void
    {
        Storage::fake('local');
        $uuid = (string) Str::uuid();
        $service = app(PersonalIngresoService::class);
        $first = $service->storeSubmission(
            $this->fichaData(['numero_documento' => '70010087']),
            [],
            'data:image/png;base64,original',
            UploadedFile::fake()->image('huella-original.jpg', 120, 120),
            [],
            $uuid,
        );
        $before = $first->fresh()->getRawOriginal();
        PersonalIngresoArchivo::creating(fn () => throw new \RuntimeException('Fallo sintético de referencia'));
        try {
            $service->storeSubmission(
                $this->fichaData(['numero_documento' => '70010087', 'correo' => 'reintento@test.local']),
                [],
                'data:image/png;base64,reintento',
                UploadedFile::fake()->image('huella-reintento.jpg', 120, 120),
                [],
                $uuid,
            );
            $this->fail('El envío debe reportar el fallo de huella.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('huella', $exception->errors());
        } finally {
            PersonalIngresoArchivo::flushEventListeners();
        }

        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->assertSame([$first->huella_path], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('personal_archivo_versiones', ['owner_id' => $first->id]);
    }

    public function test_submission_keeps_the_payload_transaction_open_while_storing_fingerprint(): void
    {
        Storage::fake('local');
        $initialLevel = DB::transactionLevel();
        $storageLevel = null;
        $file = UploadedFile::fake()->image('huella.jpg', 120, 120);
        $upload = \Mockery::mock($file)->makePartial();
        $upload->shouldReceive('storeAs')->once()->andReturnUsing(
            function (string $directory, string $filename, string $disk) use ($file, &$storageLevel): string {
                $storageLevel = DB::transactionLevel();

                return $file->storeAs($directory, $filename, $disk);
            },
        );

        app(PersonalIngresoService::class)->storeSubmission(
            $this->fichaData(['numero_documento' => '70010088']),
            [],
            'data:image/png;base64,original',
            $upload,
        );
        // The delivery transaction must still enclose the file replacement transaction.
        $this->assertSame($initialLevel + 2, $storageLevel);
    }

    public function test_optional_document_failure_keeps_submission_and_reports_warning(): void
    {
        Storage::fake('local');
        PersonalIngresoArchivo::creating(function (PersonalIngresoArchivo $archivo): void {
            if ($archivo->tipo === 'cv_documentado') {
                throw new \RuntimeException('Fallo sintético de documento opcional');
            }
        });
        try {
            $ingreso = app(PersonalIngresoService::class)->storeSubmission(
                $this->fichaData(['numero_documento' => '70010089']),
                [],
                'data:image/png;base64,original',
                UploadedFile::fake()->image('huella.jpg', 120, 120),
                ['cv_documentado' => UploadedFile::fake()->createWithContent('cv.pdf', 'Documento opcional')],
            );
        } finally {
            PersonalIngresoArchivo::flushEventListeners();
        }

        $this->assertSame(PersonalIngreso::ESTADO_RECIBIDA, $ingreso->estado);
        $this->assertStringContainsString('cv_documentado', $ingreso->observaciones_revision);
        $this->assertSame('huella', $ingreso->archivos->sole()->tipo);
        $this->assertSame([$ingreso->huella_path], Storage::disk('local')->allFiles());
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
