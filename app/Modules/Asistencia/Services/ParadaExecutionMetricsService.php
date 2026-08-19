<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\GrupoTrabajo;
use App\Models\ParadaEjecucionResumen;
use App\Models\RQMinaActividadTurno;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ParadaExecutionMetricsService
{
    public function recalculateGrupo(GrupoTrabajo|string|null $grupo, bool $persist = true, bool $dryRun = false): array
    {
        $grupo = $grupo instanceof GrupoTrabajo ? $grupo : GrupoTrabajo::query()->find($grupo);

        if (!$grupo || !$grupo->rq_mina_id || !Schema::hasTable('parada_ejecucion_resumen')) {
            return [
                'ok' => false,
                'omitida' => true,
                'reason' => 'Grupo sin RQ Mina o tabla de resumen no disponible',
                'rows' => [],
            ];
        }

        $grupo->load([
            'asistencia.detalle.grupoTrabajoDetalle.actividadesPrincipales',
            'detalle.actividadesPrincipales',
            'actividades.turnos',
        ]);

        $activeDetails = $grupo->detalle->filter(fn ($detalle): bool => $detalle->isDistribucionActiva())->values();
        $asistencia = $grupo->asistencia;
        $attendanceDetails = $asistencia?->detalle ?? collect();
        $activityIds = $grupo->actividades->pluck('id')->filter()->unique()->values();

        $rows = [];
        $rows[] = $this->buildRow($grupo, null, $activeDetails, $attendanceDetails, $activityIds, $asistencia);

        foreach ($activityIds as $activityId) {
            $assignedGtdIds = $this->principalActivityAssignments((string) $activityId);
            $rows[] = $this->buildRow(
                $grupo,
                (string) $activityId,
                $activeDetails->whereIn('id', $assignedGtdIds)->values(),
                $attendanceDetails->whereIn('grupo_trabajo_detalle_id', $assignedGtdIds)->values(),
                collect([(string) $activityId]),
                $asistencia,
            );
        }

        if ($persist && !$dryRun) {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    $lookup = [
                        'rq_mina_plan_id' => $row['rq_mina_plan_id'],
                        'rq_mina_actividad_grupo_id' => $row['rq_mina_actividad_grupo_id'],
                        'actividad_key' => $row['actividad_key'],
                        'fecha' => $row['fecha'],
                        'turno' => $row['turno'],
                    ];

                    ParadaEjecucionResumen::query()->updateOrCreate(
                        $lookup,
                        array_merge(['id' => $this->existingResumenId($lookup) ?: (string) Str::uuid()], $row),
                    );
                }
            });
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'rows' => $rows,
        ];
    }

    public function recalculateAll(array $filters = [], bool $dryRun = false): array
    {
        $query = GrupoTrabajo::query()->whereNotNull('rq_mina_id');

        if (!empty($filters['rq_mina_id'])) {
            $query->where('rq_mina_id', $filters['rq_mina_id']);
        }

        if (!empty($filters['rq_mina_plan_id'])) {
            $query->where('rq_mina_plan_id', $filters['rq_mina_plan_id']);
        }

        $result = [
            'grupos_revisados' => 0,
            'filas_calculadas' => 0,
            'filas_actualizadas' => 0,
            'omitidas' => 0,
            'errores' => [],
        ];

        $query->orderBy('id')->chunkById(100, function (Collection $grupos) use (&$result, $dryRun): void {
            foreach ($grupos as $grupo) {
                $result['grupos_revisados']++;

                try {
                    $summary = $this->recalculateGrupo($grupo, persist: true, dryRun: $dryRun);

                    if (($summary['omitida'] ?? false) === true) {
                        $result['omitidas']++;
                        continue;
                    }

                    $count = count($summary['rows'] ?? []);
                    $result['filas_calculadas'] += $count;
                    $result['filas_actualizadas'] += $dryRun ? 0 : $count;
                } catch (\Throwable $exception) {
                    $result['errores'][] = [
                        'grupo_trabajo_id' => $grupo->id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }, 'id');

        return $result;
    }

    private function buildRow(GrupoTrabajo $grupo, ?string $activityId, Collection $programmedDetails, Collection $attendanceDetails, Collection $planActivityIds, $asistencia): array
    {
        $realStates = [AsistenciaDetalle::ESTADO_PRESENTE, AsistenciaDetalle::ESTADO_TARDANZA];
        $realDetails = $attendanceDetails->whereIn('estado', $realStates)->values();
        $programado = $programmedDetails->count();
        $presentes = $realDetails->count();
        $planificado = $this->planificado($grupo, $planActivityIds);

        $titulares = $this->countPresenceBySnapshot($realDetails, 'posicion_asignacion_snapshot', 'TITULAR');
        $suplentes = $this->countPresenceBySnapshot($realDetails, 'posicion_asignacion_snapshot', 'SUPLENTE');
        $adicionales = $this->countPresenceBySnapshot($realDetails, 'tipo_asignacion_snapshot', 'ADICIONAL');
        $sinClasificar = max(0, $presentes - $titulares - $suplentes);
        $personalSinActividad = $activityId
            ? 0
            : $realDetails->filter(fn ($detalle): bool => !$this->detalleTieneActividadPrincipal($detalle))->count();

        return [
            'rq_mina_id' => $grupo->rq_mina_id,
            'rq_mina_plan_id' => $grupo->rq_mina_plan_id,
            'rq_mina_actividad_grupo_id' => $grupo->rq_mina_actividad_grupo_id,
            'rq_mina_actividad_id' => $activityId,
            'actividad_key' => $activityId ?: '__GRUPO__',
            'fecha' => $grupo->fecha?->toDateString(),
            'turno' => $grupo->turno ?: 'DIA',
            'planificado' => $planificado,
            'programado' => $programado,
            'presentes' => $presentes,
            'tardanzas' => $attendanceDetails->where('estado', AsistenciaDetalle::ESTADO_TARDANZA)->count(),
            'ausentes' => $attendanceDetails->where('estado', AsistenciaDetalle::ESTADO_AUSENTE)->count(),
            'justificados' => $attendanceDetails->where('estado', AsistenciaDetalle::ESTADO_JUSTIFICADO)->count(),
            'no_corresponde' => $attendanceDetails->where('estado', AsistenciaDetalle::ESTADO_NO_CORRESPONDE)->count(),
            'pendientes_marcacion' => max(0, $programado - $attendanceDetails->whereNotNull('estado')->count()),
            'titulares_presentes' => $titulares,
            'suplentes_presentes' => $suplentes,
            'adicionales_presentes' => $adicionales,
            'sin_clasificar_presentes' => $sinClasificar,
            'personal_sin_actividad' => $personalSinActividad,
            'brecha_plan_programado' => $planificado - $programado,
            'brecha_programado_real' => $programado - $presentes,
            'brecha_plan_real' => $planificado - $presentes,
            'exceso_programado' => max(0, $programado - $planificado),
            'exceso_real' => max(0, $presentes - $planificado),
            'porcentaje_programacion' => $this->percent($programado, $planificado),
            'porcentaje_asistencia' => $this->percent($presentes, $programado),
            'porcentaje_cumplimiento_real' => $this->percent($presentes, $planificado),
            'asistencia_cerrada' => ($asistencia?->estado === 'CERRADO'),
            'datos_completos' => ($asistencia?->estado === 'CERRADO'),
            'source_closed_at' => $asistencia?->estado === 'CERRADO' ? $asistencia?->updated_at : null,
            'recalculated_at' => now(),
        ];
    }

    private function principalActivityAssignments(string $activityId): array
    {
        if (!Schema::hasTable('grupo_trabajo_detalle_actividades')) {
            return [];
        }

        return DB::table('grupo_trabajo_detalle_actividades')
            ->where('rq_mina_actividad_id', $activityId)
            ->where('es_principal', 1)
            ->pluck('grupo_trabajo_detalle_id')
            ->all();
    }

    private function detalleTieneActividadPrincipal(AsistenciaDetalle $detalle): bool
    {
        if (!$detalle->grupo_trabajo_detalle_id || !Schema::hasTable('grupo_trabajo_detalle_actividades')) {
            return false;
        }

        return DB::table('grupo_trabajo_detalle_actividades')
            ->where('grupo_trabajo_detalle_id', $detalle->grupo_trabajo_detalle_id)
            ->where('es_principal', 1)
            ->exists();
    }

    private function planificado(GrupoTrabajo $grupo, Collection $activityIds): int
    {
        if ($activityIds->isEmpty() || !Schema::hasTable('rq_mina_actividad_turnos')) {
            return (int) ($grupo->cantidad_planificada_snapshot ?? 0);
        }

        $turnColumn = strtoupper((string) $grupo->turno) === 'NOCHE' ? 'turno_b' : 'turno_a';

        return RQMinaActividadTurno::query()
            ->whereIn('actividad_id', $activityIds->all())
            ->where('fecha', $grupo->fecha?->toDateString())
            ->get([$turnColumn])
            ->sum(fn (RQMinaActividadTurno $turno): int => $this->parsePlanValue($turno->{$turnColumn}));
    }

    private function parsePlanValue(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        preg_match('/-?\d+/', (string) $value, $matches);

        return isset($matches[0]) ? max(0, (int) $matches[0]) : 0;
    }

    private function countPresenceBySnapshot(Collection $details, string $field, string $expected): int
    {
        return $details->filter(fn (AsistenciaDetalle $detalle): bool => strtoupper((string) $detalle->{$field}) === $expected)->count();
    }

    private function percent(int $value, int $base): float
    {
        return $base > 0 ? round(($value / $base) * 100, 2) : 0.0;
    }

    private function existingResumenId(array $lookup): ?string
    {
        return ParadaEjecucionResumen::query()
            ->where('rq_mina_plan_id', $lookup['rq_mina_plan_id'])
            ->where('rq_mina_actividad_grupo_id', $lookup['rq_mina_actividad_grupo_id'])
            ->where('actividad_key', $lookup['actividad_key'])
            ->where('fecha', $lookup['fecha'])
            ->where('turno', $lookup['turno'])
            ->value('id');
    }
}
