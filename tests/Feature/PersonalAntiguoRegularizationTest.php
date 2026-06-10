<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalAntiguoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalAntiguoRegularizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_trabajador_existente_sin_origen_se_marca_antiguo_sin_duplicar(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('FALTA_CONTRATO', ['numero_documento' => '73111111', 'origen_registro' => '']);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'pendiente_regularizacion' => true,
            'sincronizar_contrato' => false,
        ], null, $actor);

        $this->assertSame(1, Personal::query()->where('numero_documento', '73111111')->count());
        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'origen_registro' => 'ANTIGUO',
            'estado' => 'FALTA_CONTRATO',
            'pendiente_regularizacion' => true,
        ]);
    }

    public function test_activo_existente_con_contrato_vigente_firmado_sigue_activo_y_no_duplica(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('ACTIVO', ['numero_documento' => '73111112']);
        $this->insertContract($personal, 'ACTIVO', '2026-01-01', null, true);

        $result = app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
            'fecha_inicio' => '2026-01-01',
        ], null, $actor);

        $personal->refresh();
        $this->assertSame('ACTIVO', $personal->estado);
        $this->assertSame('ANTIGUO', $personal->origen_registro);
        $this->assertFalse((bool) $personal->pendiente_regularizacion);
        $this->assertCount(0, $result['warnings']);
        $this->assertSame(1, PersonalContrato::query()->where('personal_id', $personal->id)->count());
    }

    public function test_activo_existente_sin_contrato_firmado_muestra_advertencia_y_queda_pendiente(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('ACTIVO', ['numero_documento' => '73111113']);

        $result = app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
            'fecha_inicio' => '2026-01-01',
        ], null, $actor);

        $personal->refresh();
        $this->assertSame('ACTIVO', $personal->estado);
        $this->assertTrue((bool) $personal->pendiente_regularizacion);
        $this->assertNotEmpty($result['warnings']);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'PREPARACION',
            'archivo_pendiente_regularizacion' => true,
        ]);
    }

    public function test_falta_contrato_existente_con_pdf_firmado_puede_quedar_activo(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('FALTA_CONTRATO', ['numero_documento' => '73111114']);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
            'fecha_inicio' => '2026-01-01',
        ], UploadedFile::fake()->create('contrato-firmado.pdf', 20, 'application/pdf'), $actor);

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'ACTIVO',
            'pendiente_regularizacion' => false,
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'ACTIVO',
            'signed_contract_original_name' => 'contrato-firmado.pdf',
        ]);
    }

    public function test_cesado_existente_se_marca_historico_y_no_se_reactiva(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('CESADO', [
            'numero_documento' => '73111115',
            'fecha_cese' => '2026-05-31',
            'motivo_cese' => 'Termino anterior',
        ]);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'HISTORICO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'CERRADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-05-31',
        ], null, $actor);

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'CESADO',
            'origen_registro' => 'HISTORICO',
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'CERRADO',
            'es_historico' => true,
        ]);
    }

    public function test_sincroniza_contrato_desde_datos_actuales_y_no_duplica_equivalente(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('FALTA_CONTRATO', ['numero_documento' => '73111116']);
        $this->insertContractData($personal, '2026-02-01', null, false);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
        ], null, $actor);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
        ], null, $actor);

        $this->assertSame(1, PersonalContrato::query()->where('personal_id', $personal->id)->count());
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'fecha_inicio' => '2026-02-01',
            'estado' => 'PREPARACION',
            'archivo_pendiente_regularizacion' => true,
        ]);
    }

    public function test_asocia_pdf_antiguo_de_datos_contrato_al_contrato_laboral_actual(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createExistingPersonal('FALTA_CONTRATO', ['numero_documento' => '73111117']);
        $this->insertContractData($personal, '2026-03-01', null, true);

        app(PersonalAntiguoService::class)->regularizeExisting($personal, [
            'origen_registro' => 'ANTIGUO',
            'sincronizar_contrato' => true,
            'estado_contrato' => 'VIGENTE',
        ], null, $actor);

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'ACTIVO',
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personal->id,
            'estado' => 'ACTIVO',
            'signed_contract_original_name' => 'contrato-previo.pdf',
        ]);
    }

    public function test_rutas_de_regularizacion_respetan_permiso_actualizar(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $personal = $this->createExistingPersonal('FALTA_CONTRATO', ['numero_documento' => '73111118']);

        $this->withSession($this->sessionFor($denied))
            ->get(route('personal.antiguo.regularize', $personal->id))
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->get(route('personal.antiguo.regularize', $personal->id))
            ->assertOk();

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.antiguo.regularize.update', $personal->id), [
                'origen_registro' => 'ANTIGUO',
                'estado_contrato' => 'VIGENTE',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.antiguo.regularize.update', $personal->id), [
                'origen_registro' => 'ANTIGUO',
                'pendiente_regularizacion' => '1',
                'estado_contrato' => 'VIGENTE',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'origen_registro' => 'ANTIGUO',
        ]);
    }

    private function createExistingPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) ($overrides['numero_documento'] ?? random_int(74000000, 74999999));

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => $overrides['nombre_completo'] ?? 'Existente Regularizacion',
            'puesto' => $overrides['puesto'] ?? 'Operario',
            'ocupacion' => $overrides['ocupacion'] ?? 'Operario',
            'contrato' => $overrides['contrato'] ?? 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => $overrides['fecha_ingreso'] ?? '2026-01-01',
            'fecha_cese' => $overrides['fecha_cese'] ?? null,
            'motivo_cese' => $overrides['motivo_cese'] ?? null,
            'estado' => $estado,
            'origen_registro' => array_key_exists('origen_registro', $overrides) ? $overrides['origen_registro'] : 'NUEVO',
            'pendiente_regularizacion' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $estado, string $inicio, ?string $fin, bool $signed): void
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-actual.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => $estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'activado_at' => '2026-01-01 08:00:00',
            'signed_at' => $signed ? '2026-01-01 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-actual.pdf' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertContractData(Personal $personal, string $inicio, ?string $fin, bool $signed): void
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-previo.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => $inicio,
            'fecha_fin_contrato' => $fin,
            'puesto' => $personal->puesto,
            'fecha_firma' => $signed ? $inicio : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-previo.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
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
            'nombre' => 'RRHH_REG_' . Str::upper(Str::random(6)),
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
                'email' => 'regularizacion@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
