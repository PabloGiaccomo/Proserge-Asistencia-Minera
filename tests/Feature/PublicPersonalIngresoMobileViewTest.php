<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPersonalIngresoMobileViewTest extends TestCase
{
    public function test_ficha_colaborador_public_page_is_accessible(): void
    {
        $response = $this->get(route('personal.ingresos.public.show'));
        $response->assertStatus(200);
        $response->assertSee('Ficha del colaborador');
        $response->assertSee('Requiere clave diaria');
        $response->assertSee('inputmode="numeric"', false);
    }
}
