<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RQMinaSendOnlyViewTest extends TestCase
{
    private function renderDetail(array $actions, array $overrides = []): string
    {
        $this->withSession(['user' => [
            'id' => 'sender-1',
            'permissions' => PermissionCatalog::matrixFromSelections(['rq_mina' => $actions]),
        ]]);
        $request = Request::create('/rq-mina/rq-1?lista[q]=parada&lista[page]=2');
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
        view()->share('errors', new ViewErrorBag);

        return view('rq-mina.show', ['item' => array_replace([
            'id' => 'rq-1',
            'area' => 'Parada de prueba',
            'lugar' => 'Mina de prueba',
            'estado' => 'BORRADOR',
            'observaciones' => '',
            'puede_registrar_rrhh' => true,
            'puede_actualizar' => true,
            'detalle' => [
                ['puesto' => 'Mecánico <campo>', 'cantidad' => 5, 'cantidad_backup' => 1, 'cantidad_total' => 6],
            ],
        ], $overrides)])->render();
    }

    private function document(string $html): DOMXPath
    {
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

    public function test_sender_can_review_and_confirm_the_saved_personnel_request(): void
    {
        $html = $this->renderDetail(['ver', 'enviar']);
        $xpath = $this->document($html);
        $panel = $xpath->query('//details[@id="rqRegisterSavedPersonnel"]')->item(0);
        $form = $xpath->query('//details[@id="rqRegisterSavedPersonnel"]//form')->item(0);

        self::assertNotNull($panel);
        self::assertFalse($panel->hasAttribute('open'), 'The saved request must be reviewed before its confirmation control is shown.');
        self::assertStringContainsString('Registrar pedido en RR.HH.', $panel->textContent);
        self::assertStringContainsString('cargos ya guardados', $panel->textContent);
        self::assertStringContainsString('Mecánico <campo>', $panel->textContent);
        self::assertStringContainsString('5 base + 1 back up = 6', $panel->textContent);
        self::assertStringContainsString('Confirmar registro en RR.HH.', $panel->textContent);
        self::assertNotNull($form);
        self::assertSame('POST', $form->getAttribute('method'));
        self::assertSame(route('rq-mina.enviar', ['id' => 'rq-1', 'lista' => ['q' => 'parada', 'page' => 2]]), $form->getAttribute('action'));
        self::assertSame(csrf_token(), $xpath->query('//details[@id="rqRegisterSavedPersonnel"]//input[@name="_token"]')->item(0)->getAttribute('value'));
        self::assertStringNotContainsString('<campo>', $html);
    }

    public function test_normal_planner_uses_the_save_flow_without_the_alternative_action(): void
    {
        $html = $this->renderDetail(['ver', 'editar', 'actualizar', 'enviar']);

        self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
    }

    public function test_sender_can_register_when_the_policy_does_not_allow_plan_updates(): void
    {
        $html = $this->renderDetail(['ver', 'editar', 'actualizar', 'enviar'], ['puede_actualizar' => false]);

        self::assertStringContainsString('id="rqRegisterSavedPersonnel"', $html);
    }

    public function test_sender_keeps_the_alternative_when_one_plan_permission_is_missing(): void
    {
        foreach ([['ver', 'editar', 'enviar'], ['ver', 'actualizar', 'enviar']] as $actions) {
            $html = $this->renderDetail($actions);
            self::assertStringContainsString('id="rqRegisterSavedPersonnel"', $html);
        }
    }

    public function test_detail_hides_registration_when_the_backend_denies_it(): void
    {
        $html = $this->renderDetail(['ver', 'enviar'], ['puede_registrar_rrhh' => false]);

        self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
    }

    public function test_detail_hides_registration_without_direct_send_permission(): void
    {
        $html = $this->renderDetail(['ver']);

        self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
    }

    public function test_detail_hides_registration_without_view_permission(): void
    {
        $html = $this->renderDetail(['enviar']);

        self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
    }

    public function test_detail_hides_registration_after_the_request_leaves_draft(): void
    {
        foreach (['ENVIADO', 'CERRADO', 'CANCELADO'] as $state) {
            $html = $this->renderDetail(['ver', 'enviar'], ['estado' => $state]);
            self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
        }
    }

    public function test_detail_hides_registration_without_saved_positions_and_quantities(): void
    {
        foreach ([[], [['puesto' => '', 'cantidad' => 5]], [['puesto' => 'Mecánico', 'cantidad' => 0]]] as $detail) {
            $html = $this->renderDetail(['ver', 'enviar'], ['detalle' => $detail]);
            self::assertStringNotContainsString('id="rqRegisterSavedPersonnel"', $html);
        }
    }
}
