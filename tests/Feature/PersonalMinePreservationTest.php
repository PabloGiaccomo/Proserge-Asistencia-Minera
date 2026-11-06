<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Models\PersonalIngreso;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalIngresoService;
use App\Modules\Personal\Services\PersonalService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalMinePreservationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_web_can_remove_an_office_and_add_a_workshop_without_changing_operational_mines(): void
    {
        [$personal, $fields, $history] = $this->workerWithHistory();
        $actor = $this->actor();
        $officeId = (string) Str::uuid();
        $office = 'Oficina prueba historial';
        $workshop = 'Taller prueba historial';
        $locationRelationId = (string) Str::uuid();
        DB::table('oficinas')->insert(['id' => (string) Str::uuid(), 'nombre' => $office, 'ubicacion' => 'Lima']);
        DB::table('talleres')->insert(['id' => (string) Str::uuid(), 'nombre' => $workshop, 'ubicacion' => 'Lima']);
        DB::table('minas')->insert(['id' => $officeId, 'nombre' => $office, 'unidad_minera' => $office, 'ubicacion' => 'Lima']);
        DB::table('personal_mina')->insert([
            'id' => $locationRelationId,
            'personal_id' => $personal->id,
            'mina_id' => $officeId,
            'estado' => 'HABILITADO',
            'activo' => true,
        ]);

        $this->withSession(['auth_token' => 'fixture-token', 'user_id' => $actor->id]);
        $this->get(route('personal.edit', $personal->id))->assertOk()->assertDontSee('name="minas[]"', false);
        $url = route('personal.update', $personal->id);
        $payload = [
            'estado' => $personal->estado,
            'fields' => [...$fields, 'telefono' => '999111222', 'correo' => 'ubicacion@test.local'],
        ];
        $this->put($url, $payload)->assertRedirect(route('personal.show', $personal->id));
        $this->assertDatabaseHas('personal_mina', ['id' => $locationRelationId, 'activo' => true]);

        $this->putJson($url, [...$payload, 'ubicaciones_internas_enviadas' => '1', 'ubicaciones_internas' => ['MINA HISTORIAL PRUEBA']])
            ->assertUnprocessable();
        $this->assertDatabaseHas('personal_mina', ['id' => $locationRelationId, 'activo' => true]);

        $this->put($url, [...$payload, 'ubicaciones_internas_enviadas' => '1', 'ubicaciones_internas' => [$workshop]])
            ->assertRedirect(route('personal.show', $personal->id));
        $this->assertDatabaseHas('personal_mina', ['id' => $locationRelationId, 'activo' => false, 'usuario_actualizacion_id' => $actor->id]);
        $this->assertDatabaseHas('personal_mina_historial', ['personal_mina_id' => $locationRelationId, 'usuario_id' => $actor->id]);
        $this->assertContains($workshop, $personal->fresh()->minas->pluck('nombre')->all());
        $this->assertMiningHistory($history);
    }

    public function test_api_rejects_explicit_mine_orders_and_preserves_history_on_general_put(): void
    {
        [$personal, $fields, $history] = $this->workerWithHistory();
        $actor = $this->actor();
        $token = Str::random(80);
        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);
        $this->withToken($token);
        $url = '/api/v1/personal/'.$personal->id;
        $payload = [
            'dni' => $personal->dni,
            'nombre_completo' => 'NOMBRE ACTUALIZADO',
            'puesto' => $fields['puesto'],
            'contrato' => 'FIJO',
        ];

        $this->putJson($url, [...$payload, 'minas' => []])->assertUnprocessable();
        $this->putJson($url, $payload)->assertOk();
        $this->assertSame('NOMBRE ACTUALIZADO', $personal->fresh()->nombre_completo);
        $this->assertMiningHistory($history);
    }

    public function test_absent_and_explicit_empty_mines_never_delete_assignments(): void
    {
        [$personal, $fields, $history] = $this->workerWithHistory();
        $service = app(PersonalService::class);
        $payload = [
            'nombre_completo' => 'Nombre actualizado',
            'puesto' => $fields['puesto'],
            'contrato' => 'FIJO',
        ];

        $service->update($personal, $payload);
        $this->assertMiningHistory($history);

        foreach ([[], null, [['mina_id' => $history['personal_mina']['mina_id'], 'estado' => 'NO_HABILITADO']]] as $mines) {
            try {
                $service->update($personal, [...$payload, 'minas' => $mines]);
                $this->fail('La edición general aceptó una orden minera.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('minas', $exception->errors());
            }

            $this->assertMiningHistory($history);
        }
    }

    #[DataProvider('ingresoActions')]
    public function test_ingreso_accept_and_contract_not_signed_preserve_mining_history(bool $signed): void
    {
        [$personal, $fields, $history] = $this->workerWithHistory();
        $actor = $this->actor();
        $ingreso = PersonalIngreso::query()->create([
            'id' => (string) Str::uuid(),
            'personal_existente_id' => $personal->id,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $personal->dni,
            'datos_json' => $fields,
            'submitted_at' => now(),
        ]);

        $service = app(PersonalIngresoService::class);
        $updated = $signed
            ? $service->accept($ingreso, $actor, ['fecha_inicio_contrato' => '2026-11-01', 'fecha_fin_contrato' => '2026-12-31'])
            : $service->markContractNotSigned($ingreso, $actor);

        $this->assertSame($personal->id, $updated->id);
        $this->assertMiningHistory($history);
    }

    public static function ingresoActions(): array
    {
        return ['aceptar' => [true], 'no firmó' => [false]];
    }

    public function test_temporal_approval_and_manual_update_preserve_mining_history(): void
    {
        [$personal, $fields, $history] = $this->workerWithHistory();
        $actor = $this->actor();
        $service = app(PersonalFichaService::class);
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'tipo_documento' => 'DNI',
            'numero_documento' => $personal->dni,
            'datos_json' => $fields,
            'submitted_at' => now(),
        ]);

        $service->approve($ficha, $actor, null, ['fecha_ingreso' => '2026-11-01', 'fecha_fin_contrato' => '2026-12-31']);
        $this->assertMiningHistory($history);

        $service->updateManual($personal->fresh(), [...$fields, 'correo' => 'cambio@test.local'], [], $actor);
        $this->assertMiningHistory($history);
    }

    private function workerWithHistory(): array
    {
        Storage::fake('local');
        $fields = [
            'tipo_documento' => 'DNI',
            'numero_documento' => '70010234',
            'nombres' => 'Trabajador',
            'apellido_paterno' => 'Prueba',
            'apellido_materno' => 'Historial',
            'puesto' => 'OPERARIO HISTORIAL MINERO',
            'contrato' => 'FIJO',
            'fecha_ingreso' => '2026-11-01',
            'fecha_fin_contrato' => '2026-12-31',
        ];
        DB::table('personal_puestos')->insert(['id' => (string) Str::uuid(), 'nombre' => $fields['puesto'], 'activo' => true]);
        $personal = app(PersonalService::class)->create([
            'dni' => $fields['numero_documento'],
            'nombre_completo' => 'Prueba Historial Trabajador',
            'puesto' => $fields['puesto'],
            'contrato' => 'FIJO',
        ]);
        $mineId = (string) Str::uuid();
        $examId = (string) Str::uuid();
        $assignmentId = (string) Str::uuid();
        $workerExamId = (string) Str::uuid();
        $path = 'tests/mining-history/evidence.pdf';
        Storage::disk('local')->put($path, 'Evidencia minera sintética');
        DB::table('minas')->insert([
            'id' => $mineId,
            'nombre' => 'MINA HISTORIAL PRUEBA',
            'unidad_minera' => 'MINA HISTORIAL PRUEBA',
            'ubicacion' => 'Perú',
        ]);
        DB::table('examenes_mineros')->insert(['id' => $examId, 'nombre' => 'CURSO HISTORIAL PRUEBA']);
        $history = [
            'personal_mina' => [
                'id' => $assignmentId,
                'personal_id' => $personal->id,
                'mina_id' => $mineId,
                'estado' => 'HABILITADO',
                'estado_habilitacion' => 'HABILITADO',
                'activo' => true,
            ],
            'personal_mina_historial' => [
                'id' => (string) Str::uuid(),
                'personal_mina_id' => $assignmentId,
                'estado_anterior' => 'EN_PROCESO',
                'estado_nuevo' => 'HABILITADO',
            ],
            'personal_mina_examenes' => [
                'id' => $workerExamId,
                'personal_mina_id' => $assignmentId,
                'examen_id' => $examId,
                'nombre_snapshot' => 'CURSO HISTORIAL PRUEBA',
                'precio_snapshot' => '92.50',
                'estado' => 'APROBADO',
                'resultado' => 'APROBADO',
            ],
            'personal_mina_examen_intentos' => [
                'id' => (string) Str::uuid(),
                'personal_mina_examen_id' => $workerExamId,
                'numero_intento' => 1,
                'resultado' => 'APROBADO',
                'archivo_path' => $path,
            ],
        ];
        foreach ($history as $table => $record) {
            DB::table($table)->insert($record);
        }

        return [$personal, $fields, $history];
    }

    private function assertMiningHistory(array $history): void
    {
        foreach ($history as $table => $record) {
            $this->assertDatabaseHas($table, $record);
        }
        $this->assertSame('Evidencia minera sintética', Storage::disk('local')->get($history['personal_mina_examen_intentos']['archivo_path']));
    }

    private function actor(): Usuario
    {
        $id = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'ROL HISTORIAL PRUEBA',
            'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['personal' => ['ver', 'editar', 'actualizar']])),
        ]);
        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::random(12).'@test.local',
            'password' => 'synthetic',
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
        ]);

        return Usuario::query()->findOrFail($id);
    }
}
