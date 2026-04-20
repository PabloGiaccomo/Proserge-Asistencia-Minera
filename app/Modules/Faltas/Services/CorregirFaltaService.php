<?php

namespace App\Modules\Faltas\Services;

use App\Models\AsistenciaDetalle;
use App\Models\Falta;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class CorregirFaltaService
{
    public function corregir(Falta $falta, Usuario $usuario, array $payload): array
    {
        if (strtoupper((string) $falta->estado) === 'ANULADA') {
            return [
                'ok' => false,
                'code' => 'FALTA_ANULADA',
                'message' => 'No se puede corregir una falta anulada',
            ];
        }

        if (!$falta->asistencia_detalle_id) {
            return [
                'ok' => false,
                'code' => 'FALTA_WITHOUT_ASISTENCIA',
                'message' => 'La falta no tiene origen de asistencia para corregir',
            ];
        }

        $detalle = AsistenciaDetalle::query()->find($falta->asistencia_detalle_id);

        if (!$detalle) {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_DETALLE_NOT_FOUND',
                'message' => 'No existe el detalle de asistencia origen',
            ];
        }

        DB::transaction(function () use ($detalle, $falta, $usuario, $payload): void {
            $detalle->fill([
                'estado' => 'PRESENTE',
                'hora_marcado' => ($payload['hora_marcado'] ?? now()->format('H:i')).':00',
                'observaciones' => trim(((string) ($detalle->observaciones ?? '')).' | Corregido por falta '.$falta->id),
            ]);
            $detalle->save();

            $falta->fill([
                'estado' => 'CORREGIDA',
                'motivo_correccion' => $payload['motivo_correccion'],
                'corregido_por_usuario_id' => $usuario->id,
                'corregido_at' => now(),
            ]);
            $falta->save();
        });

        return ['ok' => true, 'falta' => $falta->fresh()];
    }
}
