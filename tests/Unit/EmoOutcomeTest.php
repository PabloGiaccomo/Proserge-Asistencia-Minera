<?php

namespace Tests\Unit;

use App\Modules\Personal\Support\EmoOutcome;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmoOutcomeTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function test_normalizes_outcome_and_preserves_its_medical_meaning(string $input, string $value, string $generic, bool $detail, string $label): void
    {
        $outcome = EmoOutcome::fromInput($input);

        $this->assertSame($value, $outcome->value);
        $this->assertSame($generic, $outcome->genericResult());
        $this->assertSame($detail, $outcome->requiresDetail());
        $this->assertSame($label, $outcome->label());
    }

    public static function outcomes(): array
    {
        return [
            ['APTO', 'APTO', 'APROBADO', false, 'Apto'],
            ['NO_APTO', 'NO_APTO', 'DESAPROBADO', false, 'No apto'],
            ['NO APTO', 'NO_APTO', 'DESAPROBADO', false, 'No apto'],
            ['APTO_CON_RESTRICCION', 'APTO_CON_RESTRICCION', 'APROBADO', true, 'Apto con restriccion'],
            ['APTO_CON_OBSERVACION', 'APTO_CON_OBSERVACION', 'APROBADO', true, 'Apto con observacion'],
            ['  apto  con_restriccion  ', 'APTO_CON_RESTRICCION', 'APROBADO', true, 'Apto con restriccion'],
            ["\tno\t apto\n", 'NO_APTO', 'DESAPROBADO', false, 'No apto'],
            ['APTO CON RESTRICCIONES', 'APTO_CON_RESTRICCION', 'APROBADO', true, 'Apto con restriccion'],
            ['apto_con_restricción', 'APTO_CON_RESTRICCION', 'APROBADO', true, 'Apto con restriccion'],
            ['APTO CON OBSERVACIONES', 'APTO_CON_OBSERVACION', 'APROBADO', true, 'Apto con observacion'],
            ['apto_con_observación', 'APTO_CON_OBSERVACION', 'APROBADO', true, 'Apto con observacion'],
        ];
    }

    #[DataProvider('legacyMedicalTexts')]
    public function test_extracts_only_real_legacy_medical_detail(string $input, string $outcome, ?string $detail, string $tooltip): void
    {
        $legacy = EmoOutcome::fromLegacyText($input);

        $this->assertNotNull($legacy);
        $this->assertSame($outcome, $legacy['outcome']->value);
        $this->assertSame($detail, $legacy['detail']);
        $this->assertSame($tooltip, $legacy['tooltip']);
    }

    public static function legacyMedicalTexts(): array
    {
        return [
            ['APTO CON RESTRICCIONES', 'APTO_CON_RESTRICCION', null, 'Detalle de restriccion no registrado'],
            ['Apto con restricción: usar lentes', 'APTO_CON_RESTRICCION', 'usar lentes', 'Restriccion: usar lentes'],
            ['APTO_CON_OBSERVACIONES - control anual', 'APTO_CON_OBSERVACION', 'control anual', 'Observacion: control anual'],
            ['APTO CON OBSERVACIÓN: Evaluación cardiológica', 'APTO_CON_OBSERVACION', 'Evaluación cardiológica', 'Observacion: Evaluación cardiológica'],
            ['APTO CON OBSERVACIÓN', 'APTO_CON_OBSERVACION', null, 'Detalle de observacion no registrado'],
            ['NO APTO', 'NO_APTO', null, ''],
        ];
    }

    #[DataProvider('invalidOutcomes')]
    public function test_rejects_unknown_or_malformed_outcomes(mixed $input): void
    {
        $this->expectException(ValidationException::class);

        EmoOutcome::fromInput($input);
    }

    public static function invalidOutcomes(): array
    {
        return [['APROBADO'], ['PENDIENTE'], ['APTO CON RESTRICCION: texto'], [''], [null], [42], [true], [[]]];
    }
}
