<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RQMinaPlanInputRecoveryTest extends TestCase
{
    public function test_failed_save_retains_submitted_values_and_revision_without_browser_storage(): void
    {
        $saved = $this->renderPlan();
        $recovered = $this->renderPlan($this->submittedInput());

        self::assertSame('rq-1', $this->value($saved, 'rq_mina_id'));
        self::assertSame('Cargo pendiente', $this->value($recovered, 'detalle[0][puesto]'));
        self::assertSame('7', $this->value($recovered, 'detalle[0][cantidad]'));
        self::assertSame('Grupo pendiente', $this->initialPlan($recovered)[0]['nombre']);
        self::assertSame('revision-enviada', $this->value($recovered, 'plan_revision'));
        self::assertSame(
            $saved->query('//form[@id="rqMinaPlanForm"]')->item(0)->getAttribute('data-draft-base'),
            $recovered->query('//form[@id="rqMinaPlanForm"]')->item(0)->getAttribute('data-draft-base'),
            'Recovering submitted input must keep the server content as the browser copy base.',
        );
        self::assertSame('1', $recovered->query('//form[@id="rqMinaPlanForm"]')->item(0)->getAttribute('data-recovered-input'));
    }

    public function test_recovery_cannot_adopt_the_current_revision_after_a_conflict(): void
    {
        $recovered = $this->renderPlan($this->submittedInput());

        self::assertSame('revision-enviada', $this->value($recovered, 'plan_revision'));
        self::assertNotSame('revision-actual', $this->value($recovered, 'plan_revision'));
    }

    public function test_input_from_another_parada_or_plan_or_an_incomplete_submission_is_not_recovered(): void
    {
        foreach ([
            ['rq_mina_id' => 'otra-parada'],
            ['plan_id' => 'otro-plan'],
            ['plan_editor_complete' => null],
            ['plan_editor_submission' => null],
            ['plan_revision' => null],
            ['plan_operativo' => 'contenido-invalido'],
        ] as $changes) {
            $page = $this->renderPlan(array_replace($this->submittedInput(), $changes));

            self::assertSame('Cargo guardado', $this->value($page, 'detalle[0][puesto]'));
            self::assertSame('Grupo guardado', $this->initialPlan($page)[0]['nombre']);
            self::assertSame('revision-actual', $this->value($page, 'plan_revision'));
        }
    }

    public function test_recovery_preserves_removing_all_groups_and_personnel_rows(): void
    {
        $input = $this->submittedInput();
        unset($input['detalle'], $input['plan_operativo']);
        $page = $this->renderPlan($input);

        self::assertSame('', $this->value($page, 'detalle[0][puesto]'));
        self::assertSame([], $this->initialPlan($page));
        self::assertSame('revision-enviada', $this->value($page, 'plan_revision'));
    }

    private function submittedInput(): array
    {
        return [
            'rq_mina_id' => 'rq-1', 'plan_id' => 'plan-1',
            'plan_editor_submission' => '1', 'plan_editor_complete' => '1',
            'plan_revision' => 'revision-enviada',
            'detalle' => [['puesto' => 'Cargo pendiente', 'cantidad' => 7]],
            'plan_operativo' => [['nombre' => 'Grupo pendiente', 'actividades' => [], 'transportes' => []]],
        ];
    }

    private function renderPlan(array $oldInput = []): DOMXPath
    {
        $this->withoutVite();
        $this->withSession(['user' => [
            'id' => 'planner-1', 'permissions' => PermissionCatalog::fullAccessMatrix(),
        ]]);
        $request = Request::create('/rq-mina/rq-1/plan?plan_id=plan-1');
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        $this->app['session.store']->flashInput($oldInput);
        view()->share('errors', new ViewErrorBag);

        $plan = [
            'id' => 'plan-1', 'codigo' => 'PLAN-001', 'nombre' => 'Plan inicial',
            'estado' => 'BORRADOR', 'version' => 1, 'fecha_inicio' => '2026-11-01',
            'fecha_fin' => '2026-11-15', 'semana_referencia' => 'Semana 45', 'observaciones' => '',
        ];
        $html = view('rq-mina.plan', ['item' => [
            'id' => 'rq-1', 'area' => 'Parada de prueba', 'lugar' => 'Mina de prueba',
            'estado' => 'BORRADOR', 'observaciones' => '', 'plans' => [$plan],
            'selected_plan' => $plan, 'selected_plan_id' => 'plan-1',
            'plan_revision' => 'revision-actual', 'puede_actualizar' => true,
            'detalle' => [['puesto' => 'Cargo guardado', 'cantidad' => 2]],
            'plan_operativo' => [['nombre' => 'Grupo guardado', 'actividades' => [], 'transportes' => []]],
        ]])->render();

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function value(DOMXPath $page, string $name): string
    {
        return $page->query('//form[@id="rqMinaPlanForm"]//input[@name="'.$name.'"]')->item(0)->getAttribute('value');
    }

    private function initialPlan(DOMXPath $page): array
    {
        return json_decode($page->query('//script[@data-rq-plan-initial="rqPlanOperativoEditor"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
    }
}
