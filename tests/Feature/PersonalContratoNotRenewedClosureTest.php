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

class PersonalContratoNotRenewedClosureTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cierra_no_renovado_vencido_y_cesa_si_no_hay_otro_contrato_firmado(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);
        $this->registerNoRenewal($service, $contract, $actor);

        $closed = $service->closeAsNotRenewed($contract, [
            'fecha_cese' => '2026-06-30',
            'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
            'observacion_cese_controlado' => 'Cierre manual al terminar contrato.',
        ], $actor);

        $this->assertSame(PersonalContrato::ESTADO_NO_RENOVADO, $closed->estado);
        $this->assertSame(PersonalContrato::DECISION_NO_RENOVADO, $closed->estado_decision_renovacion);
        $this->assertSame(PersonalContrato::DECISION_NO_RENOVAR, $closed->decision_final);
        $this->assertTrue($closed->isHistoricalLocked());
        $this->assertDatabaseHas('personal_contratos', ['id' => $contract->id]);
        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'CESADO',
            'fecha_cese' => '2026-06-30',
        ]);
    }

    public function test_conserva_activo_si_tiene_otro_contrato_vigente_firmado(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $closing = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $this->insertContract($personal, '2026-07-01', '2026-12-31', true);
        $service = app(PersonalContratoService::class);
        $this->registerNoRenewal($service, $closing, $actor);

        $service->closeAsNotRenewed($closing, [
            'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
        ], $actor);

        $this->assertDatabaseHas('personal_contratos', [
            'id' => $closing->id,
            'estado' => PersonalContrato::ESTADO_NO_RENOVADO,
        ]);
        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_contrato_en_preparacion_sin_firma_no_mantiene_activo(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $closing = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $this->insertContract($personal, '2026-07-01', '2026-12-31', false, [
            'estado' => PersonalContrato::ESTADO_PREPARACION,
            'tipo_movimiento' => PersonalContrato::MOVIMIENTO_RENOVACION,
            'origen_contrato_id' => $closing->id,
        ]);
        $service = app(PersonalContratoService::class);
        $this->registerNoRenewal($service, $closing, $actor);

        $service->closeAsNotRenewed($closing, [
            'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
        ], $actor);

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'CESADO',
        ]);
    }

    public function test_cierre_anticipado_requiere_confirmacion_y_observacion(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);
        $this->registerNoRenewal($service, $contract, $actor);

        try {
            $service->closeAsNotRenewed($contract, [
                'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
            ], $actor);
            $this->fail('No debio cerrar anticipadamente sin confirmacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('El contrato aun no vence. Confirme si desea cerrar anticipadamente.', collect($exception->errors())->flatten()->first());
        }

        try {
            $service->closeAsNotRenewed($contract, [
                'confirmar_cierre_anticipado' => true,
                'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
            ], $actor);
            $this->fail('No debio cerrar anticipadamente sin observacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('La observacion es obligatoria para un cierre anticipado.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_bloquea_sin_decision_no_renovar_y_contrato_historico(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);

        try {
            $service->closeAsNotRenewed($contract, [
                'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
            ], $actor);
            $this->fail('No debio cerrar sin decision final de no renovar.');
        } catch (ValidationException $exception) {
            $this->assertSame('Primero registra la decision final de no renovar.', collect($exception->errors())->flatten()->first());
        }

        $closed = $this->insertContract($personal, '2025-01-01', '2025-06-30', true, [
            'estado' => PersonalContrato::ESTADO_CERRADO,
            'decision_final' => PersonalContrato::DECISION_NO_RENOVAR,
        ]);

        try {
            $service->closeAsNotRenewed($closed, [
                'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
            ], $actor);
            $this->fail('No debio cerrar dos veces un contrato historico.');
        } catch (ValidationException $exception) {
            $this->assertSame('Solo se puede cerrar un contrato activo como no renovado.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_motivo_otro_requiere_observacion(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $service = app(PersonalContratoService::class);
        $this->registerNoRenewal($service, $contract, $actor);

        try {
            $service->closeAsNotRenewed($contract, [
                'motivo_cese_controlado' => PersonalContrato::CESE_OTRO,
            ], $actor);
            $this->fail('No debio aceptar motivo otro sin observacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('La observacion es obligatoria cuando el motivo de cese es otro.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_ruta_respeta_permiso_actualizar(): void
    {
        Carbon::setTestNow('2026-07-05 09:00:00');

        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $actor = Usuario::query()->findOrFail($allowed);
        $personal = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($personal, '2026-01-01', '2026-06-30', true);
        $this->registerNoRenewal(app(PersonalContratoService::class), $contract, $actor);

        $payload = [
            'motivo_cese_controlado' => PersonalContrato::CESE_NO_RENOVACION_CONTRATO,
        ];

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.contratos.close-not-renewed', $contract->id), $payload)
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.contratos.close-not-renewed', $contract->id), $payload)
            ->assertRedirect(route('personal.contratos.expiring'));
    }

    public function test_no_se_agregan_nombres_propios_en_archivos_de_la_etapa(): void
    {
        $files = [
            app_path('Models/PersonalContrato.php'),
            app_path('Modules/Personal/Services/PersonalContratoService.php'),
            app_path('Modules/Personal/Controllers/PersonalContratoController.php'),
            resource_path('views/personal/contratos/vencimientos.blade.php'),
            database_path('migrations/2026_06_05_000800_add_not_renewed_closure_fields_to_personal_contratos.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', $content, $file);
        }
    }

    private function registerNoRenewal(PersonalContratoService $service, PersonalContrato $contract, Usuario $actor): void
    {
        $service->registerRenewalDecision($contract, [
            'estado_decision_renovacion' => PersonalContrato::DECISION_NO_RENOVAR,
            'motivo_no_renovacion' => PersonalContrato::MOTIVO_DECISION_AREA,
            'observacion_decision' => 'Decision registrada para cierre controlado.',
        ], $actor);
    }

    private function createPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(78000000, 78999999);

        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Cierre Contrato',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'cierre@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $inicio, string $fin, bool $signed, array $overrides = []): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/' . Str::uuid() . '.pdf';
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
            'cerrado_at' => in_array($estado, [PersonalContrato::ESTADO_CERRADO, PersonalContrato::ESTADO_CESADO, PersonalContrato::ESTADO_NO_RENOVADO], true) ? $fin . ' 18:00:00' : null,
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-firmado.pdf' : null,
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
            'nombre' => 'RRHH_CIERRE_' . Str::upper(Str::random(6)),
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
                'email' => 'cierre@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
