<?php

namespace App\Modules\Evaluaciones\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluacionResidenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => optional($this->fecha)->toDateString(),
            'destino_tipo' => $this->destino_tipo,
            'destino_id' => $this->destino_id,
            'indicadores_kpi' => $this->indicadores_kpi,
            'costos_servicio' => $this->costos_servicio,
            'eventos_seguridad' => $this->eventos_seguridad,
            'reportes_calidad' => $this->reportes_calidad,
            'liderazgo_gestion' => $this->liderazgo_gestion,
            'innovacion' => $this->innovacion,
            'total' => $this->total,
            'residente_id' => $this->residente_id,
            'evaluador_id' => $this->evaluador_id,
            'comentarios' => $this->comentarios,
        ];
    }
}
