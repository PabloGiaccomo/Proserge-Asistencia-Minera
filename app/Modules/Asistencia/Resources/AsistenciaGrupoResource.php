<?php

namespace App\Modules\Asistencia\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaGrupoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grupo_id' => $this->id,
            'fecha' => optional($this->fecha)->toDateString(),
            'turno' => $this->turno,
            'estado_grupo' => $this->estado,
            'mina_id' => $this->rqMina?->mina_id,
            'mina_nombre' => $this->rqMina?->mina?->nombre,
            'destino' => [
                'tipo' => $this->destino_tipo ?? $this->unidad,
                'id' => $this->destino_id,
                'nombre' => $this->mina,
            ],
            'supervisor' => [
                'id' => $this->supervisor?->id,
                'nombre_completo' => $this->supervisor?->nombre_completo,
            ],
            'asistencia' => [
                'id' => $this->asistencia?->id,
                'estado' => $this->asistencia?->estado ?? 'PENDIENTE',
                'hora_ingreso' => $this->asistencia?->hora_ingreso,
                'destino_tipo' => $this->asistencia?->destino_tipo,
                'destino_id' => $this->asistencia?->destino_id,
                'actividad_realizada' => $this->asistencia?->actividad_realizada,
                'reporte_suceso' => $this->asistencia?->reporte_suceso,
            ],
            'integrantes' => $this->detalle->map(function ($item): array {
                $marca = $this->asistencia?->detalle?->firstWhere('trabajador_id', $item->personal_id);

                return [
                    'personal_id' => $item->personal_id,
                    'nombre_completo' => $item->personal?->nombre_completo,
                    'puesto' => $item->personal?->puesto,
                    'estado_asistencia' => $marca?->estado ?? 'PENDIENTE',
                    'hora_marcado' => $marca?->hora_marcado,
                    'observaciones' => $marca?->observaciones,
                ];
            })->values()->all(),
        ];
    }
}
