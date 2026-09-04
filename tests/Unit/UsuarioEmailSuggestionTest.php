<?php

namespace Tests\Unit;

use App\Modules\Seguridad\Support\UsuarioEmailSuggestion;
use PHPUnit\Framework\TestCase;

class UsuarioEmailSuggestionTest extends TestCase
{
    public function test_usa_el_primer_nombre_y_apellido_paterno(): void
    {
        $this->assertSame('maria.torres@proserge.com', UsuarioEmailSuggestion::fromFicha([
            'nombres' => ' MARIA ELENA ',
            'apellido_paterno' => ' TORRES ',
            'apellido_materno' => 'RAMOS',
            'correo' => 'otro@example.test',
        ]));
    }

    public function test_normaliza_tildes_y_apellidos_compuestos(): void
    {
        $this->assertSame('jose.delapena@proserge.com', UsuarioEmailSuggestion::fromFicha([
            'nombres' => "JOS\u{00C9} LUIS",
            'apellido_paterno' => "DE LA PE\u{00D1}A",
        ]));
    }

    public function test_no_inventa_un_correo_si_faltan_datos(): void
    {
        foreach ([[], ['nombres' => 'MARIA'], ['apellido_paterno' => 'TORRES'], ['nombres' => '---', 'apellido_paterno' => 'TORRES']] as $data) {
            $this->assertNull(UsuarioEmailSuggestion::fromFicha($data));
        }
    }

    public function test_no_sugiere_un_correo_que_excede_el_limite_local(): void
    {
        $this->assertNull(UsuarioEmailSuggestion::fromFicha([
            'nombres' => str_repeat('a', 40),
            'apellido_paterno' => str_repeat('b', 30),
        ]));
    }
}
