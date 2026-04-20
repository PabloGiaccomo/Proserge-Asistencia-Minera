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
            'area' => $this->area,
            'fecha_inicio' => optional($this->fecha_inicio)->toDateString(),
            'fecha_fin' => optional($this->fecha_fin)->toDateString(),
            'observaciones' => $this->observaciones,
            'estado' => $this->estado,
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
            ]),
            'detalle' => $this->whenLoaded('detalle', function (): array {
                return $this->detalle->map(fn ($item): array => [
                    'id' => $item->id,
                    'puesto' => $item->puesto,
                    'cantidad' => (int) $item->cantidad,
                    'cantidad_atendida' => (int) $item->cantidad_atendida,
                ])->values()->all();
            }),
        ];
    }
}
