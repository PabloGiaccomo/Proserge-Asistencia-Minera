<?php

namespace App\Modules\Evaluaciones\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluacionSupervisorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluador_id' => $this->evaluador_id,
            'evaluado_id' => $this->evaluado_id,
            'fecha' => optional($this->fecha)->toDateString(),
            'mina_id' => $this->mina_id,
            'grupo_trabajo_id' => $this->grupo_trabajo_id,
            'asistencia_encabezado_id' => $this->asistencia_encabezado_id,
            'destino_tipo' => $this->destino_tipo,
            'destino_id' => $this->destino_id,
            'puntaje_final' => (float) $this->resultado_final,
            'comentarios_finales' => $this->comentarios_finales,
            'aspectos_positivos' => $this->aspectos_positivos,
            'capacitaciones_recomendadas' => $this->capacitaciones_recomendadas,
            'firma' => $this->firma_supervisor,
            'respuestas' => $this->respuestas,
            'estado' => $this->estado,
            'created_by_usuario_id' => $this->created_by_usuario_id,
            'updated_by_usuario_id' => $this->updated_by_usuario_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
