<?php

namespace Tests\Feature;

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
}
