<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalDocumentoEstado;
use App\Models\PersonalFicha;
use App\Modules\Personal\Services\PersonalDocumentoDownloadService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class PersonalDocumentoDownloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_individual_document_download_still_works(): void
    {
        Storage::fake('local');
        $userId = $this->createUser(['personal' => ['ver']]);
        $ficha = $this->createFicha();
        $archivoId = $this->attachDocument($ficha, 'cv_documentado', 'cv.pdf');

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.fichas.archivos.download', $archivoId))
            ->assertOk()
            ->assertDownload('cv.pdf');
    }

    public function test_one_worker_selected_documents_are_downloaded_inside_worker_folder(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha($this->fichaData([
            'nombres' => 'Juan',
            'apellido_paterno' => 'Perez',
            'apellido_materno' => 'Gomez',
            'numero_documento' => '12345678',
        ]));
        $personal = Personal::query()->findOrFail($ficha->personal_id);
        $this->attachDocument($ficha, 'cv_documentado', 'cv.pdf');
        $this->attachDocument($ficha, 'dni_vigente', 'dni.pdf');

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$personal->id],
            ['cv_documentado', 'dni_vigente'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertContains('PEREZ_GOMEZ_JUAN_12345678/CV_DOCUMENTADO.pdf', $entries);
        $this->assertContains('PEREZ_GOMEZ_JUAN_12345678/DNI.pdf', $entries);
        $this->assertSame(2, $zip['included']);

        File::delete($zip['path']);
    }

    public function test_single_document_zip_is_not_downloaded_as_loose_file(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);
        $this->attachDocument($ficha, 'dni_vigente', 'dni.pdf');

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$personal->id],
            ['dni_vigente'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('/', $entries[0]);
        $this->assertStringEndsWith('/DNI.pdf', $entries[0]);

        File::delete($zip['path']);
    }

    public function test_bulk_download_creates_one_folder_per_worker(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $fichaA = $this->createFicha($this->fichaData([
            'nombres' => 'Maria',
            'apellido_paterno' => 'Ramos',
            'apellido_materno' => 'Quispe',
            'numero_documento' => '87654321',
        ]));
        $fichaB = $this->createFicha($this->fichaData([
            'nombres' => 'Luis',
            'apellido_paterno' => 'Lopez',
            'apellido_materno' => 'Diaz',
            'numero_documento' => '45671230',
        ]));
        $this->attachDocument($fichaA, 'cv_documentado', 'cv-a.pdf');
        $this->attachDocument($fichaB, 'cv_documentado', 'cv-b.pdf');

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$fichaA->personal_id, $fichaB->personal_id],
            ['cv_documentado'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertContains('RAMOS_QUISPE_MARIA_87654321/CV_DOCUMENTADO.pdf', $entries);
        $this->assertContains('LOPEZ_DIAZ_LUIS_45671230/CV_DOCUMENTADO.pdf', $entries);

        File::delete($zip['path']);
    }

    public function test_missing_not_applicable_observed_pending_and_missing_physical_files_are_handled(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);

        $this->attachDocument($ficha, 'cv_documentado', 'cv.pdf');
        $this->attachDocument($ficha, 'dni_vigente', 'dni-observado.pdf');
        $this->attachDocument($ficha, 'matrimonio_union', 'matrimonio.pdf');
        $this->attachMissingDocumentRecord($ficha, 'recibo_servicio', 'recibo.pdf');
        $this->setDocumentState($ficha, 'dni_vigente', PersonalDocumentoEstado::ESTADO_OBSERVADO);
        $this->setDocumentState($ficha, 'matrimonio_union', PersonalDocumentoEstado::ESTADO_NO_APLICA);

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$personal->id],
            ['cv_documentado', 'dni_vigente', 'matrimonio_union', 'recibo_servicio', 'vida_ley_notarial'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertCount(2, $entries);
        $this->assertTrue(collect($entries)->contains(fn (string $entry): bool => str_ends_with($entry, '/CV_DOCUMENTADO.pdf')));
        $this->assertTrue(collect($entries)->contains(fn (string $entry): bool => str_ends_with($entry, '/DNI.pdf')));
        $this->assertFalse(collect($entries)->contains(fn (string $entry): bool => str_contains($entry, 'PARTIDA_MATRIMONIO')));
        $this->assertFalse(collect($entries)->contains(fn (string $entry): bool => str_contains($entry, 'RECIBO_LUZ_AGUA')));
        $this->assertNotEmpty($zip['skipped']);

        File::delete($zip['path']);
    }

    public function test_repeated_document_types_are_numbered(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);
        $this->attachDocument($ficha, 'dni_vigente', 'dni-frente.pdf');
        $this->attachDocument($ficha, 'dni_vigente', 'dni-reverso.pdf');

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$personal->id],
            ['dni_vigente'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertTrue(collect($entries)->contains(fn (string $entry): bool => str_ends_with($entry, '/DNI.pdf')));
        $this->assertTrue(collect($entries)->contains(fn (string $entry): bool => str_ends_with($entry, '/DNI_2.pdf')));

        File::delete($zip['path']);
    }

    public function test_route_permissions_and_validations_for_bulk_download(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha();
        $this->attachDocument($ficha, 'cv_documentado', 'cv.pdf');
        $deniedUser = $this->createUser(['personal' => []]);
        $allowedUser = $this->createUser(['personal' => ['ver']]);

        $this->withSession($this->sessionFor($deniedUser))
            ->post(route('personal.documentos.download-bulk'), [
                'personal_ids' => [$ficha->personal_id],
                'document_types' => ['cv_documentado'],
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowedUser))
            ->post(route('personal.documentos.download-bulk'), [
                'personal_ids' => [],
                'document_types' => ['cv_documentado'],
            ])
            ->assertSessionHasErrors('personal_ids');

        $this->withSession($this->sessionFor($allowedUser))
            ->post(route('personal.documentos.download-bulk'), [
                'personal_ids' => [$ficha->personal_id],
                'document_types' => [],
            ])
            ->assertSessionHasErrors('document_types');

        $response = $this->withSession($this->sessionFor($allowedUser))
            ->post(route('personal.documentos.download-bulk'), [
                'personal_ids' => [$ficha->personal_id],
                'document_types' => ['cv_documentado'],
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
    }

    public function test_normalizes_worker_folder_names_with_accents_spaces_and_symbols(): void
    {
        $this->requireZipArchive();
        Storage::fake('local');
        $ficha = $this->createFicha($this->fichaData([
            'nombres' => 'Jose Angel',
            'apellido_paterno' => 'Nunez',
            'apellido_materno' => 'Quispe-Llanos',
            'numero_documento' => '99887766',
        ]));
        $personal = Personal::query()->findOrFail($ficha->personal_id);
        $this->attachDocument($ficha, 'foto_carnet', 'foto trabajador.jpeg');

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
            [$personal->id],
            ['foto_carnet'],
        );

        $entries = $this->zipEntries($zip['path']);
        $this->assertSame(['NUNEZ_QUISPE_LLANOS_JOSE_ANGEL_99887766/FOTO.jpeg'], $entries);

        File::delete($zip['path']);
    }

    public function test_no_available_documents_returns_clear_validation_message(): void
    {
        Storage::fake('local');
        $ficha = $this->createFicha();

        try {
            app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds(
                [$ficha->personal_id],
                ['cv_documentado'],
            );
            $this->fail('La descarga debio fallar sin documentos disponibles.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No hay documentos disponibles para descargar con los filtros seleccionados.',
                collect($exception->errors())->flatten()->first(),
            );
        }
    }

    private function requireZipArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive no esta disponible.');
        }
    }

    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = $zip->getNameIndex($index);
        }

        $zip->close();
        sort($entries);

        return $entries;
    }

    private function attachDocument(PersonalFicha $ficha, string $tipo, string $filename): string
    {
        $path = 'personal_fichas/tests/' . Str::uuid() . '/' . $filename;
        Storage::disk('local')->put($path, 'contenido');

        return $this->insertArchivo($ficha, $tipo, $filename, $path);
    }

    private function attachMissingDocumentRecord(PersonalFicha $ficha, string $tipo, string $filename): string
    {
        $path = 'personal_fichas/tests/' . Str::uuid() . '/' . $filename;

        return $this->insertArchivo($ficha, $tipo, $filename, $path);
    }

    private function insertArchivo(PersonalFicha $ficha, string $tipo, string $filename, string $path): string
    {
        $id = (string) Str::uuid();
        DB::table('personal_ficha_archivos')->insert([
            'id' => $id,
            'personal_ficha_id' => $ficha->id,
            'tipo' => $tipo,
            'nombre_original' => $filename,
            'path' => $path,
            'mime' => str_ends_with($filename, '.pdf') ? 'application/pdf' : 'image/jpeg',
            'size' => 9,
            'uploaded_by_usuario_id' => null,
            'uploaded_by_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function setDocumentState(PersonalFicha $ficha, string $tipo, string $estado): void
    {
        DB::table('personal_documento_estados')->insert([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'tipo' => $tipo,
            'estado' => $estado,
            'observacion' => $estado === PersonalDocumentoEstado::ESTADO_OBSERVADO ? 'Observado en prueba.' : null,
            'vida_ley_entrega_fisica' => null,
            'vida_ley_entrega_observacion' => null,
            'updated_by_usuario_id' => null,
            'estado_updated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFicha(?array $data = null): PersonalFicha
    {
        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $data ??= $this->fichaData();

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => $data['numero_documento'],
            'tipo_documento' => 'DNI',
            'numero_documento' => $data['numero_documento'],
            'nombre_completo' => trim($data['apellido_paterno'] . ' ' . $data['apellido_materno'] . ' ' . $data['nombres']),
            'puesto' => $data['puesto'],
            'ocupacion' => $data['puesto'],
            'contrato' => $data['contrato'],
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => null,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $data['numero_documento'],
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PersonalFicha::query()->with(['personal', 'archivos', 'documentoEstados'])->findOrFail($fichaId);
    }

    private function fichaData(array $overrides = []): array
    {
        return [
            ...PersonalFichaCatalog::emptyData(),
            'nombres' => 'Documento',
            'apellido_paterno' => 'Prueba',
            'apellido_materno' => 'RRHH',
            'sexo' => 'Masculino',
            'estado_civil' => 'Soltero',
            'nacionalidad' => 'Peruana',
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(71000000, 78999999),
            'fecha_nacimiento' => '1990-01-15',
            'pais_nacimiento' => 'Peru',
            'departamento_nacimiento' => 'Arequipa',
            'provincia_nacimiento' => 'Arequipa',
            'distrito_nacimiento' => 'Cerro Colorado',
            'telefono' => '900000001',
            'correo' => 'documentos@test.local',
            'domicilio_tipo' => 'Peru',
            'domicilio_departamento' => 'Arequipa',
            'domicilio_provincia' => 'Arequipa',
            'domicilio_distrito' => 'Cerro Colorado',
            'domicilio_direccion' => 'Av. documentos 100',
            'puesto' => 'Operario',
            'contrato' => 'INDET',
            'banco' => 'BCP',
            'numero_cuenta' => '1234567890123',
            'grado_instruccion' => 'Secundaria completa',
            ...$overrides,
        ];
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_DOWNLOAD_' . Str::upper(Str::random(6)),
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

        return $userId;
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'docs@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
