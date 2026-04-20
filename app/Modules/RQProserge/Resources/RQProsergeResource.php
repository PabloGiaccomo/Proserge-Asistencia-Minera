<?php

namespace App\Modules\RQProserge\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RQProsergeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rq_mina_id' => $this->rq_mina_id,
            'mina_id' => $this->mina_id,
            'responsable_rrhh_id' => $this->responsable_rrhh_id,
            'estado' => $this->estado,
            'comentario_planner' => $this->comentario_planner,
            'comentario_rrhh' => $this->comentario_rrhh,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'mina' => $this->whenLoaded('mina', fn (): array => [
                'id' => $this->mina?->id,
                'nombre' => $this->mina?->nombre,
            ]),
            'rq_mina' => $this->whenLoaded('rqMina', fn (): array => [
                'id' => $this->rqMina?->id,
                'estado' => $this->rqMina?->estado,
            ]),
            'responsable_rrhh' => $this->whenLoaded('responsableRrhh', fn (): array => [
                'id' => $this->responsableRrhh?->id,
                'email' => $this->responsableRrhh?->email,
            ]),
            'detalle' => $this->whenLoaded('detalle', function (): array {
                return $this->detalle->map(fn ($item): array => [
                    'id' => $item->id,
                    'rq_mina_detalle_id' => $item->rq_mina_detalle_id,
                    'personal_id' => $item->personal_id,
                    'puesto_asignado' => $item->puesto_asignado,
                    'fecha_inicio' => optional($item->fecha_inicio)->toDateString(),
                    'fecha_fin' => optional($item->fecha_fin)->toDateString(),
                    'comentario' => $item->comentario,
                    'ultimo_turno_referencia' => $item->ultimo_turno_referencia,
                    'estado' => $item->estado,
                ])->values()->all();
            }),
        ];
    }
}
