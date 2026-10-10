<?php

namespace Tests\Feature;

use App\Models\EvaluacionesImportLote;
use App\Models\EvaluacionSupervisor;
use App\Models\Usuario;
use App\Modules\Dashboard\Policies\DashboardPolicy;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionDiariaRequest;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionResidenteRequest;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Services\EvaluacionesDashboardService;
use App\Modules\Evaluaciones\Services\EvaluacionesHistoricalImportService;
use App\Modules\Evaluaciones\Services\EvaluacionesPageService;
use App\Modules\Evaluaciones\Services\EvaluacionSupervisorService;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SupervisorHistoricalScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_one_control_workbook_imports_supervisors_and_nothing_else(): void
    {
        config()->set('evaluaciones.historical_import_enabled', true);
        Storage::fake('local');
        [$actor, $worker] = $this->actor();
        $path = $this->workbook();
        try {
            $service = app(EvaluacionesHistoricalImportService::class);
            $preview = $service->previewSupervisors($actor, $path);
            $this->assertTrue($preview['ok']);
            $this->assertSame(1, $preview['counts']['proposed']);
            $this->assertSame('SUPERVISORS', $preview['manifest']['scope']);
            $this->assertEmpty($preview['manifest']['blocked']);
            $this->assertTrue($service->confirmSupervisors($actor, $preview['lote_id'], $preview['hash'])['ok']);
            $this->assertDatabaseHas('evaluacion_supervisor', ['evaluado_id' => $worker, 'resultado_final' => 61]);
            $this->assertSame(0, DB::table('evaluacion_desempeno')->where('origen', 'HISTORICA')->count());
            $this->assertSame(0, DB::table('evaluacion_residente')->whereNotNull('import_lote_id')->count());
            $this->assertSame(0, DB::table('asistencia_historica')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_old_mixed_preview_cannot_be_confirmed_through_supervisor_flow(): void
    {
        config()->set('evaluaciones.historical_import_enabled', true);
        Storage::fake('local');
        [$actor] = $this->actor();
        $id = (string) Str::uuid();
        $manifest = "evaluaciones-historicas/{$id}/manifest.json";
        Storage::disk('local')->put($manifest, json_encode(['proposed' => [['type' => 'daily']]]));
        EvaluacionesImportLote::create(['id' => $id, 'hash' => str_repeat('a', 64), 'estado' => 'PREVIEW',
            'manifest_path' => $manifest, 'created_by_usuario_id' => $actor->id]);
        $result = app(EvaluacionesHistoricalImportService::class)->confirmSupervisors($actor, $id, str_repeat('a', 64));
        $this->assertSame('EVAL_HIST_SCOPE', $result['code']);
        $this->assertDatabaseHas('evaluaciones_import_lotes', ['id' => $id, 'estado' => 'PREVIEW']);
    }

    public function test_every_supervisor_criterion_is_required_for_store_and_update(): void
    {
        $responses = array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4);
        foreach ([new StoreEvaluacionSupervisorRequest, new UpdateEvaluacionSupervisorRequest] as $request) {
            $rules = array_filter($request->rules(), fn ($key) => str_starts_with($key, 'respuestas'), ARRAY_FILTER_USE_KEY);
            $this->assertTrue(Validator::make(['respuestas' => $responses], $rules)->passes());
            foreach (array_keys($responses) as $key) {
                $missing = $responses;
                unset($missing[$key]);
                $this->assertTrue(Validator::make(['respuestas' => $missing], $rules)->fails(), $key);
            }
        }
    }

    public function test_incomplete_history_is_saved_but_excluded_until_admin_regularizes(): void
    {
        config()->set('evaluaciones.historical_import_enabled', true);
        Storage::fake('local');
        [$actor, $worker] = $this->actor();
        $path = $this->workbook();
        $book = IOFactory::load($path);
        $book->getSheetByName('EV SUPERVISORES')->setCellValue('G2', null);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        try {
            $service = app(EvaluacionesHistoricalImportService::class);
            $preview = $service->previewSupervisors($actor, $path);
            $this->assertCount(1, $preview['manifest']['warnings']);
            $this->assertTrue($service->confirmSupervisors($actor, $preview['lote_id'], $preview['hash'])['ok']);
            $item = EvaluacionSupervisor::where('evaluado_id', $worker)->firstOrFail();
            $this->assertSame('POR_REGULARIZAR', $item->estado);
            $filters = ['desde' => '2025-01-01', 'hasta' => '2025-12-31', 'mina' => '', 'cargo' => '', 'evaluacion_tipo' => 'supervisores'];
            $dashboard = app(EvaluacionesDashboardService::class);
            $this->assertSame(0, $dashboard->build($actor, $filters)['summary'][0]['count']);
            $result = app(EvaluacionSupervisorService::class)->update($actor, $item,
                ['respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4)]);
            $this->assertTrue($result['ok']);
            $this->assertSame('REGISTRADA', $item->fresh()->estado);
            $this->assertSame('REGULARIZADA', $item->fresh()->calculo_version);
            $this->assertEquals(61, $item->fresh()->source_payload[31]);
            $this->assertSame(1, $dashboard->build($actor, $filters)['summary'][0]['count']);
            $again = app(EvaluacionSupervisorService::class)->update($actor, $item, ['respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 5)]);
            $this->assertSame('EVAL_HIST_READ_ONLY', $again['code']);
            $this->assertCount(1, $item->fresh()->regularizationEvents());
        } finally {
            unlink($path);
        }
    }

    public function test_daily_and_resident_requests_already_require_all_their_criteria(): void
    {
        $cases = [
            [new StoreEvaluacionDiariaRequest, [
                'desempeno_trabajo' => 3, 'orden_limpieza' => 3, 'compromiso' => 3,
                'respuesta_emocional' => 3, 'seguridad_trabajo' => 3,
            ]],
            [new StoreEvaluacionResidenteRequest, [
                'indicadores_kpi_items' => ['NINGUNO'], 'costos_servicio_items' => ['NINGUNO'],
                'eventos_seguridad_respuesta' => 'NO', 'reportes_calidad_respuesta' => 'NO',
                'liderazgo_gestion_innovacion' => 3,
            ]],
        ];
        foreach ($cases as [$request, $payload]) {
            $rules = array_intersect_key($request->rules(), $payload);
            $this->assertTrue(Validator::make($payload, $rules)->passes());
            foreach (array_keys($payload) as $key) {
                $missing = $payload;
                unset($missing[$key]);
                $this->assertTrue(Validator::make($missing, $rules)->fails(), $key);
            }
        }
    }

    public function test_supervisor_service_rejects_partial_update_without_writing(): void
    {
        [$actor] = $this->actor();
        $item = new EvaluacionSupervisor(['resultado_final' => 75]);
        $result = app(EvaluacionSupervisorService::class)->update($actor, $item, ['respuestas' => ['A1' => 4]]);
        $this->assertSame('EVAL_CRITERIA_REQUIRED', $result['code']);
        $this->assertEquals(75, $item->resultado_final);
    }

    public function test_pending_history_can_be_found_reviewed_and_regularized_without_new_evaluation_permission(): void
    {
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        $session = $this->webSession($actor);
        $url = route('evaluaciones.index', ['tipo' => 'supervisores', 'estado_supervisor' => 'pendientes']);
        $this->withSession($session)->get($url)->assertOk()->assertSee('Regularizar')->assertSee('A2');
        $form = route('evaluaciones.index', ['tipo' => 'supervisores', 'regularizar_supervisor' => $item->id]);
        $this->withSession($session)->get($form)->assertOk()->assertSee('Guardar regularización')->assertSee('Guardar seguimiento');
        $this->withSession($session)->from($form)->post(route('evaluaciones.supervisores.seguimiento', $item->id), [
            'motivo' => 'Falta confirmar la nota A2 con el evaluador original.',
        ])->assertRedirect();
        $this->assertSame('POR_REGULARIZAR', $item->fresh()->estado);
        $this->assertSame(61, $item->fresh()->source_payload[31]);
        $this->withSession($session)->get($url)->assertSee('Falta confirmar la nota A2');
        $partial = array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4);
        unset($partial['A2']);
        $this->withSession($session)->from($form)->post(route('evaluaciones.supervisores.regularizar', $item->id), [
            'respuestas' => $partial,
        ])->assertRedirect($form)->assertSessionHasErrors('respuestas.A2');
        $this->assertSame('POR_REGULARIZAR', $item->fresh()->estado);
        $this->withSession($session)->post(route('evaluaciones.supervisores.regularizar', $item->id), [
            'respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4),
        ])->assertRedirect(route('evaluaciones.index', ['tipo' => 'supervisores']));
        $this->assertSame('REGULARIZADA', $item->fresh()->calculo_version);
        $this->assertCount(2, $item->fresh()->regularizationEvents());
        $this->withSession($session)->get($form)->assertStatus(409);
    }

    public function test_editor_without_administrative_permission_cannot_regularize_or_record_follow_up(): void
    {
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        DB::table('roles')->where('id', $actor->rol_id)->update(['permisos' => json_encode(PermissionCatalog::matrixFromSelections([
            'evaluaciones' => ['ver_supervisores', 'ver_todas_evaluaciones', 'editar'],
        ]))]);
        $actor = $actor->fresh();
        $service = app(EvaluacionSupervisorService::class);
        $this->assertSame('EVAL_FORBIDDEN', $service->update($actor, $item, ['respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4)])['code']);
        $this->assertSame('EVAL_FORBIDDEN', $service->recordHistoricalFollowUp($actor, $item, 'Revision pendiente')['code']);
        $this->withSession($this->webSession($actor))->get(route('evaluaciones.index', [
            'tipo' => 'supervisores', 'regularizar_supervisor' => $item->id,
        ]))->assertForbidden();
        $this->assertSame('POR_REGULARIZAR', $item->fresh()->estado);
        $this->withSession($this->webSession($actor))->post(route('evaluaciones.supervisores.seguimiento', $item->id), ['motivo' => 'Revision'])
            ->assertForbidden();
        $this->withSession($this->webSession($actor))->post(route('evaluaciones.supervisores.regularizar', $item->id), [
            'respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4),
        ])->assertForbidden();
    }

    public function test_report_remains_visible_after_closure_and_shows_live_pending_reason(): void
    {
        Storage::fake('local');
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        $batch = EvaluacionesImportLote::findOrFail($item->import_lote_id);
        $batch->update(['manifest_path' => "evaluaciones-historicas/{$batch->id}/manifest.json", 'estado' => 'CONFIRMADO']);
        Storage::disk('local')->put($batch->manifest_path, json_encode([
            'scope' => 'SUPERVISORS', 'proposed' => [['type' => 'supervisor', 'source_key' => $item->source_key, 'payload' => ['resultado_final' => 61]]],
            'blocked' => [], 'unchanged' => [], 'warnings' => [],
        ]));
        app(EvaluacionSupervisorService::class)->recordHistoricalFollowUp($actor, $item, 'Falta evidencia original');
        config()->set('evaluaciones.historical_import_enabled', false);
        $this->withSession($this->webSession($actor))->get(route('evaluaciones.index', ['tipo' => 'supervisores']))
            ->assertOk()->assertSee('Descargar reporte')->assertDontSee('Cargar datos históricos');
        $report = $this->get(route('evaluaciones.historico.reporte', $batch->id))->assertOk()->streamedContent();
        $this->assertStringContainsString('POR_REGULARIZAR', $report);
        $this->assertStringContainsString('A2', $report);
        $this->assertStringContainsString('Falta evidencia original', $report);
    }

    public function test_global_dashboard_excludes_pending_history_and_includes_regularized(): void
    {
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        $policy = \Mockery::mock(DashboardPolicy::class);
        $policy->shouldReceive('view')->andReturn(true);
        $policy->shouldReceive('isPrivileged')->andReturn(true);
        $dashboard = new DashboardService($policy);
        $filters = ['trabajador_id' => $worker];
        $this->assertSame(0, $dashboard->resumen($actor, $filters)['evaluaciones_supervisor_total']);
        app(EvaluacionSupervisorService::class)->update($actor, $item, ['respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4)]);
        $this->assertSame(1, $dashboard->resumen($actor, $filters)['evaluaciones_supervisor_total']);
    }

    public function test_failed_regularization_rolls_back_grade_state_and_audit(): void
    {
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        $before = $item->fresh()->getAttributes();
        $listener = static function ($saved) use ($item): void {
            if ($saved->id === $item->id && $saved->calculo_version === 'REGULARIZADA') {
                throw new \RuntimeException('Simulated failure after update');
            }
        };
        $event = 'eloquent.updated: '.EvaluacionSupervisor::class;
        Event::listen($event, $listener);
        try {
            app(EvaluacionSupervisorService::class)->update($actor, $item, [
                'respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4),
            ]);
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure after update', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public function test_pending_filters_search_and_pagination_do_not_hide_older_history(): void
    {
        [$actor, $worker] = $this->actor();
        $item = $this->pending($actor, $worker);
        foreach (range(1, 21) as $index) {
            EvaluacionSupervisor::create(['id' => (string) Str::uuid(), 'evaluado_id' => $worker, 'fecha' => '2026-10-01',
                'respuestas' => array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4), 'resultado_final' => 80,
                'estado' => 'REGISTRADA', 'created_by_usuario_id' => $actor->id]);
        }
        $service = app(EvaluacionesPageService::class);
        $all = $service->build($actor, 'supervisores', '2026-10-06')['supervisorHistory'];
        $this->assertSame(22, $all->total());
        $pending = $service->build($actor, 'supervisores', '2026-10-06', ['estado_supervisor' => 'pendientes', 'buscar_supervisor' => '96781234'])['supervisorHistory'];
        $this->assertSame(1, $pending->total());
        $this->assertSame($item->id, $pending->first()->id);
        $this->assertSame(0, $service->build($actor, 'supervisores', '2026-10-06', ['buscar_supervisor' => 'no-coincide'])['supervisorHistory']->total());
    }

    private function pending(Usuario $actor, string $worker): EvaluacionSupervisor
    {
        $batch = EvaluacionesImportLote::create(['id' => (string) Str::uuid(), 'hash' => str_repeat('a', 64), 'estado' => 'CONFIRMADO',
            'created_by_usuario_id' => $actor->id]);
        $responses = array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 4);
        $responses['A2'] = 0;

        return EvaluacionSupervisor::create(['id' => (string) Str::uuid(), 'evaluado_id' => $worker, 'fecha' => '2025-12-15',
            'respuestas' => $responses, 'resultado_final' => 61, 'estado' => 'POR_REGULARIZAR', 'calculo_version' => 'HISTORICA',
            'source_payload' => [31 => 61], 'source_key' => hash('sha256', $batch->id), 'import_lote_id' => $batch->id,
            'created_by_usuario_id' => $actor->id]);
    }

    private function webSession(Usuario $actor): array
    {
        return ['auth_token' => 'test-token', 'user_id' => $actor->id, 'user' => [
            'id' => $actor->id, 'email' => $actor->email, 'rol_id' => $actor->rol_id, 'personal_id' => $actor->personal_id,
            'rol' => $actor->rol->nombre, 'name' => 'USUARIO HISTORICO', 'permissions' => $actor->rol->permisos,
        ]];
    }

    private function actor(): array
    {
        $worker = (string) Str::uuid();
        DB::table('personal')->insert(['id' => $worker, 'dni' => '96781234', 'nombre_completo' => 'SUPERVISOR HISTORICO',
            'puesto' => 'SUPERVISOR', 'qr_code' => 'QR-'.Str::random(12), 'estado' => 'ACTIVO']);
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'nombre' => 'HIST-SUP-'.Str::random(8), 'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['evaluaciones' => ['administrar', 'ver_supervisores', 'ver_todas_evaluaciones', 'editar']]))]);
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert(['id' => $id, 'email' => Str::random(12).'@test.local', 'password' => bcrypt('test'), 'rol_id' => $role, 'estado' => 'ACTIVO']);

        return [Usuario::findOrFail($id), $worker];
    }

    private function workbook(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('PERSONAL')->fromArray([['Id', 'DNI'], ['LEGACY-SUP', '96781234']]);
        $values = array_fill(0, 36, null);
        $values[0] = 'SUP-1';
        $values[2] = 'LEGACY-SUP';
        $values[3] = '2025-12-15';
        foreach (range(5, 30) as $column) {
            $values[$column] = 4;
        }
        $values[31] = 61;
        $book->createSheet()->setTitle('EV SUPERVISORES')->fromArray([['Id EvSup', '', '', 'Fecha'], $values]);
        $book->createSheet()->setTitle('EV DESEMPENO')->fromArray([['NO IMPORTAR'], ['INVALIDO']]);
        $path = sys_get_temp_dir().'/sup-history-'.Str::random(12).'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
