<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PublicDocumentBadgeViewTest extends TestCase
{
    public function test_documentos_muestran_obligatorio_sin_bloquear_el_envio(): void
    {
        $html = view('personal.fichas.partials.ingreso-form-fields', [
            'sections' => [],
            'data' => [],
            'documentRequirements' => ['cv' => ['label' => 'CV documentado']],
            'declarationCheckboxes' => [],
            'errors' => new ViewErrorBag(),
        ])->render();

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $badge = $xpath->query('//label[@for="documento_cv"]/span')->item(0);
        $input = $xpath->query('//input[@id="documento_cv"]')->item(0);

        $this->assertNotNull($badge);
        $this->assertSame('Obligatorio', trim($badge->textContent));
        $this->assertNotNull($input);
        $this->assertFalse($input->hasAttribute('required'));
        $this->assertStringContainsString('igual puedes enviar la ficha', $html);
        $this->assertStringContainsString('Faltara regularizar si no lo adjuntas ahora.', $html);
    }
}
