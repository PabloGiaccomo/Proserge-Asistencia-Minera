<?php

namespace App\Modules\Faltas\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaltaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trabajador_id' => $this->trabajador_id,
            'fecha' => $this->fecha,
            'motivo' => $this->motivo,
            'descripcion' => $this->descripcion,
            'observaciones' => $this->observaciones,
            'estado' => $this->estado,
            'destino_tipo' => $this->destino_tipo,
            'destino_id' => $this->destino_id,
            'asistencia_encabezado_id' => $this->asistencia_encabezado_id,
            'asistencia_detalle_id' => $this->asistencia_detalle_id,
            'grupo_trabajo_id' => $this->grupo_trabajo_id,
            'motivo_correccion' => $this->motivo_correccion,
            'motivo_anulacion' => $this->motivo_anulacion,
            'corregido_por_usuario_id' => $this->corregido_por_usuario_id,
            'anulado_por_usuario_id' => $this->anulado_por_usuario_id,
            'corregido_at' => optional($this->corregido_at)->toIso8601String(),
            'anulado_at' => optional($this->anulado_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
