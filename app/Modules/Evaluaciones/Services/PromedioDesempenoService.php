<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\EvaluacionDesempeno;
use App\Models\PromedioDesempeno;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromedioDesempenoService
{
    public function refreshForTrabajador(string $trabajadorId): void
    {
        $agg = EvaluacionDesempeno::query()
            ->where('trabajador_id', $trabajadorId)
            ->selectRaw('COUNT(*) as total, AVG(total) as promedio, MAX(fecha) as ultima')
            ->first();

        if (!$agg || (int) $agg->total === 0) {
            return;
        }

        $existing = PromedioDesempeno::query()->where('trabajador_id', $trabajadorId)->first();

        if ($existing) {
            $existing->fill([
                'cantidad_evaluaciones' => (int) $agg->total,
                'promedio_total' => round((float) $agg->promedio, 2),
                'ultima_evaluacion' => $agg->ultima,
            ])->save();

            return;
        }

        PromedioDesempeno::query()->create([
            'id' => (string) Str::uuid(),
            'trabajador_id' => $trabajadorId,
            'cantidad_evaluaciones' => (int) $agg->total,
            'promedio_total' => round((float) $agg->promedio, 2),
            'ultima_evaluacion' => $agg->ultima,
        ]);
    }

    public function list(array $filters): array
    {
        $query = EvaluacionDesempeno::query();

        if (!empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('fecha', '>=', $filters['fecha_desde']);
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('fecha', '<=', $filters['fecha_hasta']);
        }

        if (!empty($filters['trabajador_id'])) {
            $query->where('trabajador_id', $filters['trabajador_id']);
        }

        return $query
            ->selectRaw('trabajador_id, COUNT(*) as cantidad_evaluaciones, ROUND(AVG(total), 2) as promedio_total, MAX(fecha) as ultima_evaluacion')
            ->groupBy('trabajador_id')
            ->orderByDesc('promedio_total')
            ->get()
            ->map(fn ($row): array => [
                'trabajador_id' => $row->trabajador_id,
                'cantidad_evaluaciones' => (int) $row->cantidad_evaluaciones,
                'promedio_total' => (float) $row->promedio_total,
                'ultima_evaluacion' => $row->ultima_evaluacion,
            ])
            ->values()
            ->all();
    }

    public function comparacion(array $filters): array
    {
        $query = EvaluacionDesempeno::query();

        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('fecha', '>=', $filters['fecha_desde']);
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('fecha', '<=', $filters['fecha_hasta']);
        }

        if (!empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (!empty($filters['trabajador_ids'])) {
            $query->whereIn('trabajador_id', $filters['trabajador_ids']);
        }

        return $query
            ->selectRaw('trabajador_id, ROUND(AVG(total),2) as promedio_total, COUNT(*) as evaluaciones')
            ->groupBy('trabajador_id')
            ->orderByDesc('promedio_total')
            ->get()
            ->map(fn ($r): array => [
                'trabajador_id' => $r->trabajador_id,
                'promedio_total' => (float) $r->promedio_total,
                'evaluaciones' => (int) $r->evaluaciones,
            ])
            ->values()
            ->all();
    }
}
