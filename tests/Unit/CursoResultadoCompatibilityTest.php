<?php

namespace Tests\Unit;

use App\Modules\Personal\Support\CursoResultado;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CursoResultadoCompatibilityTest extends TestCase
{
    #[DataProvider('legacyResults')]
    public function test_default_calls_preserve_existing_contract(string $input, string $result, ?string $observation): void
    {
        $payload = ['resultado' => $input, 'observacion' => 'Evitar altura', 'nota' => 18];
        $expected = ['resultado' => $result, 'observacion' => $observation ?? 'Evitar altura', 'nota' => 18];

        $this->assertSame($expected, CursoResultado::payload($payload));
        $this->assertSame($expected, CursoResultado::payload($payload, false));
    }

    public static function legacyResults(): array
    {
        return [
            ['APTO', 'APROBADO', null],
            ['NO_APTO', 'DESAPROBADO', null],
            ['APTO_CON_RESTRICCION', 'APROBADO', 'APTO CON RESTRICCION: Evitar altura'],
            ['APROBADO', 'APROBADO', null],
            ['NO_APLICA', 'NO_APLICA', null],
            ['PENDIENTE', 'PENDIENTE', null],
            ['NO_ASISTIO', 'NO_ASISTIO', null],
            ['APTO_CON_OBSERVACION', 'APROBADO', 'APTO CON OBSERVACION: Evitar altura'],
            ['APTO CON RESTRICCIONES', 'APROBADO', 'APTO CON RESTRICCION: Evitar altura'],
            ['APTO CON OBSERVACIONES', 'APROBADO', 'APTO CON OBSERVACION: Evitar altura'],
        ];
    }

    #[DataProvider('emoResults')]
    public function test_emo_returns_only_the_compatible_generic_contract(string $input, string $generic, string $observation): void
    {
        $payload = ['resultado' => $input, 'observacion' => 'Evitar altura', 'nota' => 18];

        $this->assertSame(['resultado' => $generic, 'observacion' => $observation, 'nota' => 18], CursoResultado::payload($payload, true));
    }

    public static function emoResults(): array
    {
        return [
            ['APTO', 'APROBADO', 'Evitar altura'],
            ['NO APTO', 'DESAPROBADO', 'Evitar altura'],
            ['NO_APTO', 'DESAPROBADO', 'Evitar altura'],
            ['APTO_CON_RESTRICCION', 'APROBADO', 'APTO CON RESTRICCION: Evitar altura'],
            [' apto  con_observacion ', 'APROBADO', 'APTO CON OBSERVACION: Evitar altura'],
        ];
    }

    #[DataProvider('requiresDetail')]
    public function test_emo_requires_detail_for_restriction_and_observation(string $outcome, mixed $detail): void
    {
        try {
            CursoResultado::payload(['resultado' => $outcome, 'observacion' => $detail], true);
            $this->fail('Expected required medical detail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('observacion', $exception->errors());
        }
    }

    public static function requiresDetail(): array
    {
        return [
            ['APTO_CON_RESTRICCION', null], ['APTO_CON_RESTRICCION', '  '],
            ['APTO_CON_OBSERVACION', null], ['APTO_CON_OBSERVACION', '  '],
        ];
    }

    public function test_emo_rejects_generic_results_instead_of_inventing_medical_meaning(): void
    {
        $this->expectException(ValidationException::class);
        CursoResultado::payload(['resultado' => 'APROBADO'], true);
    }

    public function test_plain_outcomes_do_not_require_detail(): void
    {
        $this->assertSame(['resultado' => 'APROBADO'], CursoResultado::payload(['resultado' => 'APTO'], true));
        $this->assertSame(['resultado' => 'DESAPROBADO'], CursoResultado::payload(['resultado' => 'NO_APTO'], true));
    }

    public function test_medical_text_prefix_is_not_duplicated(): void
    {
        foreach (['APTO CON RESTRICCION', 'APTO CON OBSERVACION'] as $result) {
            $observation = $result.': Evitar altura';
            $this->assertSame(['resultado' => 'APROBADO', 'observacion' => $observation], CursoResultado::payload([
                'resultado' => $result, 'observacion' => $observation,
            ], true));
        }
    }
}
