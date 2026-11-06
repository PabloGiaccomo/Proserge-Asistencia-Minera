<?php

namespace App\Modules\Faltas\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\Falta;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\Asistencia\Services\ParadaExecutionMetricsService;
use App\Modules\Faltas\Policies\FaltaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\DB;

class CorregirFaltaService
{
    public function __construct(
        private readonly FaltaPolicy $policy,
        private readonly ParadaExecutionMetricsService $metrics,
    ) {}

    public function corregir(Falta $falta, Usuario $usuario, array $payload): array
    {
        $sourceDetail = $falta->asistencia_detalle_id ? AsistenciaDetalle::query()->find($falta->asistencia_detalle_id) : null;
        $sourceHeader = $sourceDetail ? AsistenciaEncabezado::query()->find($sourceDetail->asistencia_id) : null;

        return DB::transaction(function () use ($falta, $usuario, $payload, $sourceDetail, $sourceHeader): array {
            $groupId = $sourceHeader?->grupo_trabajo_id;
            $grupo = $groupId ? GrupoTrabajo::query()->lockForUpdate()->find($groupId) : null;
            $encabezado = $sourceHeader ? AsistenciaEncabezado::query()->lockForUpdate()->find($sourceHeader->id) : null;
            $detalle = $sourceDetail ? AsistenciaDetalle::query()->lockForUpdate()->find($sourceDetail->id) : null;
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

            $actor = $usuario->fresh();
            if (!$actor || $actor->estado !== 'ACTIVO' || !PermissionMatrix::userCanDirect($actor, 'faltas', 'corregir') || !$this->policy->view($actor, $faltaBloqueada)) {
                return ['ok' => false, 'code' => 'FALTAS_FORBIDDEN', 'message' => 'No autorizado', 'forbidden' => true];
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

            if (! $detalle) {
                return [
                    'ok' => false,
                    'code' => 'ASISTENCIA_DETALLE_NOT_FOUND',
                    'message' => 'No existe el detalle de asistencia origen',
                ];
            }

            if (!$encabezado || $encabezado->grupo_trabajo_id !== $groupId || ($groupId && !$grupo)
                || $detalle->id !== $faltaBloqueada->asistencia_detalle_id || $detalle->asistencia_id !== $encabezado->id
                || ($faltaBloqueada->asistencia_encabezado_id && $faltaBloqueada->asistencia_encabezado_id !== $encabezado->id)
                || $faltaBloqueada->trabajador_id !== $detalle->trabajador_id) {
                return ['ok' => false, 'code' => 'FALTA_ASISTENCIA_CHANGED', 'message' => 'El origen de la falta cambió. Vuelve a consultar el registro.'];
            }

            $notaAuditoria = 'Corregido por falta '.$faltaBloqueada->id;
            $observaciones = trim((string) $detalle->observaciones);
            $detalle->fill([
                'estado' => 'PRESENTE',
                'hora_marcado' => ($payload['hora_marcado'] ?? now()->format('H:i')).':00',
                'observaciones' => $observaciones === '' ? $notaAuditoria : $observaciones.' | '.$notaAuditoria,
                'marcado_por_id' => $actor->id,
                'updated_by' => $actor->id,
                'marcado_at' => now(),
                'origen_registro' => 'CORRECCION_FALTA',
            ]);
            $detalle->save();

            $faltaBloqueada->fill([
                'estado' => 'CORREGIDA',
                'motivo_correccion' => $payload['motivo_correccion'],
                'corregido_por_usuario_id' => $actor->id,
                'corregido_at' => now(),
            ]);
            $faltaBloqueada->save();

            if ($grupo) {
                $this->metrics->recalculateGrupo($grupo, persist: true);
            }

            return ['ok' => true, 'falta' => $faltaBloqueada->fresh()];
        }, 3);
    }
}
