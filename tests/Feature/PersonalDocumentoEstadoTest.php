<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalDocumentoEstado;
use App\Models\PersonalFicha;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalDocumentoEstadoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_worker_without_documents_has_applicable_required_documents_pending(): void
    {
        $ficha = $this->createFicha($this->fichaData([
            'estado_civil' => 'Soltero',
        ]));

        $matrix = collect(app(PersonalFichaService::class)->documentMatrix($ficha));

        $this->assertSame(PersonalDocumentoEstado::ESTADO_PENDIENTE, $matrix->firstWhere('key', 'cv_documentado')['estado']);
        $this->assertSame(PersonalDocumentoEstado::ESTADO_PENDIENTE, $matrix->firstWhere('key', 'vida_ley_notarial')['estado']);
        $this->assertSame(PersonalDocumentoEstado::ESTADO_NO_APLICA, $matrix->firstWhere('key', 'matrimonio_union')['estado']);
        $this->assertSame(PersonalDocumentoEstado::ESTADO_NO_APLICA, $matrix->firstWhere('key', 'dni_hijos_menores')['estado']);
        $this->assertContains('cv_documentado', app(PersonalFichaService::class)->missingRequiredDocumentKeys($ficha));
    }

    public function test_document_can_be_loaded_observed_corrected_and_approved(): void
    {
        Storage::fake('local');

        $service = app(PersonalFichaService::class);
        $user = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar', 'aprobar', 'ver']]));
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);

        $service->updateDocuments($personal, [
            'cv_documentado' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        ], $user);

        $this->assertDatabaseHas('personal_documento_estados', [
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'cv_documentado',
            'estado' => PersonalDocumentoEstado::ESTADO_CARGADO,
        ]);

        $service->updateDocumentState($ficha->fresh(['archivos', 'familiares']), 'cv_documentado', [
            'estado' => PersonalDocumentoEstado::ESTADO_OBSERVADO,
            'observacion' => 'Documento borroso.',
        ], $user);

        $this->assertDatabaseHas('personal_documento_estados', [
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'cv_documentado',
            'estado' => PersonalDocumentoEstado::ESTADO_OBSERVADO,
            'observacion' => 'Documento borroso.',
        ]);

        $service->updateDocuments($personal->fresh(), [
            'cv_documentado' => UploadedFile::fake()->create('cv-corregido.pdf', 22, 'application/pdf'),
        ], $user);

        $this->assertDatabaseHas('personal_documento_estados', [
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'cv_documentado',
            'estado' => PersonalDocumentoEstado::ESTADO_CARGADO,
        ]);

        $service->updateDocumentState($ficha->fresh(['archivos', 'familiares']), 'cv_documentado', [
            'estado' => PersonalDocumentoEstado::ESTADO_APROBADO,
        ], $user);

        $this->assertDatabaseHas('personal_documento_estados', [
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'cv_documentado',
            'estado' => PersonalDocumentoEstado::ESTADO_APROBADO,
        ]);
    }

    public function test_conditional_document_rules_require_marriage_minor_children_and_studying_adult_children(): void
    {
        $ficha = $this->createFicha($this->fichaData([
            'estado_civil' => 'Casado',
        ]), [
            [
                'nombres_apellidos' => 'Hijo Menor',
                'parentesco' => 'Hijo',
                'fecha_nacimiento' => now()->subYears(8)->toDateString(),
            ],
            [
                'nombres_apellidos' => 'Hija Mayor',
                'parentesco' => 'Hija',
                'fecha_nacimiento' => now()->subYears(20)->toDateString(),
                'estudia' => true,
            ],
        ]);

        $matrix = collect(app(PersonalFichaService::class)->documentMatrix($ficha));

        foreach (['matrimonio_union', 'dni_hijos_menores', 'dni_hijos_mayores_estudiantes', 'constancia_estudios_hijos'] as $key) {
            $row = $matrix->firstWhere('key', $key);

            $this->assertTrue($row['required']);
            $this->assertTrue($row['applies']);
            $this->assertSame(PersonalDocumentoEstado::ESTADO_PENDIENTE, $row['estado']);
        }
    }

    public function test_vida_ley_tracks_digital_file_and_physical_delivery_separately(): void
    {
        Storage::fake('local');

        $service = app(PersonalFichaService::class);
        $user = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar', 'aprobar', 'ver']]));
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);

        $service->updateDocuments($personal, [
            'vida_ley_notarial' => UploadedFile::fake()->create('vida-ley.pdf', 18, 'application/pdf'),
        ], $user);

        $service->updateDocumentState($ficha->fresh(['archivos', 'familiares']), 'vida_ley_notarial', [
            'estado' => PersonalDocumentoEstado::ESTADO_CARGADO,
            'vida_ley_entrega_fisica' => PersonalDocumentoEstado::VIDA_LEY_FISICO_NO_APLICA_UBICACION,
            'vida_ley_entrega_observacion' => 'Trabajador fuera de ciudad.',
        ], $user);

        $row = collect($service->documentMatrix($ficha->fresh(['archivos', 'documentoEstados', 'familiares'])))
            ->firstWhere('key', 'vida_ley_notarial');

        $this->assertSame(PersonalDocumentoEstado::ESTADO_CARGADO, $row['estado']);
        $this->assertNotNull($row['archivo']);
        $this->assertSame(PersonalDocumentoEstado::VIDA_LEY_FISICO_NO_APLICA_UBICACION, $row['vida_ley_entrega_fisica']);
        $this->assertSame('Trabajador fuera de ciudad.', $row['vida_ley_entrega_observacion']);
    }

    public function test_document_review_route_requires_approve_permission(): void
    {
        $ficha = $this->createFicha();
        $personal = Personal::query()->findOrFail($ficha->personal_id);
        $deniedUser = $this->createUser(['personal' => ['ver']]);
        $allowedUser = $this->createUser(['personal' => ['aprobar']]);

        $this->withSession($this->sessionFor($deniedUser))
            ->post(route('personal.documentos.estado', ['id' => $personal->id, 'tipo' => 'cv_documentado']), [
                'estado' => PersonalDocumentoEstado::ESTADO_OBSERVADO,
                'observacion' => 'Falta nitidez.',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowedUser))
            ->post(route('personal.documentos.estado', ['id' => $personal->id, 'tipo' => 'cv_documentado']), [
                'estado' => PersonalDocumentoEstado::ESTADO_OBSERVADO,
                'observacion' => 'Falta nitidez.',
            ])
            ->assertRedirect(route('personal.documentos.index', $personal->id));
    }

    private function createFicha(?array $data = null, array $familiares = []): PersonalFicha
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

        foreach ($familiares as $familiar) {
            DB::table('personal_ficha_familiares')->insert([
                'id' => (string) Str::uuid(),
                'personal_ficha_id' => $fichaId,
                'nombres_apellidos' => $familiar['nombres_apellidos'],
                'parentesco' => $familiar['parentesco'] ?? 'Hijo',
                'fecha_nacimiento' => $familiar['fecha_nacimiento'] ?? null,
                'tipo_documento' => 'DNI',
                'numero_documento' => $familiar['numero_documento'] ?? null,
                'telefono' => null,
                'vive_con_trabajador' => false,
                'estudia' => (bool) ($familiar['estudia'] ?? false),
                'contacto_emergencia' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return PersonalFicha::query()->with(['personal', 'familiares', 'archivos', 'documentoEstados'])->findOrFail($fichaId);
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
            'nombre' => 'RRHH_DOCS_' . Str::upper(Str::random(6)),
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
