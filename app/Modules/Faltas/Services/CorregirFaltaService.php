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
        return DB::transaction(function () use ($falta, $usuario, $payload): array {
            $faltaBloqueada = Falta::query()
                ->lockForUpdate()
                ->find($falta->getKey());

            if (! $faltaBloqueada) {
                return [
                    'ok' => false,
                    'code' => 'FALTA_NOT_FOUND',
                    'message' => 'La falta no existe',
                ];
            }

            $estado = strtoupper((string) $faltaBloqueada->estado);
            if ($estado === 'ANULADA') {
                return [
                    'ok' => false,
                    'code' => 'FALTA_ANULADA',
                    'message' => 'No se puede corregir una falta anulada',
                ];
            }

            if ($estado === 'CORREGIDA') {
                return [
                    'ok' => false,
                    'code' => 'FALTA_ALREADY_CORRECTED',
                    'message' => 'La falta ya fue corregida y su historial no puede sobrescribirse',
                ];
            }

            if ($estado !== 'ACTIVA') {
                return [
                    'ok' => false,
                    'code' => 'FALTA_NOT_ACTIVE',
                    'message' => 'Solo se puede corregir una falta activa',
                ];
            }

            if (! $faltaBloqueada->asistencia_detalle_id) {
                return [
                    'ok' => false,
                    'code' => 'FALTA_WITHOUT_ASISTENCIA',
                    'message' => 'La falta no tiene origen de asistencia para corregir',
                ];
            }

            $detalle = AsistenciaDetalle::query()
                ->lockForUpdate()
                ->find($faltaBloqueada->asistencia_detalle_id);

            if (! $detalle) {
                return [
                    'ok' => false,
                    'code' => 'ASISTENCIA_DETALLE_NOT_FOUND',
                    'message' => 'No existe el detalle de asistencia origen',
                ];
            }

            $notaAuditoria = 'Corregido por falta '.$faltaBloqueada->id;
            $observaciones = trim((string) $detalle->observaciones);
            $detalle->fill([
                'estado' => 'PRESENTE',
                'hora_marcado' => ($payload['hora_marcado'] ?? now()->format('H:i')).':00',
                'observaciones' => $observaciones === '' ? $notaAuditoria : $observaciones.' | '.$notaAuditoria,
                'marcado_por_id' => $usuario->id,
                'updated_by' => $usuario->id,
                'marcado_at' => now(),
                'origen_registro' => 'CORRECCION_FALTA',
            ]);
            $detalle->save();

            $faltaBloqueada->fill([
                'estado' => 'CORREGIDA',
                'motivo_correccion' => $payload['motivo_correccion'],
                'corregido_por_usuario_id' => $usuario->id,
                'corregido_at' => now(),
            ]);
            $faltaBloqueada->save();

            return ['ok' => true, 'falta' => $faltaBloqueada->fresh()];
        });
    }
}
