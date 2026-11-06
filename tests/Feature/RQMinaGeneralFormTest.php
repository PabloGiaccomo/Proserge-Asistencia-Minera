<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Modules\RQMina\Controllers\RQMinaPageController;
use App\Modules\RQMina\Services\RQMinaService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RQMinaGeneralFormTest extends TestCase
{
    private function asPlanner(Request $request): void
    {
        $this->withSession(['user' => ['id' => 'user-1', 'mina_scopes' => ['mine-1']]]);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
    }

    public function test_rejects_an_end_date_before_the_start_without_creating_a_parada(): void
    {
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('resolveDestination')->andReturn([
            'mina_id' => 'mine-1', 'tipo' => 'MINA', 'id' => 'mine-1', 'nombre' => 'Mina de prueba',
        ]);
        $service->shouldNotReceive('create');
        $request = Request::create('/rq-mina', 'POST', [
            'destino_id' => 'MINA|mine-1', 'area' => 'Parada de prueba',
            'fecha_inicio' => '2026-11-09', 'fecha_fin' => '2026-11-02',
        ]);
        $this->asPlanner($request);

        $this->app->make(RQMinaPageController::class)->store($request);

        self::assertTrue(session('errors')->has('fecha_fin'));
        self::assertSame('Parada de prueba', session()->getOldInput('area'));
    }

    public function test_truncated_plan_submission_stops_before_replacing_saved_rows(): void
    {
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('findForUser')->once()->andReturn(new RQMina);
        $service->shouldNotReceive('updatePlanOperativo');
        $request = Request::create('/rq-mina/rq-1/plan', 'PUT', ['plan_editor_submission' => '1']);
        $this->asPlanner($request);

        $this->app->make(RQMinaPageController::class)->updatePlan($request, 'rq-1');

        self::assertStringContainsString('No se guardaron cambios', session('error'));
        self::assertNull(session('rq_mina_saved_draft_id'));
    }

    public function test_confirms_the_exact_local_copy_only_after_the_plan_service_succeeds(): void
    {
        $service = $this->mock(RQMinaService::class);
        $parada = new RQMina;
        $service->shouldReceive('findForUser')->once()->andReturn($parada);
        $service->shouldReceive('updatePlanOperativo')->once()->withArgs(
            fn ($user, $rq, $groups, $detail, $plan, $startRrhh = false) => $rq === $parada && $plan === 'plan-1' && $startRrhh,
        )->andReturn($parada);
        $request = Request::create('/rq-mina/rq-1/plan', 'PUT', [
            'plan_id' => 'plan-1', 'draft_id' => 'copy-1',
            'plan_editor_submission' => '1', 'plan_editor_complete' => '1',
        ]);
        $this->asPlanner($request);

        $response = $this->app->make(RQMinaPageController::class)->updatePlan($request, 'rq-1');

        self::assertSame(route('rq-mina.plan', ['id' => 'rq-1', 'plan_id' => 'plan-1']), $response->getTargetUrl());
        self::assertSame('copy-1', session('rq_mina_saved_draft_id'));
        self::assertStringContainsString('guardados en el sistema', session('success'));
    }

    public function test_incomplete_personnel_row_is_rejected_before_replacing_the_order(): void
    {
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('findForUser')->once()->andReturn(new RQMina);
        $service->shouldNotReceive('updatePlanOperativo');
        $request = Request::create('/rq-mina/rq-1/plan', 'PUT', [
            'plan_id' => 'plan-1', 'plan_editor_submission' => '1', 'plan_editor_complete' => '1',
            'detalle' => [['puesto' => 'Mecánico', 'cantidad' => null]],
        ]);
        $this->asPlanner($request);

        $this->app->make(RQMinaPageController::class)->updatePlan($request, 'rq-1');

        self::assertStringContainsString('cantidad', session('error'));
        self::assertNull(session('rq_mina_saved_draft_id'));
    }

    public function test_general_form_shows_validation_errors_and_retains_values_for_correction(): void
    {
        $this->withoutVite();
        $this->withSession(['user.permissions' => PermissionCatalog::fullAccessMatrix()]);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $this->app['session.store']->flashInput([
            'destino_id' => 'MINA|mine-1', 'area' => 'Nombre corregido',
            'fecha_inicio' => '2026-11-09', 'fecha_fin' => '2026-11-02',
            'observaciones' => 'Texto que debe conservarse', 'supervisor_id' => null,
        ]);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['fecha_fin' => ['Revisa la fecha de fin.']]));

        $html = view('rq-mina.create', [
            'errors' => $errors,
            'copyData' => [
                'id' => 'parada-1', 'area' => 'Nombre anterior',
                'supervisor' => ['id' => 'supervisor-anterior', 'nombre' => 'Supervisor de prueba'],
            ],
            'lugares' => [['id' => 'mine-1', 'tipo' => 'MINA', 'label' => 'Mina de prueba']],
        ])->render();

        self::assertStringContainsString('Revisa la fecha de fin.', $html);
        self::assertStringContainsString('value="Nombre corregido"', $html);
        self::assertStringContainsString('value="2026-11-02"', $html);
        self::assertStringContainsString('Texto que debe conservarse', $html);
        self::assertStringNotContainsString('supervisor-anterior', $html);
    }

    public function test_supervisor_validation_marks_the_search_field_for_correction(): void
    {
        $this->withSession(['user.permissions' => PermissionCatalog::fullAccessMatrix()]);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['supervisor_id' => ['Selecciona un supervisor válido.']]));
        $html = view('rq-mina.create', ['errors' => $errors, 'lugares' => []])->render();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);

        self::assertSame(1, $xpath->query('//*[@id="rqCreateSupervisorSelector"]//input[@data-rq-supervisor-search and @aria-invalid="true"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="rqCreateSupervisorSelectorError"]')->length);
    }

    public function test_creating_without_editor_permission_returns_to_the_saved_parada(): void
    {
        $this->assertCreateReturnsTo(['ver', 'crear'], route('rq-mina.show', ['id' => 'rq-created', 'lista' => ['q' => 'Prueba']]));
    }

    public function test_creating_without_read_permission_returns_to_the_accessible_form_with_confirmation(): void
    {
        $this->assertCreateReturnsTo(['crear'], route('rq-mina.create', ['lista' => ['q' => 'Prueba']]));
    }

    private function assertCreateReturnsTo(array $permissions, string $expectedUrl): void
    {
        $service = $this->mock(RQMinaService::class);
        $service->shouldReceive('resolveDestination')->andReturn([
            'mina_id' => 'mine-1', 'tipo' => 'MINA', 'id' => 'mine-1', 'nombre' => 'Mina de prueba',
        ]);
        $service->shouldReceive('create')->once()->andReturn(new RQMina(['id' => 'rq-created', 'estado' => 'BORRADOR']));
        $request = Request::create('http://127.0.0.1:8000/rq-mina?lista[q]=Prueba', 'POST', [
            'destino_id' => 'MINA|mine-1', 'area' => 'Parada de prueba',
            'fecha_inicio' => '2026-11-02', 'fecha_fin' => '2026-11-09',
        ]);
        $this->asPlanner($request);
        session()->put('user.permissions', PermissionCatalog::matrixFromSelections(['rq_mina' => $permissions]));

        $response = $this->app->make(RQMinaPageController::class)->store($request);

        self::assertSame($expectedUrl, $response->getTargetUrl());
        self::assertStringContainsString('Parada registrada', session('success'));
    }
}
