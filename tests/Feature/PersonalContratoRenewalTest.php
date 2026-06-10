<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoDatoService;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalContratoRenewalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_renovacion_futura_crea_preparacion_sin_desactivar_ni_cerrar_base(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $base = $this->insertContract($personal, 'ACTIVO', '2026-01-01', '2026-06-30', true, [
            'puesto' => 'Soldador',
            'remuneracion' => '2500',
            'costo_hora' => '15',
            'tipo_contrato' => 'FIJO',
        ]);
        $this->insertContractData($personal, '2026-01-01', '2026-06-30', true, [
            'puesto' => 'Soldador',
            'sueldo_num' => '2500',
            'sueldo_hora_paradas' => '15',
        ]);

        $newContract = app(PersonalContratoService::class)->prepareRenewal($personal, [
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
            'observacion_renovacion' => 'Renovacion segundo semestre',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $newContract->estado);
        $this->assertSame(PersonalContrato::MOVIMIENTO_RENOVACION, $newContract->tipo_movimiento);
        $this->assertSame($base->id, $newContract->origen_contrato_id);
        $this->assertSame('Soldador', $newContract->puesto);
        $this->assertSame('2500', $newContract->remuneracion);
        $this->assertSame('15', $newContract->costo_hora);
        $this->assertNull($newContract->signed_at);
        $this->assertNull($newContract->signed_contract_path);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $base->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'fecha_fin' => '2026-06-30',
            'signed_contract_original_name' => 'contrato-base.pdf',
        ]);
        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'ACTIVO',
            'fecha_ingreso' => '2026-01-01',
        ]);
        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => '2026-07-01',
            'fecha_fin_contrato' => '2026-12-31',
            'signed_contract_original_name' => 'contrato-datos-base.pdf',
        ]);
    }

    public function test_editar_contrato_en_preparacion_no_modifica_contrato_anterior(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $base = $this->insertContract($personal, 'ACTIVO', '2026-01-01', null, true, [
            'puesto' => 'Operario',
            'remuneracion' => '2200',
            'costo_hora' => '12',
        ]);

        $newContract = app(PersonalContratoService::class)->prepareRenewal($personal, [
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => null,
        ], $actor);

        app(PersonalContratoDatoService::class)->update($personal->fresh(), [
            'fecha_inicio_contrato' => '2026-07-15',
            'fecha_fin_contrato' => '2026-12-31',
            'puesto' => 'Supervisor mecanico',
            'sueldo_num' => '3200',
            'sueldo_hora_paradas' => '22',
        ], $actor);

        $base->refresh();
        $newContract->refresh();

        $this->assertSame('Operario', $base->puesto);
        $this->assertSame('2200', $base->remuneracion);
        $this->assertSame('12', $base->costo_hora);
        $this->assertSame(PersonalContrato::ESTADO_ACTIVO, $base->estado);

        $this->assertSame('Supervisor mecanico', $newContract->puesto);
        $this->assertSame('3200', $newContract->remuneracion);
        $this->assertSame('22', $newContract->costo_hora);
        $this->assertSame('2026-07-15', optional($newContract->fecha_inicio)->toDateString());
    }

    public function test_pdf_anterior_no_activa_renovacion_y_pdf_nuevo_se_asocia_al_contrato_nuevo(): void
    {
        Storage::fake('local');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $base = $this->insertContract($personal, 'ACTIVO', '2026-01-01', null, true);
        $this->insertContractData($personal, '2026-01-01', null, true);

        $newContract = app(PersonalContratoService::class)->prepareRenewal($personal, [
            'fecha_inicio' => '2026-07-01',
        ], $actor);

        $this->assertTrue(app(PersonalService::class)->hasSignedContract($personal->fresh()));
        $this->assertNull($newContract->signed_contract_path);

        app(PersonalContratoDatoService::class)->uploadSignedContract(
            $personal->fresh(),
            UploadedFile::fake()->create('contrato-renovado.pdf', 32, 'application/pdf'),
            $actor,
        );

        $newContract->refresh();
        $base->refresh();

        $this->assertSame(PersonalContrato::ESTADO_ACTIVO, $newContract->estado);
        $this->assertSame('contrato-renovado.pdf', $newContract->signed_contract_original_name);
        $this->assertSame('contrato-base.pdf', $base->signed_contract_original_name);
        $this->assertSame(PersonalContrato::ESTADO_CERRADO, $base->estado);
        $this->assertSame('ACTIVO', $personal->fresh()->estado);
    }

    public function test_bloquea_doble_preparacion_anulado_y_renovacion_sin_base(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $this->insertContract($personal, 'ACTIVO', '2026-01-01', null, true);

        app(PersonalContratoService::class)->prepareRenewal($personal, ['fecha_inicio' => '2026-07-01'], $actor);

        $this->expectException(ValidationException::class);
        app(PersonalContratoService::class)->prepareRenewal($personal->fresh(), ['fecha_inicio' => '2026-08-01'], $actor);
    }

    public function test_bloquea_renovar_contrato_anulado_o_sin_contrato_base(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $sinBase = $this->createPersonal('ACTIVO');

        try {
            app(PersonalContratoService::class)->prepareRenewal($sinBase, ['fecha_inicio' => '2026-07-01'], $actor);
            $this->fail('No debio renovar sin contrato base.');
        } catch (ValidationException $exception) {
            $this->assertSame('No hay contrato vigente renovable para este trabajador.', collect($exception->errors())->flatten()->first());
        }

        $anulado = $this->createPersonal('ACTIVO');
        $this->insertContract($anulado, PersonalContrato::ESTADO_ANULADO, '2026-01-01', null, true);

        try {
            app(PersonalContratoService::class)->prepareRenewal($anulado, ['fecha_inicio' => '2026-07-01'], $actor);
            $this->fail('No debio renovar contrato anulado.');
        } catch (ValidationException $exception) {
            $this->assertSame('No hay contrato vigente renovable para este trabajador.', collect($exception->errors())->flatten()->first());
        }

        $sinFirma = $this->createPersonal('ACTIVO');
        $this->insertContract($sinFirma, PersonalContrato::ESTADO_ACTIVO, '2026-01-01', null, false);

        try {
            app(PersonalContratoService::class)->prepareRenewal($sinFirma, ['fecha_inicio' => '2026-07-01'], $actor);
            $this->fail('No debio renovar contrato vigente sin firma.');
        } catch (ValidationException $exception) {
            $this->assertSame('El contrato base no tiene PDF firmado vigente. Regularizalo antes de renovar.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_reingreso_de_cesado_crea_preparacion_y_no_activa_hasta_pdf_firmado(): void
    {
        Storage::fake('local');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('CESADO', [
            'fecha_cese' => '2026-05-31',
            'motivo_cese' => 'Termino de contrato anterior',
        ]);
        $base = $this->insertContract($personal, PersonalContrato::ESTADO_CERRADO, '2026-01-01', '2026-05-31', true, [
            'puesto' => 'Mecanico',
            'remuneracion' => '2400',
        ]);

        $newContract = app(PersonalContratoService::class)->prepareReentry($personal, [
            'fecha_inicio' => '2026-08-01',
            'observacion_renovacion' => 'Retorno por nueva parada',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $newContract->estado);
        $this->assertSame(PersonalContrato::MOVIMIENTO_REINGRESO, $newContract->tipo_movimiento);
        $this->assertSame($base->id, $newContract->origen_contrato_id);
        $this->assertSame('Mecanico', $newContract->puesto);
        $this->assertSame(PersonalContratoDatoService::PENDING_STATE, $personal->fresh()->estado);
        $this->assertNull($personal->fresh()->fecha_cese);

        app(PersonalContratoDatoService::class)->uploadSignedContract(
            $personal->fresh(),
            UploadedFile::fake()->create('contrato-reingreso.pdf', 32, 'application/pdf'),
            $actor,
        );

        $this->assertSame('ACTIVO', $personal->fresh()->estado);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $newContract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'signed_contract_original_name' => 'contrato-reingreso.pdf',
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $base->id,
            'estado' => PersonalContrato::ESTADO_CERRADO,
            'fecha_fin' => '2026-05-31',
        ]);
    }

    public function test_rutas_de_renovacion_y_reingreso_respetan_permiso_actualizar(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['personal' => ['ver', 'actualizar', 'editar']]);
        $activo = $this->createPersonal('ACTIVO');
        $this->insertContract($activo, 'ACTIVO', '2026-01-01', null, true);
        $cesado = $this->createPersonal('CESADO', [
            'fecha_cese' => '2026-05-31',
            'motivo_cese' => 'Fin anterior',
        ]);
        $this->insertContract($cesado, 'CERRADO', '2026-01-01', '2026-05-31', true);

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.contratos.renew', $activo->id), ['fecha_inicio' => '2026-07-01'])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.contratos.renew', $activo->id), ['fecha_inicio' => '2026-07-01'])
            ->assertRedirect(route('personal.contrato-datos.edit', $activo->id));

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.contratos.reentry', $cesado->id), ['fecha_inicio' => '2026-08-01'])
            ->assertRedirect(route('personal.contrato-datos.edit', $cesado->id));
    }

    private function createPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(76000000, 76999999);

        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Renovacion Test',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'renovacion@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $estado, string $inicio, ?string $fin, bool $signed, array $overrides = []): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-base.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        $id = (string) Str::uuid();
        DB::table('personal_contratos')->insert(array_merge([
            'id' => $id,
            'personal_id' => $personal->id,
            'contrato_numero' => ((int) PersonalContrato::query()->where('personal_id', $personal->id)->max('contrato_numero')) + 1,
            'estado' => $estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'puesto' => $personal->puesto,
            'remuneracion' => null,
            'costo_hora' => null,
            'tipo_contrato' => 'FIJO',
            'activado_at' => $estado === PersonalContrato::ESTADO_ACTIVO ? $inicio . ' 08:00:00' : null,
            'cerrado_at' => in_array($estado, [PersonalContrato::ESTADO_CERRADO, PersonalContrato::ESTADO_CESADO, PersonalContrato::ESTADO_NO_RENOVADO], true) ? ($fin ?: $inicio) . ' 18:00:00' : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-base.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return PersonalContrato::query()->findOrFail($id);
    }

    private function insertContractData(Personal $personal, string $inicio, ?string $fin, bool $signed, array $overrides = []): void
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-datos-base.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        DB::table('personal_contrato_datos')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => $inicio,
            'fecha_fin_contrato' => $fin,
            'puesto' => $personal->puesto,
            'fecha_firma' => $signed ? $inicio : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-datos-base.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_RENOV_' . Str::upper(Str::random(6)),
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
                'email' => 'renovacion@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
