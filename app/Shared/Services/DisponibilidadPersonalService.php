<?php

namespace App\Shared\Services;

use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\RQProsergeDetalle;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisponibilidadPersonalService
{
    public function evaluar(
        string $personalId,
        string $minaId,
        string $fechaInicio,
        string $fechaFin,
        ?string $excludeAsignacionId = null
    ): array {
        try {
            $personal = Personal::query()->find($personalId);

            if (!$personal) {
                return $this->businessUnavailable('PERSONAL_NOT_FOUND', 'Personal no existe');
            }

            if (strtoupper((string) $personal->estado) !== 'ACTIVO') {
                return $this->businessUnavailable('PERSONAL_NOT_ACTIVE', 'Personal inactivo');
            }

            $enMina = PersonalMina::query()
                ->where('personal_id', $personalId)
                ->where('mina_id', $minaId)
                ->whereIn('estado', ['ACTIVO', 'ASIGNADO', 'EN_PROCESO'])
                ->exists();

            if (!$enMina) {
                return $this->businessUnavailable('PERSONAL_OUT_OF_SCOPE_MINA', 'Personal sin relacion valida con la mina');
            }

            $hasBloqueo = DB::table('personal_bloqueo')
                ->where('personal_id', $personalId)
                ->where('estado', 'ACTIVO')
                ->whereDate('fecha_inicio', '<=', $fechaFin)
                ->whereDate('fecha_fin', '>=', $fechaInicio)
                ->exists();

            if ($hasBloqueo) {
                return $this->businessUnavailable('PERSONAL_BLOCKED', 'Personal bloqueado en el rango solicitado');
            }

            $rqConflict = RQProsergeDetalle::query()
                ->when($excludeAsignacionId, fn ($q) => $q->where('id', '!=', $excludeAsignacionId))
                ->where('personal_id', $personalId)
                ->whereDate('fecha_inicio', '<=', $fechaFin)
                ->whereDate('fecha_fin', '>=', $fechaInicio)
                ->exists();

            if ($rqConflict) {
                return $this->businessUnavailable('PERSONAL_CONFLICT_RQ', 'Personal con conflicto en otro RQ en el rango solicitado');
            }

            $groupConflict = DB::table('grupo_trabajo_detalle as gtd')
                ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
                ->where('gtd.personal_id', $personalId)
                ->whereBetween('gt.fecha', [$fechaInicio, $fechaFin])
                ->exists();

            if ($groupConflict) {
                return $this->businessUnavailable('PERSONAL_CONFLICT_MANPOWER', 'Personal ya comprometido en grupo de trabajo');
            }

            return [
                'ok' => true,
                'available' => true,
                'reason_code' => null,
                'reason_message' => null,
                'technical_error' => false,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'available' => false,
                'reason_code' => 'TECHNICAL_ERROR',
                'reason_message' => 'Error tecnico validando disponibilidad',
                'technical_error' => true,
                'exception' => $e,
            ];
        }
    }

    private function businessUnavailable(string $reasonCode, string $reasonMessage): array
    {
        return [
            'ok' => true,
            'available' => false,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage,
            'technical_error' => false,
        ];
    }
}
