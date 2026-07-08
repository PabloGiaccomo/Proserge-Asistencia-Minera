<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalAntiguoService;
use App\Modules\Personal\Services\PersonalContratoDatoService;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalDocumentoDownloadService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class PersonalAntiguoRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registra_personal_antiguo_activo_con_contrato_vigente_firmado(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear', 'ver', 'actualizar']]));

        $personal = app(PersonalAntiguoService::class)->create(
            $this->legacyPayload(['numero_documento' => '72111111']),
            UploadedFile::fake()->create('contrato-vigente.pdf', 20, 'application/pdf'),
            $actor,
        );

        $this->assertSame('ACTIVO', $personal->estado);
        $this->assertSame('ANTIGUO', $personal->origen_registro);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'ACTIVO',
            'origen_registro' => 'ANTIGUO',
            'es_historico' => false,
            'signed_contract_original_name' => 'contrato-vigente.pdf',
        ]);
        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personal->id,
            'signed_contract_original_name' => 'contrato-vigente.pdf',
        ]);
    }

    public function test_activo_antiguo_sin_contrato_firmado_queda_falta_contrato(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear']]));

        $personal = app(PersonalAntiguoService::class)->create(
            $this->legacyPayload(['numero_documento' => '72111112']),
            null,
            $actor,
        );

        $this->assertSame('FALTA_CONTRATO', $personal->estado);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'PREPARACION',
            'archivo_pendiente_regularizacion' => true,
        ]);
    }

    public function test_registra_personal_antiguo_cesado_con_contrato_cerrado_inamovible(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear', 'actualizar', 'eliminar']]));

        $personal = app(PersonalAntiguoService::class)->create(
            $this->legacyPayload([
                'numero_documento' => '72111113',
                'estado_laboral' => 'CESADO',
                'estado_contrato' => 'CERRADO',
                'fecha_fin' => '2026-05-31',
                'motivo_cese' => 'Termino de contrato antiguo',
            ]),
            UploadedFile::fake()->create('contrato-historico.pdf', 20, 'application/pdf'),
            $actor,
        );

        $contract = DB::table('personal_contratos')->where('personal_id', $personal->id)->first();

        $this->assertSame('CESADO', $personal->estado);
        $this->assertSame('CERRADO', $contract->estado);
        $this->assertTrue((bool) $contract->es_historico);
        $this->assertNotNull($contract->snapshot_json);

        try {
            app(PersonalContratoDatoService::class)->update($personal, ['puesto' => 'No cambia'], $actor);
            $this->fail('No debio permitir editar un contrato historico.');
        } catch (ValidationException $exception) {
            $this->assertSame('Solo se puede modificar el contrato vigente o en preparacion.', collect($exception->errors())->flatten()->first());
        }

        app(PersonalContratoService::class)->annulContract($personal, $contract->id, 'Error historico', $actor);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => 'ANULADO',
            'motivo_anulacion' => 'Error historico',
        ]);
        $this->assertDatabaseHas('personal_contrato_correcciones', [
            'personal_contrato_id' => $contract->id,
            'accion' => 'ANULACION',
            'motivo' => 'Error historico',
        ]);
    }

    public function test_contrato_antiguo_sin_archivo_se_registra_como_pendiente_de_regularizacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear']]));

        $personal = app(PersonalAntiguoService::class)->create(
            $this->legacyPayload([
                'numero_documento' => '72111114',
                'estado_laboral' => 'CESADO',
                'estado_contrato' => 'CERRADO',
                'fecha_fin' => '2026-05-31',
            ]),
            null,
            $actor,
        );

        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'CERRADO',
            'archivo_pendiente_regularizacion' => true,
        ]);
    }

    public function test_no_permita_duplicar_documento_activo_o_cesado(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear']]));
        $this->insertExistingPersonal('72111115', 'ACTIVO');

        try {
            app(PersonalAntiguoService::class)->create($this->legacyPayload(['numero_documento' => '72111115']), null, $actor);
            $this->fail('No debio permitir duplicado activo.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('No se puede crear un duplicado', collect($exception->errors())->flatten()->first());
        }

        $this->insertExistingPersonal('72111116', 'CESADO');

        try {
            app(PersonalAntiguoService::class)->create($this->legacyPayload(['numero_documento' => '72111116']), null, $actor);
            $this->fail('No debio permitir duplicado cesado.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Revisa el registro existente', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_documentos_y_descarga_funcionan_para_personal_antiguo(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive no esta disponible.');
        }

        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['crear', 'ver', 'actualizar']]));
        $personal = app(PersonalAntiguoService::class)->create(
            $this->legacyPayload(['numero_documento' => '72111117']),
            UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf'),
            $actor,
        );

        $ficha = PersonalFicha::query()->where('personal_id', $personal->id)->firstOrFail();
        $path = 'personal_fichas/tests/' . Str::uuid() . '/dni.pdf';
        Storage::disk('local')->put($path, 'dni');
        DB::table('personal_ficha_archivos')->insert([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'tipo' => 'dni_vigente',
            'nombre_original' => 'dni.pdf',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 3,
            'uploaded_by_usuario_id' => $actor->id,
            'uploaded_by_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $zip = app(PersonalDocumentoDownloadService::class)->createZipForPersonalIds([$personal->id], ['dni_vigente']);

        $this->assertSame(1, $zip['included']);
        $this->assertFileExists($zip['path']);
        File::delete($zip['path']);
    }

    private function legacyPayload(array $overrides = []): array
    {
        return [
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(73000000, 73999999),
            'nombres' => 'Antiguo',
            'apellido_paterno' => 'Trabajador',
            'apellido_materno' => 'Prueba',
            'telefono' => '999888777',
            'correo' => 'antiguo@test.local',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'estado_laboral' => 'ACTIVO',
            'estado_contrato' => 'VIGENTE',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'fecha_firma' => '2026-01-01',
            'area' => 'Operaciones',
            'mina' => 'Mina Test',
            'remuneracion' => '2500',
            'costo_hora' => '12.50',
            'es_supervisor' => false,
            'observacion_historica' => 'Carga manual de prueba',
            ...$overrides,
        ];
    }

    private function insertExistingPersonal(string $document, string $estado): void
    {
        DB::table('personal')->insert([
            'id' => (string) Str::uuid(),
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Duplicado Test',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePuesto(string $nombre): void
    {
        if (!Schema::hasTable('personal_puestos')) {
            return;
        }

        if (DB::table('personal_puestos')->where('nombre', $nombre)->exists()) {
            DB::table('personal_puestos')
                ->where('nombre', $nombre)
                ->update(['activo' => true, 'updated_at' => now()]);

            return;
        }

        DB::table('personal_puestos')->insert([
                'id' => (string) Str::uuid(),
                'nombre' => $nombre,
                'funciones' => null,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
        ]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_ANTIGUO_' . Str::upper(Str::random(6)),
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
                'email' => 'legacy@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
