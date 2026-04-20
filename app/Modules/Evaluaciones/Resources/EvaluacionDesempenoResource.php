<?php

namespace App\Modules\Evaluaciones\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluacionDesempenoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => optional($this->fecha)->toDateString(),
            'hora' => $this->hora,
            'mina_id' => $this->mina_id,
            'grupo_trabajo_id' => $this->grupo_trabajo_id,
            'trabajador_id' => $this->trabajador_id,
            'supervisor_id' => $this->supervisor_id,
            'semana_parada' => $this->semana_parada,
            'desempeno_trabajo' => $this->desempeno_trabajo,
            'orden_limpieza' => $this->orden_limpieza,
            'compromiso' => $this->compromiso,
            'respuesta_emocional' => $this->respuesta_emocional,
            'seguridad_trabajo' => $this->seguridad_trabajo,
            'total' => $this->total,
            'observaciones' => $this->observaciones,
            'tuvo_incidencia' => (bool) $this->tuvo_incidencia,
            'descripcion_incidencia' => $this->descripcion_incidencia,
            'asistencia_encabezado_id' => $this->asistencia_encabezado_id,
            'asistencia_detalle_id' => $this->asistencia_detalle_id,
            'destino_tipo' => $this->destino_tipo,
            'destino_id' => $this->destino_id,
            'evaluado_por_usuario_id' => $this->evaluado_por_usuario_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
