<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalContratoExpiryDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_vista_muestra_contratos_activos_del_mes_filtrado(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $userId = $this->createUser(['personal' => ['ver']]);
        $juneWorker = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Control Vencimiento Junio']);
        $julyWorker = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Control Vencimiento Julio']);
        $this->insertContract($juneWorker, '2026-01-01', '2026-06-20', true, ['area' => 'Mantenimiento', 'puesto' => 'Soldador']);
        $this->insertContract($julyWorker, '2026-01-01', '2026-07-20', true, ['area' => 'Operaciones', 'puesto' => 'Operario']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Control Vencimiento Junio')
            ->assertDontSee('Control Vencimiento Julio');
    }

    public function test_filtros_por_mes_area_cargo_y_decision(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $target = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Filtro Contrato']);
        $other = $this->createPersonal('ACTIVO', ['nombre_completo' => 'Otro Contrato']);
        $targetContract = $this->insertContract($target, '2026-01-01', '2026-06-20', true, ['area' => 'Mantenimiento', 'puesto' => 'Soldador']);
        $this->insertContract($other, '2026-01-01', '2026-06-22', true, ['area' => 'Operaciones', 'puesto' => 'Operario']);

        app(PersonalContratoService::class)->registerRenewalDecision($targetContract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_EN_EVALUACION,
        ], $actor);

        $result = app(PersonalContratoService::class)->listExpiringContracts([
            'mes' => 6,
            'anio' => 2026,
            'area' => 'Mant',
            'cargo' => 'Sold',
            'estado_decision' => PersonalContrato::DECISION_EN_EVALUACION,
            'estado_laboral' => 'ACTIVO',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($targetContract->id, $result->first()->id);
    }

    public function test_no_renovar_requiere_motivo_y_otro_requiere_observacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);

        try {
            $service->registerRenewalDecision($contract, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            ], $actor);
            $this->fail('No debio guardar no renovacion sin motivo.');
        } catch (ValidationException $exception) {
            $this->assertSame('El motivo de no renovacion es obligatorio.', collect($exception->errors())->flatten()->first());
        }

        try {
            $service->registerRenewalDecision($contract, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
                'motivo_no_renovacion' => PersonalContrato::MOTIVO_OTRO,
            ], $actor);
            $this->fail('No debio guardar motivo otro sin observacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('La observacion es obligatoria cuando el motivo es otro.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_guarda_decisiones_renovar_y_no_renovar_sin_cesar_automaticamente(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $renewPersonal = $this->createPersonal('ACTIVO');
        $noRenewPersonal = $this->createPersonal('ACTIVO');
        $renewContract = $this->insertContract($renewPersonal, '2026-01-01', '2026-06-30', true);
        $noRenewContract = $this->insertContract($noRenewPersonal, '2026-01-01', '2026-06-30', true);

        app(PersonalContratoService::class)->registerRenewalDecision($renewContract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
        ], $actor);
        app(PersonalContratoService::class)->registerRenewalDecision($noRenewContract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            'motivo_no_renovacion' => PersonalContrato::MOTIVO_DECISION_AREA,
            'observacion_decision' => 'Decision registrada para control simple.',
        ], $actor);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $renewContract->id,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            'decision_final' => PersonalContrato::DECISION_RENOVAR,
        ]);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $noRenewContract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            'decision_final' => PersonalContrato::DECISION_NO_RENOVAR,
            'motivo_no_renovacion' => PersonalContrato::MOTIVO_DECISION_AREA,
        ]);
        $this->assertSame('ACTIVO', $noRenewPersonal->fresh()->estado);
    }

    public function test_prepara_renovacion_desde_decision_sin_desactivar_y_evita_duplicado(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');
        Storage::fake('local');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['puesto' => 'Mecanico']);

        app(PersonalContratoService::class)->registerRenewalDecision($contract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
        ], $actor);

        $renewal = app(PersonalContratoService::class)->prepareRenewalFromDecision($contract, [
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-12-31',
            'observacion_renovacion' => 'Preparada desde control de vencimientos.',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_PREPARACION, $renewal->estado);
        $this->assertSame(PersonalContrato::MOVIMIENTO_RENOVACION, $renewal->tipo_movimiento);
        $this->assertSame('ACTIVO', $personal->fresh()->estado);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVACION_PREPARADA,
        ]);

        try {
            app(PersonalContratoService::class)->prepareRenewalFromDecision($contract->fresh(), [
                'fecha_inicio' => '2027-01-01',
            ], $actor);
            $this->fail('No debio crear doble contrato en preparacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('Ya existe un contrato en preparacion para este trabajador. Revisalo antes de crear otro.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_contrato_historico_no_aparece_ni_acepta_decision_activa(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('CESADO');
        $closed = $this->insertContract($personal, '2026-01-01', '2026-06-30', true, ['estado' => PersonalContrato::ESTADO_CERRADO]);

        $result = app(PersonalContratoService::class)->listExpiringContracts(['mes' => 6, 'anio' => 2026]);
        $this->assertFalse($result->contains('id', $closed->id));

        try {
            app(PersonalContratoService::class)->registerRenewalDecision($closed, [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ], $actor);
            $this->fail('No debio registrar decision activa en contrato historico.');
        } catch (ValidationException $exception) {
            $this->assertSame('Solo se puede registrar decision sobre contratos vigentes activos.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_rutas_respetan_permiso_actualizar(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['personal' => ['ver', 'actualizar', 'editar']]);
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);

        $this->withSession($this->sessionFor($denied))
            ->get(route('personal.contratos.expiring', ['mes' => 6, 'anio' => 2026]))
            ->assertOk();

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.contratos.decision', $contract->id), [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.contratos.decision', $contract->id), [
                'estado_decision_renovacion' => PersonalContrato::DECISION_RENOVAR,
            ])
            ->assertRedirect();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.contratos.prepare-from-decision', $contract->id), [
                'fecha_inicio' => '2026-07-01',
            ])
            ->assertRedirect(route('personal.contrato-datos.edit', $personal->id));
    }

    public function test_no_se_agregan_nombres_propios_en_archivos_de_la_etapa(): void
    {
        $files = [
            app_path('Models/PersonalContrato.php'),
            app_path('Modules/Personal/Services/PersonalContratoService.php'),
            app_path('Modules/Personal/Controllers/PersonalContratoController.php'),
            resource_path('views/personal/contratos/vencimientos.blade.php'),
            database_path('migrations/2026_06_05_000700_add_renewal_decision_fields_to_personal_contratos.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', $content, $file);
        }
    }

    private function createPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(77000000, 77999999);

        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Control Contrato',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'control@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $inicio, string $fin, bool $signed, array $overrides = []): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/contrato-vigente.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        $id = (string) Str::uuid();
        $estado = $overrides['estado'] ?? PersonalContrato::ESTADO_ACTIVO;
        unset($overrides['estado']);

        DB::table('personal_contratos')->insert(array_merge([
            'id' => $id,
            'personal_id' => $personal->id,
            'contrato_numero' => ((int) PersonalContrato::query()->where('personal_id', $personal->id)->max('contrato_numero')) + 1,
            'estado' => $estado,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'puesto' => $personal->puesto,
            'area' => null,
            'tipo_contrato' => 'FIJO',
            'activado_at' => $estado === PersonalContrato::ESTADO_ACTIVO ? $inicio . ' 08:00:00' : null,
            'cerrado_at' => $estado === PersonalContrato::ESTADO_CERRADO ? $fin . ' 18:00:00' : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-vigente.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return PersonalContrato::query()->findOrFail($id);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_CONTROL_' . Str::upper(Str::random(6)),
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
                'email' => 'control@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
