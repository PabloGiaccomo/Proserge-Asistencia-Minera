<?php

namespace Tests\Feature;

use App\Modules\Personal\Support\PersonalFichaCatalog;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class PublicQuickGuideViewTest extends TestCase
{
    public function test_la_guia_renderiza_documentos_y_enlaces_sin_desplegables(): void
    {
        $html = view('ficha-public.partials.quick-guide')->render();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);

        $this->assertSame(0, $xpath->query('//details | //summary | //*[@hidden]')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="public-guide-docs-title"]/h2[@id="public-guide-docs-title"]')->length);
        $this->assertSame(11, $xpath->query('//div[@class="public-guide-docs"]/div[1]/ul/li')->length);
        $this->assertSame(5, $xpath->query('//div[@class="public-guide-docs"]/div[2]/ul/li/a')->length);
        $this->assertSame(4, $xpath->query('//div[@class="public-guide-step"]')->length);
    }

    private function renderIngresoHtml(bool $authorized = true): string
    {
        return view('ficha-public.ingreso', [
            'authorized' => $authorized,
            'data' => PersonalFichaCatalog::emptyData(),
            'sections' => PersonalFichaCatalog::sections(),
            'familiares' => [
                [
                    'parentesco' => 'Hijo',
                    'nombres_apellidos' => 'Perez Juan',
                    'fecha_nacimiento' => '2015-05-10',
                    'tipo_documento' => 'DNI',
                    'numero_documento' => '76543210',
                    'telefono' => '987654321',
                    'vive_con_trabajador' => true,
                    'estudia' => true,
                ],
            ],
            'documentRequirements' => PersonalFichaCatalog::documentRequirements(),
            'declarationCheckboxes' => PersonalFichaCatalog::declarationCheckboxes(),
            'puestoOptions' => ['OPERARIO', 'CONDUCTOR'],
        ])->render();
    }

    private function getXpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    public function test_teclados_numericos_y_telefonicos_en_campos_principales(): void
    {
        $html = $this->renderIngresoHtml(true);
        $xpath = $this->getXpath($html);

        // numero_documento (DNI) debe tener inputmode="numeric"
        $dniNodes = $xpath->query('//input[@name="fields[numero_documento]"]');
        $this->assertSame(1, $dniNodes->length);
        $this->assertSame('numeric', $dniNodes->item(0)->getAttribute('inputmode'));

        // numero_cuenta debe tener inputmode="numeric"
        $cuentaNodes = $xpath->query('//input[@name="fields[numero_cuenta]"]');
        $this->assertSame(1, $cuentaNodes->length);
        $this->assertSame('numeric', $cuentaNodes->item(0)->getAttribute('inputmode'));

        // cci debe tener inputmode="numeric"
        $cciNodes = $xpath->query('//input[@name="fields[cci]"]');
        $this->assertSame(1, $cciNodes->length);
        $this->assertSame('numeric', $cciNodes->item(0)->getAttribute('inputmode'));

        // RUCs deben tener inputmode="numeric"
        $rucNodes = $xpath->query('//input[@name="fields[empleador_ruc]"]');
        $this->assertSame(1, $rucNodes->length);
        $this->assertSame('numeric', $rucNodes->item(0)->getAttribute('inputmode'));

        // telefono y telefono_alterno deben ser type="tel" e inputmode="tel"
        $telNodes = $xpath->query('//input[@name="fields[telefono]"]');
        $this->assertSame(1, $telNodes->length);
        $this->assertSame('tel', $telNodes->item(0)->getAttribute('type'));
        $this->assertSame('tel', $telNodes->item(0)->getAttribute('inputmode'));

        $telAltNodes = $xpath->query('//input[@name="fields[telefono_alterno]"]');
        $this->assertSame(1, $telAltNodes->length);
        $this->assertSame('tel', $telAltNodes->item(0)->getAttribute('type'));
        $this->assertSame('tel', $telAltNodes->item(0)->getAttribute('inputmode'));

        // remuneracion debe tener inputmode="decimal"
        $remunNodes = $xpath->query('//input[@name="fields[remuneracion]"]');
        $this->assertSame(1, $remunNodes->length);
        $this->assertSame('decimal', $remunNodes->item(0)->getAttribute('inputmode'));
    }

    public function test_tabla_familiares_responsive_y_campos_moviles(): void
    {
        $html = $this->renderIngresoHtml(true);
        $xpath = $this->getXpath($html);

        // Tabla con clase responsive
        $tableNodes = $xpath->query('//table[contains(@class, "ficha-family-responsive")]');
        $this->assertSame(1, $tableNodes->length);

        // Filas con clase card
        $rowNodes = $xpath->query('//tr[contains(@class, "ficha-family-row-card")]');
        $this->assertGreaterThanOrEqual(1, $rowNodes->length);

        // Celda de documento con data-label e inputmode="numeric"
        $docInput = $xpath->query('//input[@name="familiares[0][numero_documento]"]');
        $this->assertSame(1, $docInput->length);
        $this->assertSame('numeric', $docInput->item(0)->getAttribute('inputmode'));

        // Celda de telefono con data-label y type="tel"
        $phoneInput = $xpath->query('//input[@name="familiares[0][telefono]"]');
        $this->assertSame(1, $phoneInput->length);
        $this->assertSame('tel', $phoneInput->item(0)->getAttribute('type'));
        $this->assertSame('tel', $phoneInput->item(0)->getAttribute('inputmode'));

        // Nombres con data-uppercase="1"
        $nameInput = $xpath->query('//input[@name="familiares[0][nombres_apellidos]"]');
        $this->assertSame(1, $nameInput->length);
        $this->assertSame('1', $nameInput->item(0)->getAttribute('data-uppercase'));
    }

    public function test_canvas_firma_digital_con_configuracion_movil_y_validacion(): void
    {
        $html = $this->renderIngresoHtml(true);
        $xpath = $this->getXpath($html);

        // Canvas de firma con atributos de proteccion movil
        $canvasNodes = $xpath->query('//canvas[@data-signature-pad]');
        $this->assertSame(1, $canvasNodes->length);
        $this->assertSame('1', $canvasNodes->item(0)->getAttribute('data-required'));
        $this->assertStringContainsString('touch-action: none', $canvasNodes->item(0)->getAttribute('style'));

        // Input oculto para la firma en base64
        $inputNodes = $xpath->query('//input[@data-signature-input]');
        $this->assertSame(1, $inputNodes->length);
        $this->assertSame('1', $inputNodes->item(0)->getAttribute('data-required'));

        // Mensaje visual de error de firma
        $errorNodes = $xpath->query('//*[@data-signature-error]');
        $this->assertSame(1, $errorNodes->length);
    }

    public function test_componentes_de_optimizacion_de_fotos_y_submision(): void
    {
        $html = $this->renderIngresoHtml(true);
        $xpath = $this->getXpath($html);

        // Estado visual de envio y compresion
        $statusNodes = $xpath->query('//*[@data-ingreso-submit-status]');
        $this->assertSame(1, $statusNodes->length);

        // Huella digital con captura de camara trasera
        $huellaNodes = $xpath->query('//input[@name="huella"]');
        $this->assertSame(1, $huellaNodes->length);
        $this->assertSame('image/*', $huellaNodes->item(0)->getAttribute('accept'));
        $this->assertSame('environment', $huellaNodes->item(0)->getAttribute('capture'));

        // Input de clave diaria cuando no autorizado
        $unauthHtml = $this->renderIngresoHtml(false);
        $unauthXpath = $this->getXpath($unauthHtml);
        $claveNodes = $unauthXpath->query('//input[@id="clave"]');
        $this->assertSame(1, $claveNodes->length);
        $this->assertSame('numeric', $claveNodes->item(0)->getAttribute('inputmode'));
    }
}
