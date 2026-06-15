<?php

namespace Tests\Feature;

use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMina;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalMinaHabilitacionBaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_crea_requisito_para_mina_y_bloquea_duplicado_activo(): void
    {
        $mine = $this->createMine();
        $service = app(PersonalMinaHabilitacionService::class);

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'nombre' => 'Examen medico',
            'tipo' => 'Medico',
            'obligatorio' => true,
            'critico' => true,
            'reprogramable' => false,
            'vigencia_dias' => 365,
        ]);

        $this->assertSame($mine->id, $requirement->mina_id);
        $this->assertTrue($requirement->activo);
        $this->assertTrue($requirement->obligatorio);
        $this->assertTrue($requirement->critico);
        $this->assertFalse($requirement->reprogramable);

        try {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'nombre' => ' examen medico ',
            ]);
            $this->fail('No debio crear requisito activo duplicado.');
        } catch (ValidationException $exception) {
            $this->assertSame('Ya existe un requisito activo con ese nombre para esta mina.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_asigna_trabajador_a_mina_en_proceso_y_evitar_duplicado_activo(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('ACTIVO');
        $service = app(PersonalMinaHabilitacionService::class);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado);
        $this->assertTrue($assignment->activo);
        $this->assertDatabaseHas('personal_mina_historial', [
            'personal_mina_id' => $assignment->id,
            'estado_nuevo' => PersonalMina::ESTADO_EN_PROCESO,
        ]);

        try {
            $service->assignMine([
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ], $actor);
            $this->fail('No debio crear asignacion activa duplicada.');
        } catch (ValidationException $exception) {
            $this->assertSame('El trabajador ya tiene una asignacion activa para esta mina.', collect($exception->errors())->flatten()->first());
        }
    }

    public function test_cambiar_estado_no_modifica_estado_laboral_ni_contratos(): void
    {
        Carbon::setTestNow('2026-06-06 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('ACTIVO');
        $contract = $this->insertContract($worker, '2026-01-01', '2026-12-31', true);
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        try {
            $service->updateAssignment($assignment, [
                'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
                'observacion' => 'Habilitacion manual base.',
            ], $actor);
            $this->fail('No debio habilitar sin examenes generados y resueltos.');
        } catch (ValidationException $exception) {
            $this->assertSame('No se puede marcar como habilitado hasta que tenga examenes generados y todos los requisitos esten resueltos.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame('ACTIVO', $worker->fresh()->estado);
        $this->assertDatabaseHas('personal_contratos', [
            'id' => $contract->id,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'signed_contract_path' => $contract->signed_contract_path,
        ]);

        $service->updateAssignment($assignment->fresh(), [
            'estado_habilitacion' => PersonalMina::ESTADO_NO_HABILITADO,
            'observacion' => 'No habilitado manual.',
        ], $actor);

        $this->assertSame('ACTIVO', $worker->fresh()->estado);
        $this->assertSame(PersonalContrato::ESTADO_ACTIVO, $contract->fresh()->estado);
    }

    public function test_trabajador_cesado_requiere_confirmacion_para_marcar_habilitado(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('CESADO');
        $service = app(PersonalMinaHabilitacionService::class);

        try {
            $service->assignMine([
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            ], $actor);
            $this->fail('No debio habilitar trabajador cesado sin confirmacion.');
        } catch (ValidationException $exception) {
            $this->assertSame('El trabajador esta cesado. Confirma antes de marcarlo como habilitado.', collect($exception->errors())->flatten()->first());
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'confirmar_trabajador_cesado' => true,
        ], $actor);

        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertSame('CESADO', $worker->fresh()->estado);
    }

    public function test_trabajador_sin_contrato_firmado_muestra_advertencia_pero_no_bloquea(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('FALTA_CONTRATO');
        $service = app(PersonalMinaHabilitacionService::class);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->assertContains('Sin contrato vigente firmado', $service->warningsFor($assignment));
        $this->assertSame('FALTA_CONTRATO', $worker->fresh()->estado);
    }

    public function test_filtros_por_mina_y_estado_habilitacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $targetMine = $this->createMine('Mina filtro uno');
        $otherMine = $this->createMine('Mina filtro dos');
        $worker = $this->createPersonal('ACTIVO');
        $otherWorker = $this->createPersonal('ACTIVO');
        $service = app(PersonalMinaHabilitacionService::class);
        $target = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_OBSERVADO,
        ], $actor);
        $service->assignMine([
            'personal_id' => $otherWorker->id,
            'mina_id' => $otherMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $result = $service->listAssignments([
            'mina_id' => $targetMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($target->id, $result->first()->id);
    }

    public function test_rutas_respetan_permiso_actualizar(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);
        $allowed = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $mine = $this->createMine();
        $worker = $this->createPersonal('ACTIVO');

        $this->withSession($this->sessionFor($denied))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk();

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.habilitacion-minera.assign'), [
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($allowed))
            ->post(route('personal.habilitacion-minera.assign'), [
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertRedirect(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'worker_id' => $worker->id]));
    }

    public function test_desactivar_asignacion_no_elimina_historial(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $mine = $this->createMine();
        $worker = $this->createPersonal('ACTIVO');
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $service->deactivateAssignment($assignment, $actor, 'Ya no corresponde a esta mina.');

        $this->assertDatabaseHas('personal_mina', [
            'id' => $assignment->id,
            'activo' => false,
        ]);
        $this->assertDatabaseHas('personal_mina_historial', [
            'personal_mina_id' => $assignment->id,
        ]);
        $this->assertCount(0, $worker->fresh('minas')->minas);
    }

    public function test_no_se_agregan_nombres_propios_en_archivos_de_la_etapa(): void
    {
        $files = [
            app_path('Models/MinaRequisito.php'),
            app_path('Models/PersonalMina.php'),
            app_path('Models/PersonalMinaHistorial.php'),
            app_path('Modules/Personal/Services/PersonalMinaHabilitacionService.php'),
            app_path('Modules/Personal/Controllers/PersonalMinaHabilitacionController.php'),
            resource_path('views/personal/habilitacion-minera/index.blade.php'),
            database_path('migrations/2026_06_06_000100_create_mina_requisitos_and_extend_personal_mina.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', $content, $file);
        }
    }

    private function createMine(string $name = 'Mina habilitacion'): Mina
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name . ' ' . Str::upper(Str::random(4)),
            'unidad_minera' => $name,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Mina::query()->findOrFail($id);
    }

    private function createPersonal(string $estado, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(79000000, 79999999);

        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'Habilitacion Minera',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-01',
            'estado' => $estado,
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'habilitacion@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function insertContract(Personal $personal, string $inicio, string $fin, bool $signed): PersonalContrato
    {
        $path = null;
        if ($signed) {
            $path = 'personal_contratos/' . $personal->id . '/' . Str::uuid() . '.pdf';
            Storage::disk('local')->put($path, 'contrato');
        }

        $id = (string) Str::uuid();

        DB::table('personal_contratos')->insert([
            'id' => $id,
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'puesto' => $personal->puesto,
            'tipo_contrato' => 'FIJO',
            'activado_at' => $inicio . ' 08:00:00',
            'signed_at' => $signed ? $inicio . ' 08:00:00' : null,
            'signed_contract_path' => $path,
            'signed_contract_original_name' => $signed ? 'contrato-firmado.pdf' : null,
            'signed_contract_mime' => $signed ? 'application/pdf' : null,
            'signed_contract_size' => $signed ? 8 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PersonalContrato::query()->findOrFail($id);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_HABILITACION_' . Str::upper(Str::random(6)),
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
                'email' => 'habilitacion@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
