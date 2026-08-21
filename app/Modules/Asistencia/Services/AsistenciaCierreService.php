<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\Falta;
use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AsistenciaCierreService
{
    public function cerrar(Usuario $usuario, GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado, array $payload): array
    {
        if ($encabezado->estado === 'CERRADO') {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_ALREADY_CLOSED',
                'message' => 'Asistencia ya cerrada',
            ];
        }

        $registradorId = $usuario->personal_id ?: $encabezado->supervisor_id ?: $grupo->supervisor_id;
        if (blank($registradorId)) {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_REGISTRAR_REQUIRED',
                'message' => 'Vincula la cuenta con un trabajador antes de cerrar la asistencia.',
            ];
        }

        DB::transaction(function () use ($grupo, $encabezado, $payload, $registradorId): void {
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
                    ->where(function ($q) use ($item, $trabajadorId, $encabezado): void {
                        $q->where(function ($detailQuery) use ($item): void {
                            $detailQuery->where('asistencia_detalle_id', $item->id)
                                ->where('motivo', 'INASISTENCIA_ASISTENCIA');
                        })->orWhere(function ($legacyQuery) use ($trabajadorId, $encabezado): void {
                            $legacyQuery->where('trabajador_id', $trabajadorId)
                                ->where('fecha', $encabezado->fecha->toDateString())
                                ->where('motivo', 'INASISTENCIA_ASISTENCIA');
                        });
                    })
                    ->where('estado', 'ACTIVA')
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
                    'registrada_por_id' => $registradorId,
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

        DB::transaction(function () use ($grupo, $encabezado): void {
            $encabezado->fill(['estado' => 'REGISTRADO']);
            $encabezado->save();

            Falta::query()
                ->where('asistencia_encabezado_id', $encabezado->id)
                ->where('motivo', 'INASISTENCIA_ASISTENCIA')
                ->where('estado', 'ACTIVA')
                ->update([
                    'estado' => 'ANULADA',
                    'motivo_anulacion' => 'Reapertura de asistencia',
                    'anulado_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->ensureDetalleCompleto($grupo, $encabezado);
        });

        return ['ok' => true];
    }

    private function ensureDetalleCompleto(GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado): void
    {
        $integrantes = GrupoTrabajoDetalle::query()
            ->with(['personal'])
            ->where('grupo_trabajo_id', $grupo->id)
            ->get()
            ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva());

        foreach ($integrantes as $integrante) {
            $exists = AsistenciaDetalle::query()
                ->where('asistencia_id', $encabezado->id)
                ->when(
                    Schema::hasColumn('asistencia_detalle', 'grupo_trabajo_detalle_id'),
                    fn ($query) => $query->where('grupo_trabajo_detalle_id', $integrante->id),
                    fn ($query) => $query->where('trabajador_id', $integrante->personal_id),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            AsistenciaDetalle::query()->create($this->filterColumns('asistencia_detalle', [
                'id' => (string) Str::uuid(),
                'asistencia_id' => $encabezado->id,
                'grupo_trabajo_detalle_id' => $integrante->id,
                'rq_proserge_detalle_id' => $integrante->rq_proserge_detalle_id,
                'trabajador_id' => $integrante->personal_id,
                'puesto_snapshot' => $integrante->puesto_asignado_snapshot ?: $integrante->personal?->puesto,
                'posicion_asignacion_snapshot' => $integrante->posicion_asignacion_snapshot,
                'tipo_asignacion_snapshot' => $integrante->tipo_asignacion_snapshot,
                'estado_distribucion_snapshot' => $integrante->estado_distribucion ?: GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
                'hora_marcado' => '00:00:00',
                'estado' => AsistenciaDetalle::ESTADO_AUSENTE,
                'observaciones' => 'Marcado automatico al cierre',
                'origen_registro' => 'SISTEMA',
            ]));
        }
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
