<?php

namespace App\Modules\RQMina\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RQMinaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mina_id' => $this->mina_id,
            'destino_tipo' => $this->destino_tipo ?? 'MINA',
            'destino_id' => $this->destino_id ?? $this->mina_id,
            'destino_nombre' => $this->destino_nombre ?? $this->mina?->nombre,
            'area' => $this->area,
            'fecha_inicio' => optional($this->fecha_inicio)->toDateString(),
            'fecha_fin' => optional($this->fecha_fin)->toDateString(),
            'observaciones' => $this->observaciones,
            'estado' => $this->estado,
            'supervisor_id' => $this->supervisor_id,
            'created_by_usuario_id' => $this->created_by_usuario_id,
            'enviado_at' => optional($this->enviado_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'mina' => $this->whenLoaded('mina', fn (): array => [
                'id' => $this->mina?->id,
                'nombre' => $this->mina?->nombre,
            ]),
            'creador' => $this->whenLoaded('creador', fn (): array => [
                'id' => $this->creador?->id,
                'email' => $this->creador?->email,
                'nombre' => $this->creador?->personal?->nombre_completo ?? $this->creador?->name ?? $this->creador?->email,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn (): ?array => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'nombre' => $this->supervisor->nombre_completo,
                'dni' => $this->supervisor->dni,
                'puesto' => $this->supervisor->puesto,
                'es_supervisor' => (bool) $this->supervisor->es_supervisor,
            ] : null),
            'detalle' => $this->whenLoaded('detalle', function (): array {
                return $this->detalle->map(fn ($item): array => [
                    'id' => $item->id,
                    'puesto' => $item->puesto,
                    'cantidad' => (int) $item->cantidad,
                    'cantidad_atendida' => (int) $item->cantidad_atendida,
                ])->values()->all();
            }),
            'transporte' => $this->whenLoaded('transportes', function (): array {
                return $this->transportes->map(fn ($item): array => [
                    'id' => $item->id,
                    'transporte' => $item->transporte,
                    'cantidad' => (int) $item->cantidad,
                ])->values()->all();
            }),
            'plan_operativo' => $this->whenLoaded('actividadGrupos', function (): array {
                return $this->actividadGrupos->map(fn ($group): array => [
                    'id' => $group->id,
                    'area_operativa' => $group->area_operativa,
                    'modulo' => $group->modulo,
                    'nombre' => $group->nombre,
                    'observaciones' => $group->observaciones,
                    'actividades' => $group->actividades->map(fn ($activity): array => [
                        'id' => $activity->id,
                        'sait' => $activity->sait,
                        'sector' => $activity->sector,
                        'area' => $activity->area,
                        'ait_trabajo' => $activity->ait_trabajo,
                        'detalle_trabajos_relevantes' => $activity->detalle_trabajos_relevantes,
                        'supervisor_campo_dia' => $activity->supervisor_campo_dia,
                        'supervisor_campo_noche' => $activity->supervisor_campo_noche,
                        'supervisor_seguridad_dia' => $activity->supervisor_seguridad_dia,
                        'supervisor_seguridad_noche' => $activity->supervisor_seguridad_noche,
                        'turnos' => $activity->turnos->map(fn ($turno): array => [
                            'fecha' => optional($turno->fecha)->toDateString(),
                            'dia_label' => $turno->dia_label,
                            'turno_a' => $turno->turno_a,
                            'real_turno_a' => $turno->real_turno_a,
                            'turno_b' => $turno->turno_b,
                            'real_turno_b' => $turno->real_turno_b ?? $turno->real,
                            'real' => $turno->real_turno_b ?? $turno->real,
                        ])->values()->all(),
                    ])->values()->all(),
                    'transportes' => $group->transportes->map(fn ($transport): array => [
                        'id' => $transport->id,
                        'actividad_id' => $transport->actividad_id,
                        'alcance' => $transport->alcance,
                        'unidad_carga' => $transport->unidad_carga,
                        'unidades_transporte' => $transport->unidades_transporte,
                        'indicaciones' => $transport->indicaciones,
                    ])->values()->all(),
                ])->values()->all();
            }),
        ];
    }
}
