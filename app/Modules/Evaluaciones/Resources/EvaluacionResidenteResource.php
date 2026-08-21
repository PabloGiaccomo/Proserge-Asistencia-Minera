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
            'periodo_mes' => optional($this->periodo_mes)->toDateString(),
            'destino_tipo' => $this->destino_tipo,
            'destino_id' => $this->destino_id,
            'indicadores_kpi' => $this->indicadores_kpi,
            'indicadores_kpi_items' => $this->indicadores_kpi_items ?? [],
            'costos_servicio' => $this->costos_servicio,
            'costos_servicio_items' => $this->costos_servicio_items ?? [],
            'eventos_seguridad' => $this->eventos_seguridad,
            'eventos_seguridad_respuesta' => $this->eventos_seguridad_respuesta,
            'reportes_calidad' => $this->reportes_calidad,
            'reportes_calidad_respuesta' => $this->reportes_calidad_respuesta,
            'liderazgo_gestion' => $this->liderazgo_gestion,
            'liderazgo_gestion_innovacion' => $this->liderazgo_gestion_innovacion,
            'innovacion' => $this->innovacion,
            'total' => $this->total,
            'residente_id' => $this->residente_id,
            'evaluador_id' => $this->evaluador_id,
            'comentarios' => $this->comentarios,
            'estado' => $this->estado,
            'created_by_usuario_id' => $this->created_by_usuario_id,
        ];
    }
}
