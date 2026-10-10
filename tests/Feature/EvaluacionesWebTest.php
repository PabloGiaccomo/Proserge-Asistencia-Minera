<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluacionesWebTest extends TestCase
{
    use DatabaseTransactions;

    public function test_formulario_directo_muestra_minera_activa_sin_scope_y_busca_cualquier_persona(): void
    {
        [$userId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        DB::table('usuarios')->where('id', $userId)->update(['personal_id' => null]);
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA DIRECTA FUERA DE SCOPE', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);
        $worker = $this->createPersonal('PERSONA DIRECTA BUSCABLE');

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'desempeno']))
            ->assertOk()
            ->assertSee('Evaluación directa')
            ->assertSee('MINA DIRECTA FUERA DE SCOPE')
            ->assertSee('action="'.route('evaluaciones.desempeno.directa.store').'"', false);

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['tipo' => 'desempeno', 'q' => 'PERSONA DIRECTA BUSCABLE']))
            ->assertOk()->assertJsonPath('data.0.id', $worker);
    }

    public function test_formulario_directo_con_nota_invalida_conserva_seleccion_y_valores(): void
    {
        [$userId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        $worker = $this->createPersonal('PERSONA CON NOTA INVALIDA');
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA DIRECTA FORM', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);
        $url = route('evaluaciones.index', ['tipo' => 'desempeno']);
        $token = (string) Str::uuid();

        $this->withSession($this->sessionFor($userId))->from($url)
            ->post(route('evaluaciones.desempeno.directa.store'), [
                'trabajador_id' => $worker, 'mina_id' => $mineId, 'fecha' => '2026-10-05',
                'submission_key' => $token, 'desempeno_trabajo' => 5,
                'orden_limpieza' => 3, 'seguridad_trabajo' => 2,
                'compromiso' => 4, 'respuesta_emocional' => 1,
            ])->assertRedirect($url)->assertSessionHasErrors('desempeno_trabajo');

        $this->withSession($this->sessionFor($userId))->get($url)
            ->assertOk()->assertSee('value="'.$worker.'"', false)
            ->assertSee('value="'.$token.'"', false)
            ->assertSee('name="orden_limpieza" value="3" checked', false);
    }

    public function test_ruta_directa_rechaza_sin_permiso_y_exito_regresa_al_historial(): void
    {
        [$allowedId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        [$deniedId] = $this->createUser(['evaluaciones' => ['ver_desempeno']]);
        $worker = $this->createPersonal('PERSONA DIRECTA GUARDADA');
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA DIRECTA GUARDADA', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);
        $payload = [
            'trabajador_id' => $worker, 'mina_id' => $mineId, 'fecha' => '2026-10-05',
            'submission_key' => (string) Str::uuid(), 'desempeno_trabajo' => 4,
            'orden_limpieza' => 3, 'seguridad_trabajo' => 2,
            'compromiso' => 4, 'respuesta_emocional' => 1,
        ];

        $this->withSession($this->sessionFor($deniedId))
            ->post(route('evaluaciones.desempeno.directa.store'), $payload)->assertForbidden();
        $this->withSession($this->sessionFor($allowedId))
            ->post(route('evaluaciones.desempeno.directa.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-10-05']));
        $this->assertDatabaseHas('evaluacion_desempeno', ['trabajador_id' => $worker, 'origen' => 'DIRECTA']);
    }

    public function test_excepcion_directa_no_expone_evaluacion_de_asistencia_fuera_del_scope(): void
    {
        [$ownerId, $ownerPersonalId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        [$viewerId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno', 'ver_todas_evaluaciones']]);
        [, $detailId] = $this->createClosedAttendance($ownerId, $ownerPersonalId);
        $item = app(\App\Modules\Evaluaciones\Services\EvaluacionDesempenoService::class)->createDaily(
            \App\Models\Usuario::findOrFail($ownerId), [
                'asistencia_detalle_id' => $detailId,
                'desempeno_trabajo' => 3, 'orden_limpieza' => 3,
                'seguridad_trabajo' => 3, 'compromiso' => 3, 'respuesta_emocional' => 3,
            ],
        )['item'];
        $viewer = \App\Models\Usuario::findOrFail($viewerId);

        $this->assertFalse(app(\App\Modules\Evaluaciones\Services\EvaluacionesPageService::class)
            ->build($viewer, 'desempeno', '2026-08-21')['dailyHistory']->contains('id', $item->id));
        $this->assertNull(app(\App\Modules\Evaluaciones\Services\EvaluacionDesempenoService::class)->find($viewer, $item->id));
        $this->assertSame(0, app(\App\Modules\Evaluaciones\Services\EvaluacionesDashboardService::class)
            ->build($viewer, ['desde' => '2026-08-01', 'hasta' => '2026-08-31', 'mina' => '', 'cargo' => '', 'evaluacion_tipo' => 'desempeno'])['total']);
    }

    public function test_error_de_validacion_diaria_conserva_formulario_y_notas(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $url = route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21']);
        $session = $this->sessionFor($userId);

        $this->withSession($session)->from($url)->post(route('evaluaciones.desempeno.store'), [
            'asistencia_detalle_id' => $detailId,
            'desempeno_trabajo' => 4,
            'orden_limpieza' => 3,
            'seguridad_trabajo' => 2,
            'compromiso' => 1,
            'respuesta_emocional' => 4,
            'tuvo_incidencia' => '1',
            'descripcion_incidencia' => '',
            'observaciones' => 'Revisar herramientas',
        ])->assertRedirect($url)->assertSessionHasErrors('descripcion_incidencia');

        $response = $this->withSession($session)->get($url)->assertOk()
            ->assertSee('value="'.$detailId.'"', false)
            ->assertSee('name="desempeno_trabajo" value="4" checked', false)
            ->assertSee('Revisar herramientas')
            ->assertSee('aria-invalid="true"', false);

        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $directForm = $xpath->query('//form[contains(@action, "/evaluaciones/desempeno/directa")]')->item(0);
        $this->assertNotNull($directForm);
        $this->assertSame(0, $xpath->query('.//input[@name="orden_limpieza" and @checked]', $directForm)->length);
    }

    public function test_fallo_del_promedio_revierte_evaluacion_diaria(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['evaluar_desempeno']]);
        [, $detailId, $workerId] = $this->createClosedAttendance($userId, $personalId);
        $average = \Mockery::mock(\App\Modules\Evaluaciones\Services\PromedioDesempenoService::class);
        $average->shouldReceive('refreshForTrabajador')->once()->with($workerId)->andThrow(new \RuntimeException('average failed'));
        $this->app->instance(\App\Modules\Evaluaciones\Services\PromedioDesempenoService::class, $average);

        try {
            app(\App\Modules\Evaluaciones\Services\EvaluacionDesempenoService::class)->createDaily(
                \App\Models\Usuario::findOrFail($userId), [
                    'asistencia_detalle_id' => $detailId, 'desempeno_trabajo' => 3,
                    'orden_limpieza' => 3, 'seguridad_trabajo' => 3,
                    'compromiso' => 3, 'respuesta_emocional' => 3,
                ],
            );
            $this->fail('Se esperaba un error de recalculo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('average failed', $exception->getMessage());
        }

        $this->assertDatabaseMissing('evaluacion_desempeno', ['asistencia_detalle_id' => $detailId]);
        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $workerId]);
    }

    public function test_residente_requiere_capacidad_gerencial_explicita_independiente_del_nombre_del_rol(): void
    {
        [$operationsId] = $this->createUser(['evaluaciones' => ['evaluar_residentes']], 'OPERACIONES_EVAL');
        [$managerId, , $roleId] = $this->createUser([
            'evaluaciones' => ['evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');
        $policy = app(\App\Modules\Evaluaciones\Policies\EvaluacionesPolicy::class);

        $this->assertFalse($policy->canEvaluateType(\App\Models\Usuario::findOrFail($operationsId), 'residentes'));
        $this->assertTrue($policy->canEvaluateType(\App\Models\Usuario::findOrFail($managerId), 'residentes'));
        DB::table('roles')->where('id', $roleId)->update(['nombre' => 'COORDINACION_AUTORIZADA']);
        $this->assertTrue($policy->canEvaluateType(\App\Models\Usuario::findOrFail($managerId), 'residentes'));
    }

    public function test_residente_y_mes_se_validan_antes_de_mostrar_calificaciones(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');
        $residentId = $this->createPersonal('RESIDENTE A EVALUAR');
        DB::table('personal')->where('id', $residentId)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $base = route('evaluaciones.residentes');
        $session = $this->sessionFor($userId);

        $this->withSession($session)->get($base)->assertOk()->assertDontSee('Indicadores KPIs');
        $this->withSession($session)->get($base.'?'.http_build_query(['residente_id' => $residentId, 'periodo_mes' => '2026-09']))
            ->assertOk()->assertSee('Indicadores KPIs');
        $this->withSession($session)->get($base.'?'.http_build_query(['residente_id' => $residentId, 'periodo_mes' => '2026-13']))
            ->assertOk()->assertDontSee('Indicadores KPIs');

        DB::table('evaluacion_residente')->insert([
            'id' => (string) Str::uuid(), 'fecha' => '2026-09-01', 'periodo_mes' => '2026-09-01',
            'residente_id' => $residentId, 'evaluador_id' => null, 'total' => 15,
            'indicadores_kpi' => 1, 'costos_servicio' => 2, 'eventos_seguridad' => 4,
            'reportes_calidad' => 4, 'liderazgo_gestion' => 4, 'innovacion' => 0,
        ]);
        $this->withSession($session)->get($base.'?'.http_build_query(['residente_id' => $residentId, 'periodo_mes' => '2026-09']))
            ->assertOk()->assertSee('ya cuenta con una evaluación')->assertDontSee('Indicadores KPIs');
    }

    public function test_selector_residente_descarta_parametros_no_escalares(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.residentes', [
                'residente_id' => ['id'], 'periodo_mes' => ['2026-09'],
            ]))
            ->assertOk()
            ->assertDontSee('Indicadores KPIs');
    }

    public function test_residente_guarda_mes_normalizado_y_rechaza_duplicado_y_datos_manipulados(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');
        $residentId = $this->createPersonal('RESIDENTE VALIDO');
        $otherId = $this->createPersonal('TRABAJADOR NO RESIDENTE');
        DB::table('personal')->where('id', $residentId)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $selection = route('evaluaciones.residentes', ['residente_id' => $residentId, 'periodo_mes' => '2026-09']);
        $session = $this->sessionFor($userId);
        $payload = [
            'residente_id' => $residentId, 'periodo_mes' => '2026-09',
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA'],
            'costos_servicio_items' => ['COSTOS_MENSUALES'],
            'eventos_seguridad_respuesta' => 'SI', 'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4, 'comentarios' => 'Evaluación mensual.',
        ];

        $this->withSession($session)->from($selection)->post(route('evaluaciones.residentes.store'), [
            ...$payload, 'residente_id' => $otherId,
        ])->assertSessionHasErrors('evaluacion');
        $this->withSession($session)->from($selection)->post(route('evaluaciones.residentes.store'), [
            ...$payload, 'periodo_mes' => '2026-13',
        ])->assertSessionHasErrors('periodo_mes');
        $this->withSession($session)->from($selection)->post(route('evaluaciones.residentes.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('evaluaciones.index', ['tipo' => 'residentes']));
        $this->assertDatabaseHas('evaluacion_residente', [
            'residente_id' => $residentId, 'periodo_mes' => '2026-09-01',
        ]);
        $this->withSession($session)->from($selection)->post(route('evaluaciones.residentes.store'), $payload)
            ->assertSessionHasErrors('evaluacion');
        $this->assertSame(1, DB::table('evaluacion_residente')->where('residente_id', $residentId)->count());

        $existing = (array) DB::table('evaluacion_residente')->where('residente_id', $residentId)->first();
        try {
            DB::table('evaluacion_residente')->insert([...$existing, 'id' => (string) Str::uuid()]);
            $this->fail('El indice unico debio rechazar la segunda evaluacion.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
        }
    }

    public function test_error_del_formulario_residente_conserva_seleccion_y_respuestas(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');
        $residentId = $this->createPersonal('RESIDENTE PARA CORREGIR');
        DB::table('personal')->where('id', $residentId)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $url = route('evaluaciones.residentes', ['residente_id' => $residentId, 'periodo_mes' => '2026-09']);
        $session = $this->sessionFor($userId);

        $this->withSession($session)->from($url)->post(route('evaluaciones.residentes.store'), [
            'residente_id' => $residentId, 'periodo_mes' => '2026-09',
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA'],
            'costos_servicio_items' => ['COSTOS_MENSUALES'],
            'eventos_seguridad_respuesta' => 'SI', 'reportes_calidad_respuesta' => 'NO',
            'liderazgo_gestion_innovacion' => 3, 'comentarios' => '',
        ])->assertRedirect($url)->assertSessionHasErrors('comentarios');

        $this->withSession($session)->get($url)->assertOk()
            ->assertSee('Indicadores KPIs')
            ->assertSee('name="eventos_seguridad_respuesta" value="SI" checked', false)
            ->assertSee('name="liderazgo_gestion_innovacion" value="3" checked', false)
            ->assertSee('value="'.$residentId.'"', false);
    }

    public function test_solicitud_directa_de_residente_sin_capacidad_no_muestra_formulario(): void
    {
        [$userId] = $this->createUser(['evaluaciones' => ['ver_residentes', 'evaluar_residentes']], 'OPERACIONES_EVAL');
        $residentId = $this->createPersonal('RESIDENTE AJENO');
        DB::table('personal')->where('id', $residentId)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.residentes', ['residente_id' => $residentId, 'periodo_mes' => '2026-09']))
            ->assertForbidden();
    }

    public function test_colision_de_unicidad_residente_se_traduce_a_error_de_dominio(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'DIRECCION_EVAL');
        $residentId = $this->createPersonal('RESIDENTE EN CARRERA');
        DB::table('personal')->where('id', $residentId)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $model = \App\Models\EvaluacionResidente::class;
        $model::creating(function ($evaluation): void {
            DB::table('evaluacion_residente')->insert([
                ...$evaluation->getAttributes(),
                'id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $result = app(\App\Modules\Evaluaciones\Services\EvaluacionDesempenoService::class)->createResidente(
                \App\Models\Usuario::findOrFail($userId), [
                    'residente_id' => $residentId, 'fecha' => '2026-09-15',
                    'indicadores_kpi_items' => ['REPORTE_ASISTENCIA'],
                    'costos_servicio_items' => ['COSTOS_MENSUALES'],
                    'eventos_seguridad_respuesta' => 'SI',
                    'reportes_calidad_respuesta' => 'SI',
                    'liderazgo_gestion_innovacion' => 4,
                ],
            );
            $this->assertFalse($result['ok']);
            $this->assertSame('EVAL_RESIDENT_DUPLICATED', $result['code']);
            $this->assertSame(1, DB::table('evaluacion_residente')->where('residente_id', $residentId)->count());
        } finally {
            $model::flushEventListeners();
        }
    }

    public function test_migracion_no_altera_evaluaciones_residentes_duplicadas_historicas(): void
    {
        $residentId = $this->createPersonal('RESIDENTE HISTORICO');
        foreach (['2026-09-01', '2026-09-15'] as $date) {
            DB::table('evaluacion_residente')->insert([
                'id' => (string) Str::uuid(), 'fecha' => $date, 'periodo_mes' => $date,
                'residente_id' => $residentId, 'evaluador_id' => null, 'total' => 10,
                'indicadores_kpi' => 1, 'costos_servicio' => 2, 'eventos_seguridad' => 0,
                'reportes_calidad' => 0, 'liderazgo_gestion' => 4, 'innovacion' => 3,
            ]);
        }

        $migration = require database_path('migrations/2026_09_28_000100_enforce_unique_resident_evaluation_period.php');
        try {
            $migration->up();
            $this->fail('La migracion debio detenerse ante duplicados historicos.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicadas por mes', $exception->getMessage());
        }
        $this->assertSame(['2026-09-01', '2026-09-15'], DB::table('evaluacion_residente')
            ->where('residente_id', $residentId)->orderBy('periodo_mes')->pluck('periodo_mes')->all());
    }

    public function test_disponibilidad_diaria_se_calcula_antes_de_abrir_el_formulario(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['ver_desempeno', 'evaluar_desempeno']]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $session = $this->sessionFor($userId);

        $this->withSession($session)->get(route('evaluaciones.index', ['fecha' => '2026-08-21']))
            ->assertOk()->assertSee('Evaluar')->assertSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
        $this->withSession($session)->get(route('evaluaciones.desempeno.create', ['asistencia_detalle_id' => $detailId]))
            ->assertRedirect(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21', 'asistencia_detalle_id' => $detailId]));

        DB::table('asistencia_detalle')->where('id', $detailId)->update(['estado' => 'TARDANZA']);
        $this->withSession($session)->get(route('evaluaciones.index', ['fecha' => '2026-08-21']))
            ->assertOk()->assertSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
    }

    public function test_detalle_ya_evaluado_se_muestra_sin_formulario_y_rechaza_url_directa(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['ver_desempeno', 'evaluar_desempeno']]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $this->withSession($this->sessionFor($userId))->post(route('evaluaciones.desempeno.store'), [
            'asistencia_detalle_id' => $detailId, 'desempeno_trabajo' => 3, 'orden_limpieza' => 3,
            'seguridad_trabajo' => 3, 'compromiso' => 3, 'respuesta_emocional' => 3,
        ])->assertSessionHasNoErrors();

        $reopened = $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['fecha' => '2026-08-21']));
        $reopened->assertOk()->assertSee('Evaluado')->assertDontSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
        $this->assertSame('evaluated', $reopened->viewData('dailyPending')->first()['workers']->first()['status']);
        $this->withSession($this->sessionFor($userId))->get(route('evaluaciones.desempeno.create', ['asistencia_detalle_id' => $detailId]))
            ->assertStatus(409);

        DB::table('asistencia_encabezado')->where('id', DB::table('asistencia_detalle')->where('id', $detailId)->value('asistencia_id'))
            ->update(['estado' => 'REGISTRADO']);
        $reopened = $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['fecha' => '2026-08-21']));
        $reopened->assertOk()->assertDontSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
        $this->assertSame('evaluated', $reopened->viewData('dailyPending')->first()['workers']->first()['status']);
    }

    public function test_detalle_no_evaluable_no_ofrece_formulario(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['ver_desempeno', 'evaluar_desempeno']]);
        [$mineId, $detailId, $workerId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $session = $this->sessionFor($userId);
        $cases = [
            ['estado' => 'REGISTRADO', 'detail' => 'PRESENTE'],
            ['estado' => 'CERRADO', 'detail' => 'AUSENTE'],
            ['estado' => 'CERRADO', 'detail' => 'PRESENTE', 'self' => true],
        ];

        foreach ($cases as $case) {
            DB::table('asistencia_encabezado')->where('id', DB::table('asistencia_detalle')->where('id', $detailId)->value('asistencia_id'))
                ->update(['estado' => $case['estado']]);
            DB::table('asistencia_detalle')->where('id', $detailId)->update([
                'estado' => $case['detail'],
                'trabajador_id' => !empty($case['self']) ? $personalId : $workerId,
            ]);
            $this->withSession($session)->get(route('evaluaciones.index', ['fecha' => '2026-08-21']))
                ->assertOk()->assertSee('No evaluable')->assertDontSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
            $this->withSession($session)->get(route('evaluaciones.desempeno.create', ['asistencia_detalle_id' => $detailId]))
                ->assertStatus(422);
            $this->withSession($session)->get(route('evaluaciones.index', [
                'tipo' => 'desempeno', 'fecha' => '2026-08-21', 'asistencia_detalle_id' => $detailId,
            ]))->assertStatus(422);
        }
    }

    public function test_actor_ajeno_no_puede_abrir_evaluacion_por_url(): void
    {
        [$ownerId, $ownerPersonalId] = $this->createUser(['evaluaciones' => ['ver_desempeno', 'evaluar_desempeno']]);
        [$mineId, $detailId] = $this->createClosedAttendance($ownerId, $ownerPersonalId);
        [$otherId] = $this->createUser(['evaluaciones' => ['ver_desempeno', 'evaluar_desempeno']]);
        $this->assignScope($otherId, $mineId);

        $this->withSession($this->sessionFor($otherId))
            ->get(route('evaluaciones.desempeno.create', ['asistencia_detalle_id' => $detailId]))
            ->assertStatus(403);
    }

    public function test_permiso_de_solo_lectura_no_abre_formulario_diario(): void
    {
        [$userId, $personalId] = $this->createUser(['evaluaciones' => ['ver_desempeno']]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);

        $this->withSession($this->sessionFor($userId))->get(route('evaluaciones.index', ['fecha' => '2026-08-21']))
            ->assertOk()->assertSee('No evaluable')->assertDontSee('name="asistencia_detalle_id" value="'.$detailId.'"', false);
        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.desempeno.create', ['asistencia_detalle_id' => $detailId]))
            ->assertForbidden();
    }

    public function test_rol_solo_desempeno_entra_directo_sin_otras_pestanas(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['fecha' => '2026-08-21']));

        $response->assertOk()
            ->assertSee('Evaluaciones pendientes del día')
            ->assertDontSee('Evaluación realizada por residentes')
            ->assertDontSee('Seguimiento mensual');
    }

    public function test_encargado_registra_evaluacion_diaria_sobre_20(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId, $detailId, $workerId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'eval-csrf'])
            ->post(route('evaluaciones.desempeno.store'), [
                '_token' => 'eval-csrf',
                'asistencia_detalle_id' => $detailId,
                'desempeno_trabajo' => 4,
                'orden_limpieza' => 4,
                'seguridad_trabajo' => 4,
                'compromiso' => 4,
                'respuesta_emocional' => 4,
                'observaciones' => 'Buen trabajo diario',
            ])
            ->assertRedirect(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21']));

        $this->assertDatabaseHas('evaluacion_desempeno', [
            'asistencia_detalle_id' => $detailId,
            'trabajador_id' => $workerId,
            'supervisor_id' => $personalId,
            'evaluado_por_usuario_id' => $userId,
            'origen' => 'ASISTENCIA',
            'total' => 20,
            'tuvo_incidencia' => 0,
            'descripcion_incidencia' => null,
        ]);
    }

    public function test_no_permite_duplicar_evaluacion_de_la_misma_asistencia(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [$mineId, $detailId] = $this->createClosedAttendance($userId, $personalId);
        $this->assignScope($userId, $mineId);
        $payload = [
            '_token' => 'eval-csrf',
            'asistencia_detalle_id' => $detailId,
            'desempeno_trabajo' => 3,
            'orden_limpieza' => 3,
            'seguridad_trabajo' => 3,
            'compromiso' => 3,
            'respuesta_emocional' => 3,
        ];

        $session = [...$this->sessionFor($userId), '_token' => 'eval-csrf'];
        $this->withSession($session)->post(route('evaluaciones.desempeno.store'), $payload)->assertSessionHasNoErrors();
        $this->withSession($session)->from(route('evaluaciones.index'))->post(route('evaluaciones.desempeno.store'), $payload)
            ->assertSessionHasErrors('evaluacion');

        $this->assertSame(1, DB::table('evaluacion_desempeno')->where('asistencia_detalle_id', $detailId)->count());
    }

    public function test_catalogo_publica_permisos_separados(): void
    {
        $actions = PermissionCatalog::availableModuleActions()['evaluaciones'];

        foreach (['ver_todas_evaluaciones', 'ver_desempeno', 'evaluar_desempeno', 'ver_supervisores', 'evaluar_supervisores', 'ver_residentes', 'evaluar_residentes'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_permisos_genericos_no_habilitan_tipos_de_evaluacion_desmarcados(): void
    {
        $selected = [
            'evaluaciones' => [
                'ver',
                'ver_desempeno',
                'evaluar_desempeno',
                'ver_supervisores',
                'dashboards',
                'crear',
                'editar',
                'actualizar',
                'aprobar',
                'administrar',
            ],
        ];
        $permissions = PermissionCatalog::matrixFromSelections($selected);

        $this->assertTrue(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'ver_desempeno'));
        $this->assertTrue(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'evaluar_desempeno'));
        $this->assertTrue(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'ver_supervisores'));
        $this->assertFalse(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'ver_todas_evaluaciones'));
        $this->assertFalse(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'evaluar_supervisores'));
        $this->assertFalse(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'ver_residentes'));
        $this->assertFalse(PermissionMatrix::allowsDirect($permissions, 'evaluaciones', 'evaluar_residentes'));

        [$userId] = $this->createUser($selected);
        $session = [...$this->sessionFor($userId), '_token' => 'evaluation-role-csrf'];

        $this->withSession($session)
            ->get(route('evaluaciones.index', ['tipo' => 'supervisores']))
            ->assertOk()
            ->assertSee('Evaluación de supervisores')
            ->assertDontSee('Nueva evaluación de supervisor')
            ->assertDontSee('Seguimiento mensual');

        $this->withSession($session)
            ->get(route('evaluaciones.residentes'))
            ->assertForbidden();

        $this->withSession($session)
            ->post(route('evaluaciones.supervisores.store'), ['_token' => 'evaluation-role-csrf'])
            ->assertForbidden();
    }

    public function test_ver_evaluaciones_de_otros_controla_el_historial(): void
    {
        $basePermissions = [
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ];
        [$userId, $personalId, $roleId] = $this->createUser($basePermissions);
        [$otherUserId, $otherPersonalId] = $this->createUser($basePermissions);
        [$ownMineId, $ownDetailId, $ownWorkerId] = $this->createClosedAttendance($userId, $personalId);
        [$otherMineId, $otherDetailId, $otherWorkerId] = $this->createClosedAttendance($otherUserId, $otherPersonalId);
        DB::table('personal')->where('id', $ownWorkerId)->update(['nombre_completo' => 'TECNICO HISTORIAL PROPIO']);
        DB::table('personal')->where('id', $otherWorkerId)->update(['nombre_completo' => 'TECNICO HISTORIAL AJENO']);
        $this->assignScope($userId, $ownMineId);
        $this->assignScope($userId, $otherMineId);

        $payload = static fn (string $detailId, string $token): array => [
            '_token' => $token,
            'asistencia_detalle_id' => $detailId,
            'desempeno_trabajo' => 4,
            'orden_limpieza' => 4,
            'seguridad_trabajo' => 4,
            'compromiso' => 4,
            'respuesta_emocional' => 4,
        ];

        $this->withSession([...$this->sessionFor($userId), '_token' => 'own-evaluation-csrf'])
            ->post(route('evaluaciones.desempeno.store'), $payload($ownDetailId, 'own-evaluation-csrf'))
            ->assertSessionHasNoErrors();
        $this->withSession([...$this->sessionFor($otherUserId), '_token' => 'other-evaluation-csrf'])
            ->post(route('evaluaciones.desempeno.store'), $payload($otherDetailId, 'other-evaluation-csrf'))
            ->assertSessionHasNoErrors();

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21']))
            ->assertOk()
            ->assertSee('TECNICO HISTORIAL PROPIO')
            ->assertDontSee('TECNICO HISTORIAL AJENO');

        DB::table('roles')->where('id', $roleId)->update([
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno', 'ver_todas_evaluaciones'],
            ])),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21']))
            ->assertOk()
            ->assertSee('TECNICO HISTORIAL PROPIO')
            ->assertSee('TECNICO HISTORIAL AJENO');
    }

    public function test_administrador_puede_evaluar_una_asistencia_cerrada_por_otro_responsable(): void
    {
        [$adminUserId, $adminPersonalId] = $this->createUser([
            'evaluaciones' => [
                'ver_desempeno',
                'evaluar_desempeno',
                'ver_todas_evaluaciones',
                'administrar',
            ],
        ]);
        [$ownerUserId, $ownerPersonalId] = $this->createUser([
            'evaluaciones' => ['ver_desempeno', 'evaluar_desempeno'],
        ]);
        [, $detailId, $workerId] = $this->createClosedAttendance($ownerUserId, $ownerPersonalId);
        DB::table('personal')->where('id', $workerId)->update([
            'nombre_completo' => 'TECNICO PENDIENTE DE OTRO RESPONSABLE',
        ]);

        $session = [...$this->sessionFor($adminUserId), '_token' => 'admin-evaluation-csrf'];

        $this->withSession($session)
            ->get(route('evaluaciones.index', ['tipo' => 'desempeno', 'fecha' => '2026-08-21']))
            ->assertOk()
            ->assertSee('TECNICO PENDIENTE DE OTRO RESPONSABLE')
            ->assertSee('incluidas las registradas por otros responsables');

        $this->withSession($session)
            ->post(route('evaluaciones.desempeno.store'), [
                '_token' => 'admin-evaluation-csrf',
                'asistencia_detalle_id' => $detailId,
                'desempeno_trabajo' => 4,
                'orden_limpieza' => 4,
                'seguridad_trabajo' => 4,
                'compromiso' => 4,
                'respuesta_emocional' => 4,
                'observaciones' => 'Evaluación registrada por contingencia administrativa.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('evaluacion_desempeno', [
            'asistencia_detalle_id' => $detailId,
            'evaluado_por_usuario_id' => $adminUserId,
            'supervisor_id' => $adminPersonalId,
            'total' => 20,
        ]);
    }

    public function test_permisos_especificos_habilitan_supervisor_y_residente(): void
    {
        [$userId, $personalId] = $this->createUser([
            'evaluaciones' => [
                'ver_desempeno',
                'evaluar_desempeno',
                'ver_supervisores',
                'evaluar_supervisores',
                'ver_residentes',
                'evaluar_residentes',
                'administrar',
            ],
        ]);
        [$mineId] = $this->createClosedAttendance($userId, $personalId);
        $candidateId = $this->createPersonal('SUPERVISOR EVALUADO BUSCABLE', true);
        $residentWithoutMineId = $this->createPersonal('RESIDENTE BUSCABLE SIN MINA');
        $this->assignScope($userId, $mineId);
        DB::table('personal')->where('id', $residentWithoutMineId)->update(['estado' => 'CESADO', 'puesto' => 'RESIDENTE DE OPERACIONES']);

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'supervisores']))
            ->assertOk()
            ->assertSee('Nueva evaluación de supervisor')
            ->assertSee('Bloque A: Competencias Técnicas (Asociadas al Cargo)')
            ->assertSee('Bloque B: Desempeño en SSOMA')
            ->assertSee('Bloque C: Habilidades blandas')
            ->assertSee('Buscar por nombre, DNI o puesto');

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['tipo' => 'supervisores', 'mina_id' => $mineId, 'q' => 'SUPERVISOR EVALUADO']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $candidateId)
            ->assertJsonPath('data.0.nombre', 'SUPERVISOR EVALUADO BUSCABLE');

        $this->withSession($this->sessionFor($userId))
            ->get(route('evaluaciones.index', ['tipo' => 'residentes']))
            ->assertOk()
            ->assertSee('Buscar por nombre, DNI o puesto')
            ->assertDontSee('Unidad minera');

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['tipo' => 'residentes', 'q' => 'RESIDENTE BUSCABLE']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $residentWithoutMineId)
            ->assertJsonPath('data.0.estado', 'CESADO');

        $session = [...$this->sessionFor($userId), '_token' => 'eval-csrf'];
        $this->withSession($session)->post(route('evaluaciones.supervisores.store'), [
            '_token' => 'eval-csrf',
            'mina_id' => $mineId,
            'evaluado_id' => $candidateId,
            'fecha' => '2026-08-21',
            'respuestas' => array_fill_keys(array_keys(\App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate::WEIGHTS), 4),
        ])->assertRedirect(route('evaluaciones.index', ['tipo' => 'supervisores']));

        $this->withSession($session)->post(route('evaluaciones.residentes.store'), [
            '_token' => 'eval-csrf',
            'residente_id' => $residentWithoutMineId,
            'periodo_mes' => '2026-08',
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA', 'REPORTE_EVALUACION_DESEMPENO', 'ENTREGA_INFORMES', 'ENTREGA_PROTOCOLOS'],
            'costos_servicio_items' => ['COSTOS_MENSUALES', 'CURVA_S'],
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
            'comentarios' => 'Cumplimiento mensual completo.',
        ])->assertRedirect(route('evaluaciones.index', ['tipo' => 'residentes']));

        $this->assertDatabaseHas('evaluacion_supervisor', [
            'evaluador_id' => $personalId,
            'evaluado_id' => $candidateId,
        ]);
        $this->assertDatabaseHas('evaluacion_residente', [
            'evaluador_id' => $personalId,
            'residente_id' => $residentWithoutMineId,
            'periodo_mes' => '2026-08-01',
            'destino_tipo' => null,
            'destino_id' => null,
            'total' => 20,
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
        ]);
    }

    public function test_admin_sin_personal_evalua_supervisor_y_residente_sin_parada(): void
    {
        [$userId, , $roleId] = $this->createUser(['evaluaciones' => ['evaluar_supervisores', 'evaluar_residentes', 'administrar']]);
        DB::table('usuarios')->where('id', $userId)->update(['personal_id' => null]);
        $candidate = $this->createPersonal('SUPERVISOR SIN PARADA', true);
        $resident = $this->createPersonal('RESIDENTE SIN PARADA');
        DB::table('personal')->where('id', $resident)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $mine = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mine, 'nombre' => 'MINA EVAL SIN PARADA', 'unidad_minera' => 'UM PRUEBA', 'estado' => 'ACTIVO']);
        $email = DB::table('usuarios')->where('id', $userId)->value('email');
        foreach (['supervisores', 'residentes'] as $type) {
            $this->withSession($this->sessionFor($userId))->get(route('evaluaciones.index', ['tipo' => $type]))
                ->assertOk()->assertSee($email)->assertSee('está evaluando.');
        }
        $session = [...$this->sessionFor($userId), '_token' => 'eval-csrf'];
        $this->withSession($session)->post(route('evaluaciones.supervisores.store'), [
            '_token' => 'eval-csrf', 'mina_id' => $mine, 'evaluado_id' => $candidate,
            'evaluador_id' => $candidate, 'fecha' => '2026-09-16', 'respuestas' => array_fill_keys(array_keys(\App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate::WEIGHTS), 4),
        ])->assertSessionHasNoErrors()->assertRedirect(route('evaluaciones.index', ['tipo' => 'supervisores']));
        $this->withSession($session)->post(route('evaluaciones.residentes.store'), [
            '_token' => 'eval-csrf', 'residente_id' => $resident, 'periodo_mes' => '2026-09',
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA'], 'costos_servicio_items' => ['COSTOS_MENSUALES'],
            'eventos_seguridad_respuesta' => 'SI', 'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4, 'comentarios' => 'Revision mensual sin parada.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('evaluaciones.index', ['tipo' => 'residentes']));
        $this->assertDatabaseHas('evaluacion_supervisor', ['evaluado_id' => $candidate, 'evaluador_id' => null, 'created_by_usuario_id' => $userId, 'grupo_trabajo_id' => null]);
        $this->assertDatabaseHas('evaluacion_residente', ['residente_id' => $resident, 'evaluador_id' => null, 'created_by_usuario_id' => $userId, 'destino_id' => null]);
        [$otherId] = $this->createUser(['evaluaciones' => ['evaluar_supervisores', 'evaluar_residentes', 'administrar']]);
        DB::table('usuarios')->where('id', $otherId)->update(['personal_id' => null]);
        $service = app(\App\Modules\Evaluaciones\Services\EvaluacionesPageService::class);
        $other = \App\Models\Usuario::findOrFail($otherId);
        $data = $service->build($other, 'supervisores', '2026-09-16');
        $this->assertFalse($data['supervisorHistory']->contains('evaluado_id', $candidate));
        $this->assertFalse($data['residentHistory']->contains('residente_id', $resident));
    }

    public function test_permiso_de_lectura_no_permite_evaluar_aunque_sea_administrador(): void
    {
        [$userId] = $this->createUser(['evaluaciones' => ['ver_supervisores', 'administrar']]);
        DB::table('usuarios')->where('id', $userId)->update(['personal_id' => null]);
        $result = app(\App\Modules\Evaluaciones\Services\EvaluacionSupervisorService::class)->create(
            \App\Models\Usuario::findOrFail($userId), ['destino_tipo' => 'MINA', 'destino_id' => (string) Str::uuid()]);
        $this->assertFalse($result['ok']);
        $this->assertSame('EVAL_FORBIDDEN', $result['code']);
    }

    public function test_permiso_explicito_habilita_evaluar_supervisores_sin_rol_residente(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_supervisores', 'evaluar_supervisores'],
        ], 'OPERACIONES_EVALUACIONES_TEST', 'COORDINADOR DE OPERACIONES');

        $actor = \App\Models\Usuario::findOrFail($userId);

        $this->assertTrue(app(\App\Modules\Evaluaciones\Policies\EvaluacionesPolicy::class)
            ->canEvaluateType($actor, 'supervisores'));
    }

    public function test_permiso_permite_evaluar_supervisor_sin_vinculo_con_su_mina(): void
    {
        [$userId] = $this->createUser([
            'evaluaciones' => ['ver_supervisores', 'evaluar_supervisores'],
        ], 'OPERACIONES_RESIDENTE_TEST', 'RESIDENTE DE OPERACIONES');
        $mineId = (string) Str::uuid();
        $otherMineId = (string) Str::uuid();
        DB::table('minas')->insert([
            ['id' => $mineId, 'nombre' => 'MINA DEL RESIDENTE', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO'],
            ['id' => $otherMineId, 'nombre' => 'MINA AJENA', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO'],
        ]);
        $supervisor = $this->createPersonal('SUPERVISOR DEL AMBITO', true);
        $outsideSupervisor = $this->createPersonal('SUPERVISOR FUERA DEL AMBITO', true);
        foreach ([[$supervisor, $mineId], [$outsideSupervisor, $otherMineId]] as [$personalId, $personalMineId]) {
            DB::table('personal_mina')->insert([
                'id' => (string) Str::uuid(),
                'personal_id' => $personalId,
                'mina_id' => $personalMineId,
                'estado' => 'EN_PROCESO',
                'estado_habilitacion' => 'EN_PROCESO',
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $service = app(\App\Modules\Evaluaciones\Services\EvaluacionSupervisorService::class);
        $actor = \App\Models\Usuario::findOrFail($userId);
        $payload = [
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'mina_id' => $mineId,
            'fecha' => '2026-09-25',
            'respuestas' => array_fill_keys(array_keys(\App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate::WEIGHTS), 4),
        ];

        $allowed = $service->create($actor, [...$payload, 'evaluado_id' => $supervisor]);
        $outside = $service->create($actor, [...$payload, 'evaluado_id' => $outsideSupervisor]);

        $this->assertTrue($allowed['ok']);
        $this->assertTrue($outside['ok']);
        $invalidMine = $service->create($actor, [
            ...$payload, 'destino_id' => (string) Str::uuid(), 'evaluado_id' => $outsideSupervisor,
        ]);
        $this->assertFalse($invalidMine['ok']);
        $this->assertSame('EVAL_SUP_CONTEXT_INVALID', $invalidMine['code']);
        $this->withSession($this->sessionFor($userId))
            ->getJson(route('evaluaciones.personal.buscar', ['tipo' => 'supervisores', 'q' => 'SUPERVISOR FUERA']))
            ->assertOk()->assertJsonPath('data.0.id', $outsideSupervisor);
    }

    public function test_usuario_con_permiso_sin_ficha_personal_evalua_supervisor(): void
    {
        [$userId] = $this->createUser(['evaluaciones' => ['evaluar_supervisores']], 'DIRECCION_SUPERVISORES');
        DB::table('usuarios')->where('id', $userId)->update(['personal_id' => null]);
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA SUPERVISORES', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);
        $supervisorId = $this->createPersonal('SUPERVISOR SIN VINCULO', true);

        $result = app(\App\Modules\Evaluaciones\Services\EvaluacionSupervisorService::class)->create(
            \App\Models\Usuario::findOrFail($userId), [
                'evaluado_id' => $supervisorId, 'fecha' => '2026-09-26',
                'mina_id' => $mineId, 'destino_tipo' => 'MINA', 'destino_id' => $mineId,
                'respuestas' => array_fill_keys(array_keys(\App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate::WEIGHTS), 4),
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('evaluacion_supervisor', [
            'evaluado_id' => $supervisorId, 'evaluador_id' => null, 'created_by_usuario_id' => $userId,
        ]);
    }

    public function test_solo_gerencia_o_administracion_evalua_residentes(): void
    {
        [$operationsId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes'],
        ], 'OPERACIONES_GERENCIA_TEST', 'JEFE DE OPERACIONES');
        [$managerId] = $this->createUser([
            'evaluaciones' => ['ver_residentes', 'evaluar_residentes', 'habilitar_evaluacion_residentes'],
        ], 'GERENTE_EVALUACIONES_TEST', 'GERENTE GENERAL');

        $policy = app(\App\Modules\Evaluaciones\Policies\EvaluacionesPolicy::class);

        $this->assertFalse($policy->canEvaluateType(\App\Models\Usuario::findOrFail($operationsId), 'residentes'));
        $this->assertTrue($policy->canEvaluateType(\App\Models\Usuario::findOrFail($managerId), 'residentes'));
    }

    public function test_dashboard_filtra_y_respeta_propiedad_y_tipo_sin_recargar_layout(): void
    {
        [$id, $person] = $this->createUser(['evaluaciones' => ['ver_supervisores', 'administrar', 'dashboards']]);
        $mine = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mine, 'nombre' => 'MINA DASHBOARD', 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);
        $worker = $this->createPersonal('PERSONA DASHBOARD', true);
        DB::table('personal')->where('id', $worker)->update(['puesto' => 'SUPERVISOR PRUEBA']);
        foreach ([['2026-09-10', 80, $id], ['2026-09-11', 100, $id], ['2026-08-01', 20, $id], ['2026-09-10', 10, null]] as [$date, $score, $owner]) {
            DB::table('evaluacion_supervisor')->insert(['id' => (string) Str::uuid(), 'evaluado_id' => $worker, 'evaluador_id' => null,
                'fecha' => $date, 'mina_id' => $mine, 'resultado_final' => $score, 'created_by_usuario_id' => $owner]);
        }
        $filters = ['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'mina' => $mine, 'cargo' => 'SUPERVISOR PRUEBA'];
        $service = app(\App\Modules\Evaluaciones\Services\EvaluacionesDashboardService::class);
        $actor = \App\Models\Usuario::findOrFail($id);
        $data = $service->build($actor, $filters);
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['people']);
        $this->assertCount(1, $data['summary']);
        $this->assertEquals(90, $data['summary'][0]['average']);
        $this->assertSame(['supervisores' => 'Supervisores'], $data['types']);
        $this->assertSame(2, $service->build($actor, [...$filters, 'evaluacion_tipo' => 'supervisores'])['total']);
        $this->assertSame(0, $service->build($actor, [...$filters, 'evaluacion_tipo' => 'residentes'])['total']);
        $this->assertSame(0, $service->build($actor, [...$filters, 'mina' => 'sin_mina'])['total']);
        $this->assertSame(1, $service->build($actor, [...$filters, 'desde' => '2026-09-10', 'hasta' => '2026-09-10'])['total']);
        $this->withSession($this->sessionFor($id))->withHeader('X-Evaluation-Dashboard', '1')
            ->get(route('evaluaciones.index', ['tipo' => 'dashboard', ...$filters]))->assertOk()
            ->assertSee('Distribución por tipo')->assertSee('90 / 100')->assertDontSee('<!DOCTYPE html>', false);
        $this->withSession($this->sessionFor($id))->withHeader('X-Evaluation-Dashboard', '1')
            ->get(route('evaluaciones.index', ['tipo' => 'dashboard', ...$filters, 'evaluacion_tipo' => 'supervisores']))->assertOk()
            ->assertSee('Bloque A: Competencias Técnicas (Asociadas al Cargo)')
            ->assertSee('Bloque B: Desempeño en SSOMA')->assertSee('Bloque C: Habilidades blandas');
        $this->withSession($this->sessionFor($id))->getJson(route('evaluaciones.index', ['tipo' => 'dashboard', 'desde' => '2026-09-30', 'hasta' => '2026-09-01']))
            ->assertStatus(422);
        DB::table('roles')->where('id', $actor->rol_id)->update(['permisos' => json_encode(PermissionCatalog::matrixFromSelections([
            'evaluaciones' => ['ver_supervisores', 'evaluar_residentes', 'administrar'],
        ]))]);
        $actor = $actor->fresh();
        $residentWorker = $this->createPersonal('RESIDENTE DASHBOARD');
        DB::table('personal')->where('id', $residentWorker)->update(['puesto' => 'RESIDENTE DE OPERACIONES']);
        $created = app(\App\Modules\Evaluaciones\Services\EvaluacionDesempenoService::class)->createResidente($actor, [
            'residente_id' => $residentWorker, 'fecha' => '2026-09-01', 'indicadores_kpi_items' => ['REPORTE_ASISTENCIA'],
            'costos_servicio_items' => ['COSTOS_MENSUALES'], 'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI', 'liderazgo_gestion_innovacion' => 4, 'comentarios' => 'Prueba de filtro por tipo.',
        ]);
        $this->assertTrue($created['ok']);
        $allFilters = [...$filters, 'mina' => '', 'cargo' => ''];
        $this->assertSame(3, $service->build($actor, $allFilters)['total']);
        $this->assertNull($service->build($actor, $allFilters)['details']);
        $residentData = $service->build($actor, [...$allFilters, 'evaluacion_tipo' => 'residentes']);
        $this->assertSame(1, $residentData['total']);
        $this->assertSame('residentes', $residentData['details']['type']);
        $this->assertSame(1, $residentData['details']['total']);
        $this->assertSame('Residentes', $residentData['summary'][0]['label']);
        $this->assertCount(1, $residentData['summary']);
        $supervisorData = $service->build($actor, [...$allFilters, 'evaluacion_tipo' => 'supervisores']);
        $this->assertSame(2, $supervisorData['total']);
        $this->assertSame([
            'Aporte ponderado por bloque · puntos de 100',
            'Bloque A: Competencias Técnicas (Asociadas al Cargo) · escala 1 a 5',
            'Bloque B: Desempeño en SSOMA · escala 1 a 5',
            'Bloque C: Habilidades blandas · escala 1 a 5',
        ], array_column($supervisorData['details']['charts'], 'title'));
    }

    private function createUser(array $permissions, ?string $roleName = null, string $position = 'ENCARGADO DE ASISTENCIA'): array
    {
        $roleId = (string) Str::uuid();
        $personalId = $this->createPersonal($position, true);
        DB::table('personal')->where('id', $personalId)->update(['puesto' => $position]);
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => $roleName ?: 'EVALUADOR_'.Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return [$userId, $personalId, $roleId];
    }

    private function createClosedAttendance(string $userId, string $supervisorId): array
    {
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'MINA EVALUACION', 'unidad_minera' => 'UM EVAL', 'estado' => 'ACTIVO']);
        $workerId = $this->createPersonal('TECNICO EVALUADO');
        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $mineId,
            'area' => 'MANTENIMIENTO',
            'fecha_inicio' => '2026-08-21',
            'fecha_fin' => '2026-08-21',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $groupId,
            'fecha' => '2026-08-21',
            'supervisor_id' => $supervisorId,
            'mina' => 'MINA EVALUACION',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'rq_mina_id' => $rqId,
            'servicio' => 'SERVICIO DIARIO',
            'area' => 'MANTENIMIENTO',
            'horario_salida' => '07:00:00',
            'turno' => 'DIA',
            'estado' => 'CERRADO',
            'created_by_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendanceId = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $attendanceId,
            'grupo_trabajo_id' => $groupId,
            'fecha' => '2026-08-21',
            'hora_ingreso' => '07:00:00',
            'mina_id' => $mineId,
            'destino_tipo' => 'MINA',
            'destino_id' => $mineId,
            'supervisor_id' => $supervisorId,
            'estado' => 'CERRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detailId = (string) Str::uuid();
        DB::table('asistencia_detalle')->insert([
            'id' => $detailId,
            'asistencia_id' => $attendanceId,
            'trabajador_id' => $workerId,
            'hora_marcado' => '07:02:00',
            'estado' => 'PRESENTE',
            'marcado_por_id' => $userId,
            'marcado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$mineId, $detailId, $workerId];
    }

    private function createPersonal(string $name, bool $supervisor = false): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name,
            'puesto' => $supervisor ? 'RESPONSABLE' : 'TECNICO',
            'es_supervisor' => $supervisor,
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignScope(string $userId, string $mineId): void
    {
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $userId, 'mina_id' => $mineId]);
    }

    private function sessionFor(string $userId): array
    {
        $user = DB::table('usuarios')->where('id', $userId)->first();
        $role = DB::table('roles')->where('id', $user->rol_id)->first();

        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => $user->email,
                'name' => 'USUARIO EVALUADOR',
                'rol' => $role->nombre,
                'personal_id' => $user->personal_id,
                'permissions' => json_decode($role->permisos, true),
            ],
        ];
    }
}
