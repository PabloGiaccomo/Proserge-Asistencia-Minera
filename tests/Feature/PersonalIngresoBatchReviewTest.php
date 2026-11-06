<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalIngreso;
use App\Models\PersonalPuesto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalIngresoBatchReviewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_previews_and_accepts_exact_selection_with_resumable_per_row_results(): void
    {
        Storage::fake('local');
        $actor = $this->login(true);
        $first = $this->ingreso();
        $second = $this->ingreso();
        $second->forceFill(['estado' => PersonalIngreso::ESTADO_FALTA_REVISION])->save();
        $changed = $this->ingreso();
        $existing = $this->ingreso();
        Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => $existing->numero_documento,
            'nombre_completo' => 'Trabajador existente de prueba', 'puesto' => 'Operario',
            'qr_code' => (string) Str::uuid(), 'estado' => 'CESADO',
        ]);
        $items = collect([$first, $second, $changed, $existing])->map(fn ($ingreso) => [
            'ingreso_id' => $ingreso->id,
            'fecha_inicio_contrato' => now()->toDateString(),
            'fecha_fin_contrato' => now()->addMonth()->toDateString(),
        ])->all();
        $start = '/personal/ingresos/lotes';
        $this->postJson($start, ['filas' => [$items[0], $items[0]]])->assertUnprocessable();
        $lot = $this->postJson($start, ['filas' => $items])->assertCreated()
            ->assertJsonPath('filas.3.estado', 'REVISAR')
            ->assertJsonMissing(['numero_cuenta' => '9876543210'])
            ->assertDontSee('9876543210')->json();
        self::assertSame(PersonalIngreso::ESTADO_RECIBIDA, $first->fresh()->estado);

        $changed->forceFill(['datos_json' => array_replace($changed->datos_json, ['telefono' => '900000003'])])->save();
        $base = $start.'/'.$lot['id'];
        $this->getJson($base.'/filas/'.$lot['filas'][0]['id'].'/revision')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertJsonPath('proposito', 'ALTA');
        $this->getJson($base.'/filas/'.$lot['filas'][2]['id'].'/revision')->assertConflict();
        $this->get($base)->assertOk()->assertSee('Revisión de fichas seleccionadas');
        $payload = ['revision' => $lot['revision'], 'filas' => array_column(array_slice($lot['filas'], 0, 3), 'id'), 'revisado' => true];
        $this->postJson($base.'/confirmar', $payload)->assertOk()->assertJsonPath('resultados.0.estado', 'ACEPTADA')
            ->assertJsonPath('resultados.1.estado', 'ACEPTADA')->assertJsonPath('resultados.2.estado', 'REVISAR');
        $first->refresh();
        $this->assertDatabaseHas('personal_contratos', ['personal_id' => $first->personal_creado_id, 'estado' => 'PREPARACION']);
        self::assertSame(PersonalIngreso::ESTADO_RECIBIDA, $changed->fresh()->estado);
        self::assertSame(PersonalIngreso::ESTADO_RECIBIDA, $existing->fresh()->estado);
        $this->postJson($base.'/confirmar', $payload)->assertOk()->assertJsonPath('resultados.0.estado', 'ACEPTADA');
        self::assertSame(1, PersonalContrato::query()->where('personal_id', $first->personal_creado_id)->count());
        $this->getJson($base)->assertOk()->assertJsonPath('filas.1.estado', 'ACEPTADA');
        $this->get(route('personal.fichas.temporales'))->assertOk()
            ->assertSee('Mis revisiones recientes')->assertSee($lot['id']);
        $this->login(true);
        $this->getJson($base)->assertNotFound();
        $this->get(route('personal.fichas.temporales'))->assertOk()
            ->assertDontSee($lot['id']);
        $actor->rol->forceFill(['permisos' => PermissionCatalog::matrixFromSelections(['personal_ingresos' => ['ver']])])->save();
        $this->withSession(['user_id' => $actor->id, 'user' => ['id' => $actor->id]]);
        $this->postJson($base.'/confirmar', $payload)->assertForbidden();
    }

    public function test_sensitive_rows_and_duplicate_documents_require_individual_review(): void
    {
        Storage::fake('local');
        $this->login(false);
        $sensitive = $this->ingreso();
        $sensitive->forceFill(['datos_json' => array_replace($sensitive->datos_json, ['numero_cuenta' => '9876543210'])])->save();
        $duplicate = $this->ingreso($sensitive->numero_documento);
        $params = fn ($ingreso) => ['ingreso_id' => $ingreso->id, 'fecha_inicio_contrato' => now()->toDateString()];
        $this->postJson('/personal/ingresos/lotes', ['filas' => [$params($sensitive), $params($duplicate)]])
            ->assertCreated()->assertJsonPath('filas.0.estado', 'REVISAR')->assertJsonPath('filas.1.estado', 'REVISAR')
            ->assertDontSee('9876543210');
        $this->postJson('/personal/ingresos/lotes', ['filas' => [$params($sensitive)]])
            ->assertCreated()->assertJsonPath('filas.0.estado', 'REVISAR')->assertDontSee('9876543210');
    }

    public function test_one_batch_accepts_a_new_hire_and_a_general_update_without_a_new_contract_for_the_update(): void
    {
        Storage::fake('local');
        $this->login(false);
        $new = $this->ingreso();
        $worker = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => '70120931',
            'nombre_completo' => 'Trabajador con ficha de prueba', 'puesto' => 'Operario',
            'correo' => 'actual@example.test', 'qr_code' => (string) Str::uuid(), 'estado' => 'CESADO',
        ]);
        $issued = $this->postJson(route('personal.actualizacion.issue', $worker->id), ['requested_fields' => ['correo', 'familiares']])
            ->assertCreated()->json('url');
        $exchange = $this->postJson(route('personal.actualizacion.public.exchange'), ['token' => parse_url($issued, PHP_URL_FRAGMENT)])
            ->assertOk()->json();
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'revision' => $exchange['revision'], 'session_handle' => $exchange['session_handle'],
            'fields' => ['correo' => 'actualizado@example.test'],
            'familiares' => [[
                'key' => (string) Str::uuid(), 'nombres_apellidos' => 'Familiar de prueba',
                'parentesco' => 'Hijo', 'fecha_nacimiento' => today()->subYears(8)->toDateString(),
            ]],
        ])->assertOk()->json('revision');
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'revision' => $draft, 'session_handle' => $exchange['session_handle'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $update = PersonalIngreso::query()->where('personal_existente_id', $worker->id)->where('proposito', 'ACTUALIZACION')->firstOrFail();
        $lot = $this->postJson('/personal/ingresos/lotes', ['filas' => [
            ['ingreso_id' => $new->id, 'fecha_inicio_contrato' => now()->toDateString()],
            ['ingreso_id' => $update->id],
        ]])->assertCreated()->assertJsonPath('filas.1.estado', 'LISTA')
            ->assertJsonPath('filas.1.proposito', 'ACTUALIZACION')->assertDontSee('actualizado@example.test')->json();
        $base = '/personal/ingresos/lotes/'.$lot['id'];
        $this->getJson($base.'/filas/'.$lot['filas'][1]['id'].'/revision')->assertOk()
            ->assertJsonPath('campos.0.antes', 'actual@example.test')
            ->assertJsonPath('campos.0.propuesto', 'actualizado@example.test')
            ->assertJsonPath('familiares.0.campos.0.propuesto', 'Familiar de prueba');
        $this->postJson($base.'/confirmar', [
            'revision' => $lot['revision'], 'filas' => array_column($lot['filas'], 'id'), 'revisado' => true,
        ])->assertOk()->assertJsonPath('resultados.0.estado', 'ACEPTADA')->assertJsonPath('resultados.1.estado', 'ACEPTADA');
        self::assertSame('actualizado@example.test', $worker->fresh()->correo);
        self::assertSame('CESADO', $worker->fresh()->estado);
        $this->assertDatabaseMissing('personal_contratos', ['personal_id' => $worker->id]);
        self::assertSame('Familiar de prueba', $worker->fresh()->fichaColaborador->familiares->sole()->nombres_apellidos);
    }

    public function test_new_hire_families_are_reviewable_inside_the_owned_batch_before_acceptance(): void
    {
        Storage::fake('local');
        $this->login(false);
        $ingreso = $this->ingreso();
        $ingreso->forceFill(['familiares_json' => [[
            'nombres_apellidos' => 'FAMILIAR DEL ALTA DE PRUEBA', 'parentesco' => 'Madre',
            'fecha_nacimiento' => '1968-04-03', 'tipo_documento' => 'DNI', 'numero_documento' => '10010234',
            'telefono' => '999111234', 'contacto_emergencia' => true,
        ]]])->save();
        $lot = $this->postJson('/personal/ingresos/lotes', ['filas' => [[
            'ingreso_id' => $ingreso->id, 'fecha_inicio_contrato' => today()->toDateString(),
        ]]])->assertCreated()->assertDontSee('FAMILIAR DEL ALTA DE PRUEBA')->json();
        $details = '/personal/ingresos/lotes/'.$lot['id'].'/filas/'.$lot['filas'][0]['id'].'/revision';
        $this->getJson($details)->assertOk()->assertJsonPath('proposito', 'ALTA')
            ->assertJsonPath('familiares.0.campos.0.propuesto', 'FAMILIAR DEL ALTA DE PRUEBA')
            ->assertJsonPath('familiares.0.campos.8.propuesto', 'Sí');
        $this->login(false);
        $this->getJson($details)->assertNotFound()->assertDontSee('FAMILIAR DEL ALTA DE PRUEBA');
    }

    private function login(bool $sensitive): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'RRHH_LOTE_'.Str::random(8), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections([
                'personal' => ['editar'],
                'personal_ingresos' => $sensitive ? ['ver', 'aprobar', 'ver_datos_sensibles'] : ['ver', 'aprobar'],
            ]),
        ]);
        $actor = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'synthetic-password',
            'estado' => 'ACTIVO', 'rol_id' => $role->id,
        ]);
        $this->withSession(['auth_token' => 'test', 'user_id' => $actor->id, 'user' => ['id' => $actor->id]]);

        return $actor;
    }

    private function ingreso(?string $number = null): PersonalIngreso
    {
        $number ??= (string) random_int(10000000, 99999999);
        $puesto = PersonalPuesto::query()->firstOrCreate(['nombre' => 'OPERARIO LOTE'], [
            'id' => (string) Str::uuid(), 'activo' => true,
        ]);

        return PersonalIngreso::query()->create([
            'id' => (string) Str::uuid(), 'tipo_documento' => 'DNI', 'numero_documento' => $number,
            'estado' => PersonalIngreso::ESTADO_RECIBIDA, 'submitted_at' => now(),
            'datos_json' => [
                'tipo_documento' => 'DNI', 'numero_documento' => $number,
                'nombres' => 'Trabajador', 'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Sintética',
                'puesto' => $puesto->nombre, 'correo' => 'trabajador@example.test', 'telefono' => '900000001',
            ],
        ]);
    }
}
