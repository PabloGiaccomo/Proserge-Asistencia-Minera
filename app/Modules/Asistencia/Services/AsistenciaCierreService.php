<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\Falta;
use App\Models\GrupoTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AsistenciaCierreService
{
    public function cerrar(GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado, array $payload): array
    {
        if ($encabezado->estado === 'CERRADO') {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_ALREADY_CLOSED',
                'message' => 'Asistencia ya cerrada',
            ];
        }

        DB::transaction(function () use ($grupo, $encabezado, $payload): void {
            $this->ensureDetalleCompleto($grupo, $encabezado);

            $encabezado->fill([
                'estado' => 'CERRADO',
                'actividad_realizada' => $payload['actividad_realizada'] ?? $encabezado->actividad_realizada,
                'reporte_suceso' => $payload['reporte_suceso'] ?? $encabezado->reporte_suceso,
            ]);
            $encabezado->save();

            $ausentes = AsistenciaDetalle::query()
                ->where('asistencia_id', $encabezado->id)
                ->where('estado', 'AUSENTE')
                ->get(['id', 'trabajador_id']);

            foreach ($ausentes as $item) {
                $trabajadorId = (string) $item->trabajador_id;
                $exists = Falta::query()
                    ->where('asistencia_detalle_id', $item->id)
                    ->where('motivo', 'INASISTENCIA_ASISTENCIA')
                    ->orWhere(function ($q) use ($trabajadorId, $encabezado): void {
                        $q->where('trabajador_id', $trabajadorId)
                            ->where('fecha', $encabezado->fecha->toDateString())
                            ->where('motivo', 'INASISTENCIA_ASISTENCIA');
                    })
                    ->exists();

                if ($exists) {
                    continue;
                }

                Falta::query()->create([
                    'id' => (string) Str::uuid(),
                    'trabajador_id' => $trabajadorId,
                    'fecha' => $encabezado->fecha->toDateString(),
                    'motivo' => 'INASISTENCIA_ASISTENCIA',
                    'descripcion' => 'Generada automaticamente al cierre de asistencia',
                    'observaciones' => 'grupo_trabajo_id='.$grupo->id,
                    'estado' => 'ACTIVA',
                    'registrada_por_id' => $grupo->supervisor_id,
                    'asistencia_encabezado_id' => $encabezado->id,
                    'asistencia_detalle_id' => $item->id,
                    'destino_tipo' => $encabezado->destino_tipo,
                    'destino_id' => $encabezado->destino_id,
                ]);
            }
        });

        return ['ok' => true];
    }

    public function reabrir(GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado): array
    {
        if ($encabezado->estado !== 'CERRADO') {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_NOT_CLOSED',
                'message' => 'Asistencia no esta cerrada',
            ];
        }

        $missingMembers = DB::table('grupo_trabajo_detalle as gtd')
            ->leftJoin('asistencia_detalle as ad', function ($join) use ($encabezado): void {
                $join->on('ad.trabajador_id', '=', 'gtd.personal_id')
                    ->where('ad.asistencia_id', '=', $encabezado->id);
            })
            ->where('gtd.grupo_trabajo_id', $grupo->id)
            ->whereNull('ad.id')
            ->count();

        if ($missingMembers === 0) {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_REOPEN_NOT_REQUIRED',
                'message' => 'No hay nuevos integrantes para reabrir asistencia',
            ];
        }

        $encabezado->fill(['estado' => 'REGISTRADO']);
        $encabezado->save();

        return ['ok' => true];
    }

    private function ensureDetalleCompleto(GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado): void
    {
        $integrantes = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupo->id)
            ->pluck('personal_id');

        foreach ($integrantes as $personalId) {
            $exists = AsistenciaDetalle::query()
                ->where('asistencia_id', $encabezado->id)
                ->where('trabajador_id', $personalId)
                ->exists();

            if ($exists) {
                continue;
            }

            AsistenciaDetalle::query()->create([
                'id' => (string) Str::uuid(),
                'asistencia_id' => $encabezado->id,
                'trabajador_id' => $personalId,
                'hora_marcado' => '00:00:00',
                'estado' => 'AUSENTE',
                'observaciones' => 'Marcado automatico al cierre',
            ]);
        }
    }
}
