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
            'supervisor' => $this->whenLoaded('supervisor', fn (): array => [
                'id' => $this->supervisor?->id,
                'nombre_completo' => $this->supervisor?->nombre_completo,
                'puesto' => $this->supervisor?->puesto,
            ]),
            'personal' => $this->whenLoaded('detalle', function (): array {
                return $this->detalle->map(fn ($item): array => [
                    'personal_id' => $item->personal_id,
                    'nombre_completo' => $item->personal?->nombre_completo,
                    'puesto' => $item->personal?->puesto,
                    'estado_asistencia' => $item->estado_asistencia,
                    'hora_marcado' => $item->hora_marcado,
                ])->values()->all();
            }),
        ];
    }
}
