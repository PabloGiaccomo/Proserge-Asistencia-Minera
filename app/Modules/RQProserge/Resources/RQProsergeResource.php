<?php

namespace App\Modules\RQProserge\Resources;

use App\Models\RQMinaDetalle;
use App\Modules\RQProserge\Services\RQProsergeCoverageService;
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
                    'posicion_asignacion' => $item->posicion_asignacion,
                    'tipo_asignacion' => $item->tipo_asignacion,
                    'estado' => $item->estado,
                    'estado_habilitacion_snapshot' => $item->estado_habilitacion_snapshot,
                    'disponibilidad' => [
                        'available' => (bool) data_get($item->disponibilidad_snapshot, 'available', true),
                        'reason_code' => data_get($item->disponibilidad_snapshot, 'reason_code'),
                        'reason_message' => data_get($item->disponibilidad_snapshot, 'reason_message'),
                        'mina_estado' => data_get($item->disponibilidad_snapshot, 'mina_estado'),
                    ],
                    'es_sin_clasificar' => $item->isSinClasificar(),
                    'cuenta_como_titular' => $item->cuentaComoTitular(),
                    'cuenta_como_respaldo' => $item->cuentaComoRespaldo(),
                    'asignado_por' => $item->relationLoaded('asignadoPor') && $item->asignadoPor ? [
                        'id' => $item->asignadoPor->id,
                        'email' => $item->asignadoPor->email,
                    ] : null,
                    'asignado_at' => optional($item->asignado_at)->toIso8601String(),
                    'retirado_at' => optional($item->retirado_at)->toIso8601String(),
                    'motivo_retiro' => $item->motivo_retiro,
                    'reemplaza_a_id' => $item->reemplaza_a_id,
                ])->values()->all();
            }),
            'cobertura' => $this->coveragePayload(),
        ];
    }

    private function coveragePayload(): array
    {
        if (!$this->rq_mina_id) {
            return [];
        }

        $coverage = app(RQProsergeCoverageService::class)->calculateForRq($this->resource);

        return [
            'estado' => $coverage['estado'],
            'resumen' => $coverage['global'],
            'detalles' => collect($coverage['detalles'])->map(function (array $metrics, string $detalleId): array {
                $detalle = RQMinaDetalle::query()->select(['id', 'puesto', 'cantidad', 'cantidad_backup', 'cantidad_total'])->find($detalleId);

                return [
                    'rq_mina_detalle_id' => $detalleId,
                    'cargo_solicitado' => $detalle?->puesto,
                    'cantidades' => [
                        'titulares' => (int) ($metrics['titular_objetivo'] ?? 0),
                        'respaldo' => (int) ($metrics['respaldo_objetivo'] ?? 0),
                        'total' => (int) ($metrics['total_objetivo'] ?? 0),
                    ],
                    'metricas' => $metrics,
                ];
            })->values()->all(),
        ];
    }
}
