<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoDatoService;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalContratoServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cese_guarda_snapshot_y_activacion_abre_siguiente_contrato(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');

        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $workerRoleId = $this->createRole('TRABAJADOR_CONTRATOS');
        $personalId = $this->createPersonal();
        $this->createFicha($personalId);
        $this->createUser($workerRoleId, 'trabajador', $personalId);

        $personal = Personal::query()->with('fichaColaborador')->findOrFail($personalId);
        app(PersonalService::class)->markCeased($personal, 'Termino de contrato', $actor, '2026-05-31');

        $expectedPersonal = [
            'id' => $personalId,
            'estado' => 'CESADO',
        ];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $expectedPersonal['fecha_cese'] = '2026-05-31';
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $expectedPersonal['motivo_cese'] = 'Termino de contrato';
        }
        $this->assertDatabaseHas('personal', $expectedPersonal);

        $closed = DB::table('personal_contratos')
            ->where('personal_id', $personalId)
            ->where('contrato_numero', 1)
            ->first();

        $this->assertNotNull($closed);
        $this->assertSame('CERRADO', $closed->estado);
        $this->assertSame('2026-05-31', $closed->fecha_fin);

        $snapshot = json_decode($closed->snapshot_json, true);
        $this->assertSame('cierre_contrato', $snapshot['evento']);
        $this->assertSame('Termino de contrato', data_get($snapshot, 'extra.motivo_cese'));
        $this->assertSame('Contrato Test', data_get($snapshot, 'trabajador.nombre_completo'));
        $this->assertTrue((bool) data_get($snapshot, 'usuario_proserge.tiene_usuario'));
        $this->assertSame('trabajador-contrato@test.local', data_get($snapshot, 'usuario_proserge.usuario.email'));

        $newContract = app(PersonalContratoService::class)->activateNextContract(
            Personal::query()->findOrFail($personalId),
            '2026-06-01',
            null,
            $actor,
        );

        $this->assertSame(2, (int) $newContract->contrato_numero);
        $this->assertSame($closed->id, $newContract->origen_contrato_id);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personalId,
            'contrato_numero' => 2,
            'estado' => 'PREPARACION',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
        ]);
        $expectedActivatedPersonal = [
            'id' => $personalId,
            'estado' => 'FALTA_CONTRATO',
            'fecha_ingreso' => '2026-06-01',
        ];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $expectedActivatedPersonal['fecha_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $expectedActivatedPersonal['motivo_cese'] = null;
        }
        $this->assertDatabaseHas('personal', $expectedActivatedPersonal);

        $fichaData = json_decode(DB::table('personal_fichas')->where('personal_id', $personalId)->value('datos_json'), true);
        $this->assertSame('2026-06-01', $fichaData['fecha_ingreso']);
        $this->assertSame('', $fichaData['fecha_fin_contrato']);

    }

    public function test_historical_contracts_are_not_physically_deleted(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();
        $closedId = (string) Str::uuid();
        $preparingId = (string) Str::uuid();

        DB::table('personal_contratos')->insert([
            [
                'id' => $closedId,
                'personal_id' => $personalId,
                'contrato_numero' => 1,
                'estado' => 'CERRADO',
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => '2026-05-31',
                'motivo_cese' => 'Termino de contrato',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $preparingId,
                'personal_id' => $personalId,
                'contrato_numero' => 2,
                'estado' => 'PREPARACION',
                'fecha_inicio' => '2026-06-01',
                'fecha_fin' => null,
                'motivo_cese' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(PersonalContratoService::class);
        $personal = Personal::query()->findOrFail($personalId);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $closedId,
            'estado' => 'CERRADO',
        ]);

        $service->annulContract($personal, $closedId, 'Error de prueba', $actor);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $closedId,
            'estado' => 'ANULADO',
            'motivo_anulacion' => 'Error de prueba',
        ]);
        $this->assertDatabaseHas('personal_contrato_correcciones', [
            'personal_contrato_id' => $closedId,
            'accion' => 'ANULACION',
            'motivo' => 'Error de prueba',
        ]);

        $service->annulContract($personal, $preparingId, 'Creado por error', $actor);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $preparingId,
            'estado' => 'ANULADO',
            'motivo_anulacion' => 'Creado por error',
        ]);
    }

    public function test_contract_correction_updates_dates_and_keeps_audit(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();
        $contractId = (string) Str::uuid();

        DB::table('personal_contratos')->insert([
            'id' => $contractId,
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-10-31',
            'tipo_contrato' => 'FIJO',
            'puesto' => 'Operario',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract = app(PersonalContratoService::class)->correctContract(
            Personal::query()->findOrFail($personalId),
            $contractId,
            [
                'fecha_inicio' => '2026-06-02',
                'fecha_fin' => '2026-11-01',
                'tipo_contrato' => 'FIJO',
                'puesto' => 'Operario',
                'motivo_correccion' => 'Fecha digitada por error',
            ],
            $actor,
        );

        $this->assertSame('2026-06-02', optional($contract->fecha_inicio)->toDateString());
        $this->assertSame('2026-11-01', optional($contract->fecha_fin)->toDateString());
        $this->assertDatabaseHas('personal_contrato_correcciones', [
            'personal_contrato_id' => $contractId,
            'accion' => 'CORRECCION',
            'motivo' => 'Fecha digitada por error',
        ]);
        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personalId,
            'fecha_inicio_contrato' => '2026-06-02',
            'fecha_fin_contrato' => '2026-11-01',
        ]);

        $snapshot = json_decode((string) DB::table('personal_contratos')->where('id', $contractId)->value('snapshot_inicial_json'), true);
        $this->assertSame('correccion_contrato', data_get($snapshot, 'evento'));
        $this->assertSame('2026-06-02', data_get($snapshot, 'rango.fecha_inicio'));
        $this->assertSame('2026-11-01', data_get($snapshot, 'rango.fecha_fin'));
        $this->assertSame('Fecha digitada por error', data_get($snapshot, 'extra.motivo_correccion'));
    }

    public function test_closed_contract_correction_refreshes_historical_snapshot(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();
        $contractId = (string) Str::uuid();

        DB::table('personal_contratos')->insert([
            'id' => $contractId,
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'CERRADO',
            'fecha_inicio' => '2026-04-01',
            'fecha_fin' => '2026-04-30',
            'tipo_contrato' => 'FIJO',
            'puesto' => 'Operario',
            'snapshot_json' => json_encode([
                'evento' => 'cierre_contrato',
                'rango' => [
                    'fecha_inicio' => '2026-04-01',
                    'fecha_fin' => '2026-04-30',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract = app(PersonalContratoService::class)->correctContract(
            Personal::query()->findOrFail($personalId),
            $contractId,
            [
                'fecha_inicio' => '2026-04-02',
                'fecha_fin' => '2026-05-01',
                'tipo_contrato' => 'FIJO',
                'puesto' => 'Operario',
                'motivo_correccion' => 'Regularizacion de fecha historica',
            ],
            $actor,
        );

        $this->assertSame('2026-04-02', optional($contract->fecha_inicio)->toDateString());
        $this->assertSame('2026-05-01', optional($contract->fecha_fin)->toDateString());

        $snapshot = json_decode((string) DB::table('personal_contratos')->where('id', $contractId)->value('snapshot_json'), true);
        $this->assertSame('correccion_contrato', data_get($snapshot, 'evento'));
        $this->assertSame('2026-04-02', data_get($snapshot, 'rango.fecha_inicio'));
        $this->assertSame('2026-05-01', data_get($snapshot, 'rango.fecha_fin'));
        $this->assertSame('Regularizacion de fecha historica', data_get($snapshot, 'extra.motivo_correccion'));
    }

    public function test_closed_contract_cannot_be_edited(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();

        DB::table('personal')->where('id', $personalId)->update(['estado' => 'CESADO']);
        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'CERRADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-05-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(PersonalContratoDatoService::class)->update(
                Personal::query()->findOrFail($personalId),
                ['puesto' => 'No debe cambiar'],
                $actor,
            );
            $this->fail('No debio permitir editar un contrato cerrado.');
        } catch (ValidationException $exception) {
            $this->assertSame('Solo se puede modificar el contrato vigente o en preparacion.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame('Operario', DB::table('personal')->where('id', $personalId)->value('puesto'));
    }

    public function test_signed_contract_is_attached_to_current_contract(): void
    {
        Storage::fake('local');

        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();
        DB::table('personal')->where('id', $personalId)->update(['estado' => 'FALTA_CONTRATO']);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'PREPARACION',
            'fecha_inicio' => '2026-06-01',
            'activado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PersonalContratoDatoService::class)->uploadSignedContract(
            Personal::query()->findOrFail($personalId),
            UploadedFile::fake()->create('contrato-firmado.pdf', 24, 'application/pdf'),
            $actor,
        );

        $contract = DB::table('personal_contratos')->where('personal_id', $personalId)->first();
        $this->assertSame('ACTIVO', $contract->estado);
        $this->assertSame('contrato-firmado.pdf', $contract->signed_contract_original_name);
        $this->assertNotNull($contract->signed_at);
        $this->assertSame('ACTIVO', DB::table('personal')->where('id', $personalId)->value('estado'));
    }

    public function test_old_signed_contract_does_not_activate_new_preparing_contract(): void
    {
        $personalId = $this->createPersonal();
        DB::table('personal')->where('id', $personalId)->update(['estado' => 'FALTA_CONTRATO']);

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'signed_at' => '2026-05-01 08:00:00',
            'signed_contract_path' => 'personal_contratos/' . $personalId . '/contrato_antiguo.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_contratos')->insert([
            [
                'id' => (string) Str::uuid(),
                'personal_id' => $personalId,
                'contrato_numero' => 1,
                'estado' => 'CERRADO',
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => '2026-05-31',
                'activado_at' => null,
                'signed_at' => '2026-05-01 08:00:00',
                'signed_contract_path' => 'personal_contratos/' . $personalId . '/contrato_antiguo.pdf',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'personal_id' => $personalId,
                'contrato_numero' => 2,
                'estado' => 'PREPARACION',
                'fecha_inicio' => '2026-06-01',
                'fecha_fin' => null,
                'activado_at' => '2026-06-01 09:00:00',
                'signed_at' => null,
                'signed_contract_path' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $updated = app(PersonalService::class)->update(Personal::query()->findOrFail($personalId), [
            'dni' => '70000002',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70000002',
            'nombre_completo' => 'Falta Contrato',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'ACTIVO',
        ]);

        $this->assertSame('FALTA_CONTRATO', $updated->estado);
    }

    public function test_no_permite_registrar_contrato_con_periodo_solapado(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'CERRADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(PersonalContratoService::class)->registerLegacyContract(
                Personal::query()->findOrFail($personalId),
                [
                    'fecha_inicio' => '2026-06-30',
                    'fecha_fin' => '2026-12-31',
                    'estado_laboral' => 'CESADO',
                    'estado_contrato' => 'CERRADO',
                    'tipo_contrato' => 'FIJO',
                    'puesto' => 'Operario',
                    'motivo_cese' => 'Contrato historico',
                ],
                null,
                $actor,
            );

            $this->fail('No debio permitir registrar dos contratos cruzados por la misma fecha.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'cruza ese periodo',
                collect($exception->errors())->flatten()->first()
            );
        }
    }

    public function test_permite_registrar_contrato_continuo_al_dia_siguiente(): void
    {
        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $personalId = $this->createPersonal();

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'contrato_numero' => 1,
            'estado' => 'CERRADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contract = app(PersonalContratoService::class)->registerLegacyContract(
            Personal::query()->findOrFail($personalId),
            [
                'fecha_inicio' => '2026-07-01',
                'fecha_fin' => '2026-12-31',
                'estado_laboral' => 'CESADO',
                'estado_contrato' => 'CERRADO',
                'tipo_contrato' => 'FIJO',
                'puesto' => 'Operario',
                'motivo_cese' => 'Contrato historico',
            ],
            null,
            $actor,
        );

        $this->assertSame('2026-07-01', optional($contract->fecha_inicio)->toDateString());
        $this->assertSame('2026-12-31', optional($contract->fecha_fin)->toDateString());
    }

    private function createRole(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => $name . '_' . Str::upper(Str::random(6)),
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $prefix, ?string $personalId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => $prefix === 'trabajador' ? 'trabajador-contrato@test.local' : $prefix . '-' . Str::lower(Str::random(6)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createPersonal(): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Contrato Test',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-15',
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'contrato@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createFicha(string $personalId): void
    {
        DB::table('personal_fichas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'estado' => 'APROBADO',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'datos_json' => json_encode([
                'contrato' => 'FIJO',
                'fecha_ingreso' => '2026-01-15',
                'fecha_fin_contrato' => '2026-05-31',
                'banco' => 'BCP',
                'numero_cuenta' => '123-456',
            ]),
            'datos_detectados_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
