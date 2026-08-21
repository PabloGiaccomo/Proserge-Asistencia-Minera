<?php

namespace App\Modules\Asistencia\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaGrupoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grupo_id' => $this->id,
            'fecha' => optional($this->fecha)->toDateString(),
            'turno' => $this->turno,
            'horario_salida' => $this->horario_salida,
            'servicio' => $this->servicio,
            'area' => $this->area,
            'estado_grupo' => $this->estado,
            'mina_id' => $this->rqMina?->mina_id,
            'mina_nombre' => $this->rqMina?->mina?->nombre,
            'destino' => [
                'tipo' => $this->destino_tipo ?? $this->unidad,
                'id' => $this->destino_id,
                'nombre' => $this->mina,
            ],
            'supervisor' => [
                'id' => $this->supervisor?->id,
                'nombre_completo' => $this->supervisor?->nombre_completo,
            ],
            'asistencia' => [
                'id' => $this->asistencia?->id,
                'estado' => $this->asistencia?->estado ?? 'PENDIENTE',
                'hora_ingreso' => $this->asistencia?->hora_ingreso,
                'destino_tipo' => $this->asistencia?->destino_tipo,
                'destino_id' => $this->asistencia?->destino_id,
                'actividad_realizada' => $this->asistencia?->actividad_realizada,
                'reporte_suceso' => $this->asistencia?->reporte_suceso,
                'responsable_registro' => $this->asistencia?->supervisor?->nombre_completo,
                'metricas' => $this->metricasAsistencia(),
            ],
            'integrantes' => $this->detalle->map(function ($item): array {
                $marca = $this->asistencia?->detalle?->firstWhere('grupo_trabajo_detalle_id', $item->id)
                    ?: $this->asistencia?->detalle?->firstWhere('trabajador_id', $item->personal_id);
                $actividadPrincipal = $item->actividadesPrincipales?->firstWhere('es_principal', true);

                return [
                    'grupo_trabajo_detalle_id' => $item->id,
                    'asistencia_detalle_id' => $marca?->id,
                    'rq_proserge_detalle_id' => $marca?->rq_proserge_detalle_id ?? $item->rq_proserge_detalle_id,
                    'personal_id' => $item->personal_id,
                    'nombre_completo' => $item->personal?->nombre_completo,
                    'dni' => $item->personal?->dni ?? $item->personal?->numero_documento,
                    'puesto' => $marca?->puesto_snapshot ?? $item->puesto_asignado_snapshot ?? $item->personal?->puesto,
                    'posicion_asignacion' => $marca?->posicion_asignacion_snapshot ?? $item->posicion_asignacion_snapshot ?? 'SIN CLASIFICAR',
                    'tipo_asignacion' => $marca?->tipo_asignacion_snapshot ?? $item->tipo_asignacion_snapshot ?? 'SIN CLASIFICAR',
                    'estado_distribucion' => $marca?->estado_distribucion_snapshot ?? $item->estado_distribucion,
                    'estado_asistencia' => $marca?->estado ?? 'PENDIENTE',
                    'hora_marcado' => $marca?->hora_marcado,
                    'marcado_at' => optional($marca?->marcado_at)->toDateTimeString(),
                    'marcado_por' => $marca?->marcador?->personal?->nombre_completo ?? $marca?->marcador?->email,
                    'observaciones' => $marca?->observaciones,
                    'motivo_estado' => $marca?->motivo_estado,
                    'es_legacy' => $marca?->isLegacy() ?? false,
                    'actividad_principal' => $actividadPrincipal ? [
                        'id' => $actividadPrincipal->rq_mina_actividad_id,
                        'sait' => $actividadPrincipal->actividad?->sait,
                        'area' => $actividadPrincipal->actividad?->area,
                        'sector' => $actividadPrincipal->actividad?->sector,
                        'ait_trabajo' => $actividadPrincipal->actividad?->ait_trabajo,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    private function metricasAsistencia(): array
    {
        $detalles = $this->asistencia?->detalle ?? collect();
        $programado = $this->detalle->filter(fn ($item): bool => $item->isDistribucionActiva())->count();
        $presentes = $detalles->whereIn('estado', ['PRESENTE', 'TARDANZA'])->count();

        return [
            'programado' => $programado,
            'presentes' => $presentes,
            'ausentes' => $detalles->where('estado', 'AUSENTE')->count(),
            'tardanzas' => $detalles->where('estado', 'TARDANZA')->count(),
            'justificados' => $detalles->where('estado', 'JUSTIFICADO')->count(),
            'no_corresponde' => $detalles->where('estado', 'NO_CORRESPONDE')->count(),
            'cobertura' => $programado > 0 ? round(($presentes / $programado) * 100, 2) : 0,
        ];
    }
}
