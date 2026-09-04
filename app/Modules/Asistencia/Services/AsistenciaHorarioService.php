<?php

namespace App\Modules\Asistencia\Services;

use App\Models\GrupoTrabajo;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AsistenciaHorarioService
{
    public function resumen(GrupoTrabajo $grupo): array
    {
        $timezone = config('app.display_timezone', 'America/Lima');
        $now = CarbonImmutable::now($timezone);
        $hora = (string) $grupo->horario_salida;
        $limite = $grupo->fecha && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)
            ? CarbonImmutable::parse($grupo->fecha->toDateString().' '.$hora, $timezone)
            : null;
        $vencido = $limite && $now->greaterThan($limite);

        return [
            'permite_presente' => $limite !== null && !$vencido,
            'vencido' => (bool) $vencido,
            'jornada_actual' => $limite && $limite->toDateString() === $now->toDateString(),
            'limite' => $limite?->toIso8601String(),
            'servidor' => $now->toIso8601String(),
            'hora_actual' => $now->format('H:i:s'),
            'mensaje' => !$limite
                ? 'Configura el horario del grupo antes de registrar presentes.'
                : ($vencido ? 'Horario vencido. Los pendientes quedan ausentes; puedes registrar tardanza o justificar con un motivo.' : ''),
        ];
    }

    public function validarPresente(GrupoTrabajo $grupo, array $payload): void
    {
        if (strtoupper((string) ($payload['estado'] ?? '')) === 'PRESENTE') {
            $horario = $this->resumen($grupo);
            if (!$horario['permite_presente']) {
                throw ValidationException::withMessages(['estado' => $horario['mensaje']]);
            }
        }
    }
}
