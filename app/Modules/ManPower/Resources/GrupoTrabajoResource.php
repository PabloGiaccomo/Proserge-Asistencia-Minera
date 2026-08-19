<?php

namespace App\Modules\ManPower\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrupoTrabajoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => optional($this->fecha)->toDateString(),
            'turno' => $this->turno,
            'estado' => $this->estado,
            'rq_mina_id' => $this->rq_mina_id,
            'rq_proserge_id' => $this->rq_proserge_id,
            'rq_mina_plan_id' => $this->rq_mina_plan_id,
            'rq_mina_actividad_grupo_id' => $this->rq_mina_actividad_grupo_id,
            'supervisor_id' => $this->supervisor_id,
            'servicio' => $this->servicio,
            'area' => $this->area,
            'paradero' => $this->paradero,
            'paradero_link' => $this->paradero_link,
            'horario_salida' => $this->horario_salida,
            'destino' => [
                'tipo' => $this->destino_tipo ?? $this->unidad,
                'id' => $this->destino_id,
                'nombre' => $this->mina,
            ],
            'observaciones' => $this->observaciones,
            'planificacion' => [
                'codigo_grupo' => $this->codigo_grupo,
                'nombre_snapshot' => $this->nombre_snapshot,
                'area_snapshot' => $this->area_snapshot,
                'sector_snapshot' => $this->sector_snapshot,
                'modulo_snapshot' => $this->modulo_snapshot,
                'sait_snapshot' => $this->sait_snapshot,
                'supervisor_operativo_snapshot' => $this->supervisor_operativo_snapshot,
                'supervisor_seguridad_snapshot' => $this->supervisor_seguridad_snapshot,
                'cantidad_planificada_snapshot' => $this->cantidad_planificada_snapshot,
                'observacion_planificacion' => $this->observacion_planificacion,
                'justificacion_brecha' => $this->justificacion_brecha,
                'legacy' => !$this->rq_mina_plan_id || !$this->rq_mina_actividad_grupo_id,
            ],
            'metricas' => [
                'requerido' => (int) ($this->cantidad_planificada_snapshot ?? 0),
                'asignado' => $this->whenLoaded('detalle', fn () => $this->detalle->filter(fn ($item) => ($item->estado_distribucion ?? 'ASIGNADO') === 'ASIGNADO')->count()),
            ],
            'rq_mina' => $this->whenLoaded('rqMina', fn (): array => [
                'id' => $this->rqMina?->id,
                'mina_id' => $this->rqMina?->mina_id,
                'area' => $this->rqMina?->area,
                'mina' => [
                    'id' => $this->rqMina?->mina?->id,
                    'nombre' => $this->rqMina?->mina?->nombre,
                ],
            ]),
            'rq_proserge' => $this->whenLoaded('rqProserge', fn (): array => [
                'id' => $this->rqProserge?->id,
                'estado' => $this->rqProserge?->estado,
            ]),
            'plan' => $this->whenLoaded('plan', fn (): array => [
                'id' => $this->plan?->id,
                'codigo' => $this->plan?->codigo,
                'nombre' => $this->plan?->nombre,
                'estado' => $this->plan?->estado,
            ]),
            'grupo_operativo' => $this->whenLoaded('grupoOperativo', fn (): array => [
                'id' => $this->grupoOperativo?->id,
                'nombre' => $this->grupoOperativo?->nombre,
                'area' => $this->grupoOperativo?->area_operativa,
                'modulo' => $this->grupoOperativo?->modulo,
            ]),
            'actividades' => $this->whenLoaded('actividades', function (): array {
                return $this->actividades->map(fn ($item): array => [
                    'id' => $item->id,
                    'cantidad_planificada_snapshot' => $item->pivot?->cantidad_planificada_snapshot,
                ])->values()->all();
            }),
            'supervisor' => $this->whenLoaded('supervisor', fn (): array => [
                'id' => $this->supervisor?->id,
                'nombre_completo' => $this->supervisor?->nombre_completo,
                'puesto' => $this->supervisor?->puesto,
            ]),
            'personal' => $this->whenLoaded('detalle', function (): array {
                return $this->detalle->map(function ($item): array {
                    $actividadPrincipal = $item->actividadesPrincipales?->firstWhere('es_principal', true);

                    return [
                        'id' => $item->id,
                        'personal_id' => $item->personal_id,
                        'rq_proserge_detalle_id' => $item->rq_proserge_detalle_id,
                        'nombre_completo' => $item->personal?->nombre_completo,
                        'puesto' => $item->personal?->puesto,
                        'cargo' => $item->puesto_asignado_snapshot,
                        'posicion' => $item->posicion_asignacion_snapshot,
                        'tipo' => $item->tipo_asignacion_snapshot,
                        'habilitacion' => $item->estado_habilitacion_snapshot,
                        'estado_distribucion' => $item->estado_distribucion ?? 'ASIGNADO',
                        'motivo_retiro' => $item->motivo_retiro,
                        'tiene_asistencia' => false,
                        'estado_asistencia' => $item->estado_asistencia,
                        'hora_marcado' => $item->hora_marcado,
                        'actividad_principal' => $actividadPrincipal ? [
                            'id' => $actividadPrincipal->rq_mina_actividad_id,
                            'sait' => $actividadPrincipal->actividad?->sait,
                            'area' => $actividadPrincipal->actividad?->area,
                            'sector' => $actividadPrincipal->actividad?->sector,
                            'ait_trabajo' => $actividadPrincipal->actividad?->ait_trabajo,
                        ] : null,
                    ];
                })->values()->all();
            }),
        ];
    }
}
