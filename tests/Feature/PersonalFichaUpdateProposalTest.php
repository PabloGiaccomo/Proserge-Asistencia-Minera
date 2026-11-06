<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoRevision;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalIngresoRevisionService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalFichaUpdateProposalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_update_link_sends_a_separate_proposal_and_rrhh_applies_only_general_fields(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        $mineId = (string) Str::uuid();
        $assignmentId = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $mineId, 'nombre' => 'MINA-PRUEBA', 'unidad_minera' => 'MINA-PRUEBA', 'ubicacion' => 'Lima',
        ]);
        DB::table('personal_mina')->insert([
            'id' => $assignmentId, 'personal_id' => $personal->id, 'mina_id' => $mineId, 'estado' => 'HABILITADO',
        ]);
        $issued = $this->withSession(['auth_token' => 'synthetic', 'user_id' => $actor->id])
            ->postJson(route('personal.actualizacion.issue', $personal->id), [
                'requested_fields' => ['correo', 'telefono_alterno'],
            ])->assertCreated();
        $url = $issued->json('url');
        $token = parse_url($url, PHP_URL_FRAGMENT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertNull(parse_url($url, PHP_URL_QUERY));
        $this->assertStringNotContainsString($personal->dni, $url);
        $this->assertDatabaseHas('personal_ingreso_links', ['token_hash' => hash('sha256', $token)]);
        $this->get(route('personal.fichas.temporales'))->assertOk()->assertDontSee('Revisar actualización');

        $this->get(route('personal.actualizacion.public.show'))->assertOk()
            ->assertDontSee($token)->assertDontSee('registro@test.local');
        $exchange = $this->postJson(route('personal.actualizacion.public.exchange'), ['token' => $token])->assertOk();
        foreach (['registro@test.local', 'NUMERO-BANCO-PRUEBA', 'SIGNATURE-PRUEBA', 'MINA-PRUEBA'] as $value) {
            $exchange->assertDontSee($value);
        }
        $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange->json('session_handle'), 'revision' => $exchange->json('revision'),
            'fields' => ['numero_cuenta' => 'INTRUSION'],
        ])->assertUnprocessable();

        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange->json('session_handle'), 'revision' => $exchange->json('revision'),
            'fields' => ['correo' => 'propuesta@test.local', 'telefono_alterno' => null],
        ])->assertOk();
        $submission = [
            'session_handle' => $draft->json('session_handle'), 'revision' => $draft->json('revision'),
            'submission_uuid' => (string) Str::uuid(),
        ];
        $this->postJson(route('personal.actualizacion.public.submit'), $submission)->assertOk();
        $this->assertDatabaseHas('personal_ingreso_links', [
            'token_hash' => hash('sha256', $token),
            'personal_id' => $personal->id,
            'proposito' => 'ACTUALIZACION',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('personal_ingresos', [
            'personal_existente_id' => $personal->id,
            'proposito' => 'ACTUALIZACION',
            'submission_uuid' => $submission['submission_uuid'],
        ]);
        $retry = $this->postJson(route('personal.actualizacion.public.submit'), $submission);
        $this->assertSame(200, $retry->status(), $retry->json('message') ?? '');
        $this->postJson(route('personal.actualizacion.public.submit'), [
            ...$submission, 'submission_uuid' => (string) Str::uuid(),
        ])->assertConflict();
        $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $draft->json('session_handle'), 'revision' => $draft->json('revision'),
            'fields' => ['correo' => 'despues@test.local'],
        ])->assertForbidden();

        $this->assertSame('registro@test.local', $personal->fresh()->correo);
        $this->assertSame('registro@test.local', $ficha->fresh()->datos_json['correo']);
        $this->assertStringNotContainsString('propuesta@test.local', (string) DB::table('personal_ingreso_revisiones')->where('evento', 'ENVIADA')->value('snapshot'));
        $ingresoId = DB::table('personal_ingresos')->where('personal_existente_id', $personal->id)->value('id');
        $this->get(route('personal.fichas.temporales'))->assertOk()->assertSee('Completa para revisión');
        $this->get(route('personal.ingresos.actualizacion.show', $ingresoId))->assertOk()
            ->assertSee('propuesta@test.local')->assertDontSee('NUMERO-BANCO-PRUEBA');
        $this->postJson(route('personal.ingresos.accept', $ingresoId))->assertConflict();
        $this->putJson(route('personal.ingresos.update', $ingresoId), [
            'fields' => [
                'tipo_documento' => 'DNI', 'numero_documento' => $personal->dni, 'nombres' => 'Falso',
                'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Prueba', 'telefono' => '999111222',
                'correo' => 'alterado@test.local', 'puesto' => $personal->puesto,
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('ingreso');
        $service = app(PersonalIngresoRevisionService::class);
        $comparison = $service->compare($ingresoId, $actor);
        $this->assertSame(['before' => 'registro@test.local', 'after' => 'propuesta@test.local'], $comparison['changes']['correo']);
        $this->assertArrayNotHasKey('numero_cuenta', $comparison['changes']);

        $updated = $service->acceptUpdate($ingresoId, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
        $this->assertSame($personal->id, $updated->id);
        $this->assertSame('propuesta@test.local', $updated->correo);
        $this->assertSame('CESADO', $updated->estado);
        $this->assertSame('NUMERO-BANCO-PRUEBA', $ficha->fresh()->datos_json['numero_cuenta']);
        $this->assertSame('data:image/png;base64,SIGNATURE-PRUEBA', $ficha->fresh()->firma_base64);
        $this->assertSame('', $ficha->fresh()->datos_json['telefono_alterno']);
        $this->assertDatabaseMissing('personal_contratos', ['personal_id' => $personal->id]);
        $this->assertDatabaseHas('personal_mina', ['id' => $assignmentId, 'estado' => 'HABILITADO']);
        $this->assertDatabaseCount('personal_ingreso_revisiones', 4);

        $this->post(route('personal.ingresos.actualizacion.accept', $ingresoId), $comparison)
            ->assertRedirect(route('personal.ingresos.actualizacion.show', $ingresoId));
        $this->assertDatabaseCount('personal_ingreso_revisiones', 4);
    }

    public function test_changed_ficha_rejects_stale_approval_and_observation_keeps_snapshot(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        [$issued, $exchange] = $this->issueAndExchange($personal, $actor);
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'],
            'fields' => ['correo' => 'nuevo@test.local'],
        ])->assertOk();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft->json('session_handle'), 'revision' => $draft->json('revision'),
            'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->firstOrFail();
        $comparison = app(PersonalIngresoRevisionService::class)->compare($ingreso->id, $actor);
        $ficha->forceFill(['datos_json' => [...$ficha->datos_json, 'correo' => 'cambio-intermedio@test.local']])->save();

        $this->postJson(route('personal.ingresos.actualizacion.accept', $ingreso->id), $comparison)->assertConflict();
        $this->assertSame('registro@test.local', $personal->fresh()->correo);
        $this->assertSame('cambio-intermedio@test.local', $ficha->fresh()->datos_json['correo']);

        $this->post(route('personal.ingresos.actualizacion.observe', $ingreso->id), [
            'revision' => $comparison['revision'], 'reason' => 'Verificar cambio previo.',
        ])->assertRedirect(route('personal.ingresos.actualizacion.show', $ingreso->id));
        $this->assertDatabaseHas('personal_ingreso_revisiones', ['ingreso_id' => $ingreso->id, 'evento' => 'ENVIADA']);
        $this->assertDatabaseHas('personal_ingreso_revisiones', ['ingreso_id' => $ingreso->id, 'evento' => 'OBSERVADA', 'actor_id' => $actor->id]);
        $this->get(route('personal.ingresos.actualizacion.show', $ingreso->id))
            ->assertOk()->assertSee('Verificar cambio previo.')->assertSee('Observada');
    }

    public function test_general_update_captures_personal_education_sizes_and_adds_families_only_after_review(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        $ficha->forceFill(['datos_json' => [
            ...$ficha->datos_json,
            'nombres' => 'Anterior',
            'apellido_paterno' => 'Apellido',
            'apellido_materno' => 'Prueba',
            'profesion_oficio' => 'PROFESION CONSERVADA',
        ]])->save();
        $existingFamily = $ficha->familiares()->create([
            'id' => (string) Str::uuid(),
            'nombres_apellidos' => 'FAMILIAR VIGENTE PRIVADO',
            'parentesco' => 'Madre',
            'fecha_nacimiento' => '1968-04-03',
            'tipo_documento' => 'DNI',
            'numero_documento' => '10010234',
        ]);
        $issued = $this->withSession(['auth_token' => 'synthetic', 'user_id' => $actor->id])
            ->postJson(route('personal.actualizacion.issue', $personal->id), [
                'requested_fields' => ['nombres', 'fecha_nacimiento', 'estado_civil', 'grado_instruccion', 'profesion_oficio', 'talla_polo', 'familiares'],
            ])->assertCreated()->json();
        $exchange = $this->postJson(route('personal.actualizacion.public.exchange'), [
            'token' => parse_url($issued['url'], PHP_URL_FRAGMENT),
        ])->assertOk()->assertJsonPath('families_enabled', true)
            ->assertDontSee('FAMILIAR VIGENTE PRIVADO')->assertDontSee('NUMERO-BANCO-PRUEBA')->json();
        $family = [
            'key' => (string) Str::uuid(),
            'nombres_apellidos' => 'NUEVO FAMILIAR DE PRUEBA',
            'parentesco' => 'Hijo',
            'fecha_nacimiento' => '2018-05-03',
            'tipo_documento' => 'DNI',
            'numero_documento' => '10010235',
            'vive_con_trabajador' => true,
        ];
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'],
            'fields' => [
                'nombres' => 'Nombre nuevo', 'fecha_nacimiento' => '1994-11-05', 'estado_civil' => 'Otro',
                'grado_instruccion' => 'Tecnico egresado', 'talla_polo' => 'XL',
            ],
            'familiares' => [$family],
        ])->assertOk()->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'],
            'fields' => ['estado_civil_otro' => 'Situación declarada'],
        ])->assertOk()->assertJsonPath('familiares.0.key', $family['key'])->json();
        $this->assertSame('Anterior', $ficha->fresh()->datos_json['nombres']);
        $this->assertSame(1, $ficha->familiares()->count());
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->sole();
        $service = app(PersonalIngresoRevisionService::class);
        $comparison = $service->compare($ingreso->id, $actor);
        $this->assertSame('add', $comparison['family_changes'][0]['action']);
        $this->assertSame($family['key'], $comparison['family_changes'][0]['key']);
        $this->assertArrayNotHasKey('profesion_oficio', $comparison['changes']);
        $this->get(route('personal.ingresos.actualizacion.show', $ingreso->id))->assertOk()
            ->assertSee('NUEVO FAMILIAR DE PRUEBA')->assertDontSee('NUMERO-BANCO-PRUEBA');
        $updated = $service->acceptUpdate($ingreso->id, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
        $this->assertSame('APELLIDO PRUEBA NOMBRE NUEVO', $updated->nombre_completo);
        $this->assertSame('CESADO', $updated->estado);
        $this->assertSame('PROFESION CONSERVADA', $ficha->fresh()->datos_json['profesion_oficio']);
        $this->assertSame('XL', $ficha->fresh()->datos_json['talla_polo']);
        $this->assertSame(2, $ficha->familiares()->count());
        $this->assertSame('FAMILIAR VIGENTE PRIVADO', $existingFamily->fresh()->nombres_apellidos);
        $this->assertDatabaseHas('personal_ficha_familiares', [
            'personal_ficha_id' => $ficha->id, 'numero_documento' => '10010235', 'nombres_apellidos' => $family['nombres_apellidos'],
        ]);
        $accepted = PersonalIngresoRevision::query()->where('ingreso_id', $ingreso->id)->where('evento', 'ACEPTADA')->sole();
        $this->assertStringNotContainsString($family['nombres_apellidos'], $accepted->getRawOriginal('snapshot'));
    }

    public function test_family_proposals_detect_existing_documents_without_modifying_existing_relatives(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        $family = [
            'nombres_apellidos' => 'FAMILIAR REGISTRADO',
            'parentesco' => 'Madre',
            'fecha_nacimiento' => '1968-04-03',
            'tipo_documento' => 'DNI',
            'numero_documento' => '10010234',
            'telefono' => '999111234',
            'vive_con_trabajador' => false, 'estudia' => false, 'contacto_emergencia' => true,
        ];
        $existing = $ficha->familiares()->create(['id' => (string) Str::uuid(), ...$family]);
        [, $exchange] = $this->issueAndExchange($personal, $actor, ['familiares']);
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => [],
            'familiares' => [['key' => (string) Str::uuid(), ...$family]],
        ])->assertOk()->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $service = app(PersonalIngresoRevisionService::class);
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->sole();
        $comparison = $service->compare($ingreso->id, $actor);
        $this->assertSame('existing', $comparison['family_changes'][0]['action']);
        $this->assertFalse($comparison['conflict']);
        $service->acceptUpdate($ingreso->id, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
        $this->assertSame(1, $ficha->familiares()->count());

        [, $exchange] = $this->issueAndExchange($personal, $actor, ['familiares']);
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => [],
            'familiares' => [['key' => (string) Str::uuid(), ...$family, 'parentesco' => 'Padre', 'nombres_apellidos' => 'OTRA IDENTIDAD']],
        ])->assertOk()->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $other = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->where('estado', PersonalIngreso::ESTADO_RECIBIDA)->sole();
        $comparison = $service->compare($other->id, $actor);
        $this->assertSame('conflict', $comparison['family_changes'][0]['action']);
        $this->assertTrue($comparison['conflict']);
        $this->postJson(route('personal.ingresos.actualizacion.accept', $other->id), $comparison)->assertConflict();
        $this->assertSame('Madre', $existing->fresh()->parentesco);
        $this->assertSame('FAMILIAR REGISTRADO', $existing->fresh()->nombres_apellidos);
        $this->assertSame(1, $ficha->familiares()->count());
    }

    public function test_family_changes_between_issue_and_approval_require_a_new_review(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        [, $exchange] = $this->issueAndExchange($personal, $actor, ['familiares']);
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => [],
            'familiares' => [[
                'key' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR PROPUESTO',
                'parentesco' => 'Madre', 'fecha_nacimiento' => '1968-04-03', 'numero_documento' => '10010234',
            ]],
        ])->assertOk()->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->sole();
        $comparison = app(PersonalIngresoRevisionService::class)->compare($ingreso->id, $actor);
        $ficha->familiares()->create([
            'id' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR REGISTRADO POR RRHH',
            'parentesco' => 'Padre', 'fecha_nacimiento' => '1965-01-01',
        ]);
        $this->postJson(route('personal.ingresos.actualizacion.accept', $ingreso->id), $comparison)->assertConflict();
        $this->assertSame(1, $ficha->familiares()->count());
    }

    public function test_new_family_can_omit_unknown_birthdate_and_name_match_requires_internal_review(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        $existing = $ficha->familiares()->create([
            'id' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR SIN DOCUMENTO',
            'parentesco' => 'Contacto', 'fecha_nacimiento' => null,
        ]);
        [, $exchange] = $this->issueAndExchange($personal, $actor, ['familiares']);
        $base = ['session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => []];
        $family = ['key' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR NUEVO SIN FECHA', 'parentesco' => 'Contacto'];
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [...$base, 'familiares' => [$family]])
            ->assertOk()->assertJsonPath('familiares.0.fecha_nacimiento', '')->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->sole();
        $service = app(PersonalIngresoRevisionService::class);
        $comparison = $service->compare($ingreso->id, $actor);
        $service->acceptUpdate($ingreso->id, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
        $this->assertDatabaseHas('personal_ficha_familiares', [
            'personal_ficha_id' => $ficha->id, 'nombres_apellidos' => $family['nombres_apellidos'], 'fecha_nacimiento' => null,
        ]);

        [, $exchange] = $this->issueAndExchange($personal, $actor, ['familiares']);
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => [],
            'familiares' => [['key' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR SIN DOCUMENTO', 'parentesco' => 'Contacto']],
        ])->assertOk()->json();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $other = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->where('estado', PersonalIngreso::ESTADO_RECIBIDA)->sole();
        $comparison = $service->compare($other->id, $actor);
        $this->assertSame('conflict', $comparison['family_changes'][0]['action']);
        $this->assertTrue($comparison['conflict']);
        $this->postJson(route('personal.ingresos.actualizacion.accept', $other->id), $comparison)->assertConflict();
        $this->assertSame('FAMILIAR SIN DOCUMENTO', $existing->fresh()->nombres_apellidos);
        $this->assertSame(2, $ficha->familiares()->count());
    }

    public function test_general_update_rejects_invalid_dates_sizes_and_family_identity_commands(): void
    {
        $actor = $this->actor();
        [$personal] = $this->worker();
        [, $exchange] = $this->issueAndExchange($personal, $actor, ['fecha_nacimiento', 'talla_polo', 'familiares']);
        $base = ['session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision']];
        foreach ([
            ['fecha_nacimiento' => 'fecha inválida'],
            ['fecha_nacimiento' => now()->addDay()->toDateString()],
            ['talla_polo' => 'TALLA INVENTADA'],
            ['numero_documento' => '10010234'],
            ['puesto' => 'OTRO PUESTO'],
            ['banco' => 'BCP'],
        ] as $fields) {
            $this->postJson(route('personal.actualizacion.public.draft'), [...$base, 'fields' => $fields])->assertUnprocessable();
        }
        $this->postJson(route('personal.actualizacion.public.draft'), [
            ...$base, 'fields' => [], 'familiares' => [[
                'key' => (string) Str::uuid(), 'id' => (string) Str::uuid(), 'nombres_apellidos' => 'FAMILIAR',
                'parentesco' => 'Padre', 'fecha_nacimiento' => '1965-01-01',
            ]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_ingreso_revisiones', 1);
    }

    public function test_default_general_request_uses_general_requirements_and_optional_foreign_birthplace(): void
    {
        $actor = $this->actor();
        [$personal] = $this->worker();
        [, $exchange] = $this->issueAndExchange($personal, $actor, []);
        foreach (['nombres', 'fecha_nacimiento', 'correo', 'grado_instruccion', 'talla_polo'] as $key) {
            $this->assertArrayHasKey($key, $exchange['definitions']);
        }
        foreach (['tipo_documento', 'numero_documento', 'puesto', 'contrato', 'banco', 'cuspp', 'remuneracion', 'firma_base64'] as $key) {
            $this->assertArrayNotHasKey($key, $exchange['definitions']);
        }
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'],
            'fields' => [
                'nombres' => 'Nombre de prueba', 'apellido_paterno' => 'Apellido', 'apellido_materno' => 'Prueba',
                'sexo' => 'Masculino', 'estado_civil' => 'Soltero', 'nacionalidad' => 'Peruana',
                'fecha_nacimiento' => '1994-01-02', 'pais_nacimiento' => 'Otro', 'pais_nacimiento_otro' => 'Chile',
                'telefono' => '999111222', 'correo' => 'general@test.local',
                'domicilio_tipo' => 'Extranjero', 'domicilio_pais_otro' => 'Chile', 'domicilio_extranjero' => 'Dirección de prueba',
                'grado_instruccion' => 'Secundaria completa',
            ],
        ])->assertOk()->json();
        $this->assertTrue($draft['completeness']['complete']);
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft['session_handle'], 'revision' => $draft['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('registro@test.local', $personal->fresh()->correo);
        $this->assertDatabaseMissing('personal_contratos', ['personal_id' => $personal->id]);
    }

    public function test_previously_issued_contact_revision_remains_reviewable_after_general_expansion(): void
    {
        $actor = $this->actor();
        [$personal, $ficha] = $this->worker();
        $baseHash = hash('sha256', json_encode([
            $personal->id, 'DNI', '70010407', 'registro@test.local', null, null, null,
            $ficha->id, ['correo' => 'registro@test.local', 'telefono' => '', 'telefono_alterno' => '999876321'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $ingreso = PersonalIngreso::query()->create([
            'id' => (string) Str::uuid(), 'proposito' => 'ACTUALIZACION', 'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'personal_existente_id' => $personal->id, 'tipo_documento' => 'DNI', 'numero_documento' => $personal->dni,
            'revision' => 1, 'base_hash' => $baseHash, 'requested_fields_json' => ['correo'], 'submitted_at' => now(),
        ]);
        foreach (['BORRADOR', 'ENVIADA'] as $event) {
            PersonalIngresoRevision::query()->create([
                'id' => (string) Str::uuid(), 'ingreso_id' => $ingreso->id, 'revision' => 1, 'evento' => $event,
                'snapshot' => ['correo' => 'legado@test.local'], 'base_hash' => $baseHash, 'origen' => 'ENLACE',
            ]);
        }
        $service = app(PersonalIngresoRevisionService::class);
        $comparison = $service->compare($ingreso->id, $actor);
        $this->assertFalse($comparison['conflict']);
        $service->acceptUpdate($ingreso->id, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
        $this->assertSame('legado@test.local', $personal->fresh()->correo);
    }

    public function test_review_access_does_not_authorize_acceptance(): void
    {
        $actor = $this->actor();
        [$personal] = $this->worker();
        [, $exchange] = $this->issueAndExchange($personal, $actor);
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'submission_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $draft = $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'], 'fields' => ['correo' => 'propuesta@test.local'],
        ])->assertOk();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $draft->json('session_handle'), 'revision' => $draft->json('revision'), 'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $ingreso = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->firstOrFail();
        DB::table('roles')->where('id', $actor->rol_id)->update([
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['personal_ingresos' => ['ver']])),
        ]);
        $comparison = app(PersonalIngresoRevisionService::class)->compare($ingreso->id, $actor);
        $this->get(route('personal.ingresos.actualizacion.show', $ingreso->id))->assertOk()->assertDontSee('Aceptar actualización');
        $this->postJson(route('personal.ingresos.actualizacion.accept', $ingreso->id), $comparison)->assertForbidden();
        $this->assertSame('registro@test.local', $personal->fresh()->correo);
    }

    public function test_separate_tabs_keep_their_link_binding_and_expired_senders_cannot_write(): void
    {
        $actor = $this->actor();
        [$personal] = $this->worker();
        [, $first] = $this->issueAndExchange($personal, $actor);
        $second = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => '70010408', 'tipo_documento' => 'DNI', 'numero_documento' => '70010408',
            'nombre_completo' => 'OTRO TRABAJADOR DE PRUEBA', 'puesto' => 'OPERARIO PRUEBA', 'estado' => 'CESADO',
            'qr_code' => 'QR-'.Str::random(12), 'correo' => 'segundo@test.local',
        ]);
        [$issued, $otherTab] = $this->issueAndExchange($second, $actor);
        $this->postJson(route('personal.actualizacion.public.draft'), [
            'revision' => $otherTab['revision'],
            'fields' => ['correo' => 'sin-enlace@test.local'],
        ])->assertForbidden();

        $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $first['session_handle'],
            'revision' => $first['revision'],
            'fields' => ['correo' => 'primer-enlace@test.local'],
        ])->assertOk();
        $firstId = PersonalIngreso::query()->where('personal_existente_id', $personal->id)->sole()->id;
        $firstSaved = PersonalIngresoRevision::query()->where('ingreso_id', $firstId)->orderByDesc('revision')->firstOrFail();
        $this->assertSame('primer-enlace@test.local', $firstSaved->snapshot['correo'] ?? null);
        $this->postJson(route('personal.actualizacion.public.exchange'), [
            'session_handle' => $otherTab['session_handle'],
        ])->assertOk()->assertJsonPath('fields', []);
        $firstResume = $this->postJson(route('personal.actualizacion.public.exchange'), [
            'session_handle' => $first['session_handle'],
        ])->assertOk()->assertJsonPath('fields.correo', 'primer-enlace@test.local');

        $this->postJson(route('personal.actualizacion.revoke', $issued['link_id']))->assertOk();
        $this->postJson(route('personal.actualizacion.public.draft'), [
            'session_handle' => $otherTab['session_handle'],
            'revision' => $otherTab['revision'],
            'fields' => ['correo' => 'revocado@test.local'],
        ])->assertForbidden();
        $this->postJson(route('personal.actualizacion.public.submit'), [
            'session_handle' => $first['session_handle'],
            'revision' => $firstResume->json('revision'),
            'submission_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('segundo@test.local', $second->fresh()->correo);
        $this->assertDatabaseHas('personal_ingresos', [
            'personal_existente_id' => $personal->id, 'estado' => PersonalIngreso::ESTADO_RECIBIDA,
        ]);
        $this->assertDatabaseHas('personal_ingresos', [
            'personal_existente_id' => $second->id, 'estado' => PersonalIngreso::ESTADO_BORRADOR,
        ]);
    }

    #[DataProvider('invalidatedLinks')]
    public function test_update_link_is_rechecked_after_exchange(string $mode): void
    {
        Carbon::setTestNow('2026-11-04 13:00:00');
        try {
            $actor = $this->actor();
            [$personal] = $this->worker();
            [$issued, $exchange] = $this->issueAndExchange($personal, $actor);
            $linkId = $issued['link_id'];
            $token = parse_url($issued['url'], PHP_URL_FRAGMENT);
            $this->assertDatabaseHas('personal_ingreso_links', ['id' => $linkId, 'expires_at' => '2026-11-07 13:00:00']);
            if ($mode === 'revoked') {
                $this->postJson(route('personal.actualizacion.revoke', $linkId))->assertOk();
            } elseif ($mode === 'expired') {
                DB::table('personal_ingreso_links')->where('id', $linkId)->update(['expires_at' => now()->subSecond()]);
            } else {
                DB::table('personal_ingreso_links')->where('id', $linkId)->update(['proposito' => 'ALTA']);
            }
            $this->postJson(route('personal.actualizacion.public.draft'), [
                'session_handle' => $exchange['session_handle'], 'revision' => $exchange['revision'],
                'fields' => ['correo' => 'manipulado@test.local'],
            ])->assertForbidden();
            $this->postJson(route('personal.actualizacion.public.exchange'), ['token' => $token])->assertForbidden();
            $this->assertDatabaseCount('personal_ingreso_revisiones', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function invalidatedLinks(): array
    {
        return [['revoked'], ['expired'], ['other_purpose']];
    }

    private function issueAndExchange(Personal $personal, Usuario $actor, array $requested = ['correo']): array
    {
        $issued = $this->withSession(['auth_token' => 'synthetic', 'user_id' => $actor->id])
            ->postJson(route('personal.actualizacion.issue', $personal->id), ['requested_fields' => $requested])
            ->assertCreated()->json();
        $exchange = $this->postJson(route('personal.actualizacion.public.exchange'), [
            'token' => parse_url($issued['url'], PHP_URL_FRAGMENT),
        ])->assertOk()->json();

        return [$issued, $exchange];
    }

    private function worker(): array
    {
        PersonalPuesto::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'OPERARIO PRUEBA', 'activo' => true]);
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '70010407',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70010407',
            'nombre_completo' => 'FICHA PRUEBA ACTUALIZACION',
            'puesto' => 'OPERARIO PRUEBA',
            'contrato' => 'FIJO',
            'estado' => 'CESADO',
            'correo' => 'registro@test.local',
            'qr_code' => 'QR-'.Str::random(12),
        ]);
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => $personal->dni,
            'datos_json' => [
                'correo' => 'registro@test.local',
                'telefono_alterno' => '999876321',
                'numero_cuenta' => 'NUMERO-BANCO-PRUEBA',
            ],
            'firma_base64' => 'data:image/png;base64,SIGNATURE-PRUEBA',
        ]);

        return [$personal, $ficha];
    }

    private function actor(): Usuario
    {
        $id = (string) Str::uuid();
        $role = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $role,
            'nombre' => 'ROL ACTUALIZACION PRUEBA',
            'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'personal' => ['ver', 'editar'],
                'personal_ingresos' => ['ver', 'editar', 'actualizar', 'aprobar'],
            ])),
        ]);
        DB::table('usuarios')->insert([
            'id' => $id,
            'rol_id' => $role,
            'email' => Str::random(12).'@test.local',
            'password' => 'synthetic',
            'estado' => 'ACTIVO',
        ]);

        return Usuario::query()->findOrFail($id);
    }
}
