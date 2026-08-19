<?php

namespace App\Modules\RQMina\Services;

use App\Models\ParadaEjecucionResumen;
use App\Models\RQMina;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaDetalle;
use App\Models\RQMinaPlan;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\Asistencia\Services\ParadaExecutionMetricsService;
use App\Modules\ManPower\Services\ManPowerPlanningService;
use App\Modules\RQProserge\Services\RQProsergeCoverageService;
use App\Modules\RQMina\Support\ParadaKpiDefinition;
use App\Modules\Transporte\Services\TransportePlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class ParadaDashboardService
{
    public function __construct(
        private readonly RQProsergeCoverageService $coverageService,
        private readonly ManPowerPlanningService $manPowerService,
        private readonly TransportePlanningService $transporteService,
        private readonly ParadaExecutionMetricsService $executionMetricsService,
    ) {
    }

    public function build(Usuario $usuario, RQMina $rqMina, array $filters = []): array
    {
        $rqMina->loadMissing(['mina:id,nombre', 'planes']);

        $plan = $this->resolvePlan($rqMina, (string) ($filters['plan_id'] ?? ''));
        $fecha = $this->normalizeDate(
            (string) ($filters['fecha'] ?? ''),
            $plan?->fecha_inicio?->toDateString() ?: $rqMina->fecha_inicio?->toDateString()
        );
        $turno = $this->normalizeTurno((string) ($filters['turno'] ?? 'DIA'));

        $manPower = $this->manPowerService->buildContext($usuario, [
            'rq_mina_id' => (string) $rqMina->id,
            'plan_id' => $plan?->id,
            'fecha' => $fecha,
            'turno' => $turno,
        ]);

        $transport = $this->transporteService->buildContext($usuario, [
            'rq_mina_id' => (string) $rqMina->id,
            'rq_mina_plan_id' => $plan?->id,
            'fecha' => $fecha,
            'turno' => $turno,
        ]);

        $dashboard = [
            'filters' => [
                'plan_id' => $plan?->id,
                'fecha' => $fecha,
                'turno' => $turno,
            ],
            'rq_mina' => $this->rqMinaPayload($rqMina),
            'plans' => $this->plansPayload($rqMina),
            'plan' => $plan ? $this->planPayload($plan) : null,
            'rq' => $this->rqSummary($rqMina),
            'coverage' => $this->coverageSummary($rqMina),
            'plan_operativo' => $this->planSummary($rqMina, $plan),
            'man_power' => $manPower,
            'transport' => $transport,
            'execution' => $this->executionSummary($rqMina, $plan, $fecha, $turno),
            'generated_at' => now()->toDateTimeString(),
        ];

        $dashboard['kpis'] = ParadaKpiDefinition::cards($dashboard);
        $dashboard['alerts'] = $this->alerts($dashboard);
        $dashboard['formulas'] = ParadaKpiDefinition::formulas();

        return $dashboard;
    }

    public function recalculate(RQMina $rqMina, ?string $planId = null, bool $dryRun = false): array
    {
        return $this->executionMetricsService->recalculateAll([
            'rq_mina_id' => (string) $rqMina->id,
            'rq_mina_plan_id' => $planId,
        ], $dryRun);
    }

    private function resolvePlan(RQMina $rqMina, string $planId): ?RQMinaPlan
    {
        $plans = $rqMina->planes;

        if ($planId !== '') {
            $selected = $plans->firstWhere('id', $planId);
            if ($selected) {
                return $selected;
            }
        }

        return $plans->first(fn (RQMinaPlan $plan): bool => $plan->estado === RQMinaPlan::ESTADO_VIGENTE)
            ?? $plans->first();
    }

    private function rqMinaPayload(RQMina $rqMina): array
    {
        return [
            'id' => (string) $rqMina->id,
            'mina_id' => (string) $rqMina->mina_id,
            'mina_nombre' => (string) ($rqMina->mina?->nombre ?? '-'),
            'destino_tipo' => (string) ($rqMina->destino_tipo ?: 'MINA'),
            'destino_nombre' => (string) ($rqMina->destino_nombre ?: $rqMina->mina?->nombre ?: '-'),
            'area' => (string) $rqMina->area,
            'fecha_inicio' => $rqMina->fecha_inicio?->toDateString(),
            'fecha_fin' => $rqMina->fecha_fin?->toDateString(),
            'estado' => (string) $rqMina->estado,
        ];
    }

    private function plansPayload(RQMina $rqMina): array
    {
        return $rqMina->planes->map(fn (RQMinaPlan $plan): array => $this->planPayload($plan))->values()->all();
    }

    private function planPayload(RQMinaPlan $plan): array
    {
        return [
            'id' => (string) $plan->id,
            'codigo' => (string) $plan->codigo,
            'nombre' => (string) $plan->nombre,
            'version' => (int) $plan->version,
            'estado' => (string) $plan->estado,
            'fecha_inicio' => $plan->fecha_inicio?->toDateString(),
            'fecha_fin' => $plan->fecha_fin?->toDateString(),
        ];
    }

    private function rqSummary(RQMina $rqMina): array
    {
        if (!Schema::hasTable('rq_mina_detalle')) {
            return $this->emptyRqSummary();
        }

        $rows = RQMinaDetalle::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->get();

        return [
            'puestos' => $rows->count(),
            'titular_objetivo' => (int) $rows->sum('cantidad'),
            'respaldo_objetivo' => (int) $rows->sum('cantidad_backup'),
            'total_objetivo' => (int) $rows->sum(fn (RQMinaDetalle $row): int => (int) ($row->cantidad_total ?: ((int) $row->cantidad + (int) $row->cantidad_backup))),
            'atendido_snapshot' => (int) $rows->sum('cantidad_atendida'),
            'detalle' => $rows->map(fn (RQMinaDetalle $row): array => [
                'id' => (string) $row->id,
                'puesto' => (string) $row->puesto,
                'titular_objetivo' => (int) $row->cantidad,
                'respaldo_objetivo' => (int) $row->cantidad_backup,
                'total_objetivo' => (int) ($row->cantidad_total ?: ((int) $row->cantidad + (int) $row->cantidad_backup)),
                'atendido_snapshot' => (int) $row->cantidad_atendida,
            ])->values()->all(),
        ];
    }

    private function coverageSummary(RQMina $rqMina): array
    {
        if (!Schema::hasTable('rq_proserge')) {
            return ['estado' => 'SIN_RQ_PROSERGE', 'global' => [], 'detalles' => []];
        }

        $rqProserge = RQProserge::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$rqProserge) {
            return ['estado' => 'SIN_RQ_PROSERGE', 'global' => [], 'detalles' => []];
        }

        return $this->coverageService->calculateForRq($rqProserge);
    }

    private function planSummary(RQMina $rqMina, ?RQMinaPlan $plan): array
    {
        if (!Schema::hasTable('rq_mina_actividad_grupos')) {
            return ['grupos' => 0, 'actividades' => 0, 'transportes' => 0, 'detalle' => []];
        }

        $query = RQMinaActividadGrupo::query()
            ->with(['actividades', 'transportes'])
            ->where('rq_mina_id', (string) $rqMina->id)
            ->orderBy('orden');

        if ($plan && Schema::hasColumn('rq_mina_actividad_grupos', 'rq_mina_plan_id')) {
            $query->where('rq_mina_plan_id', (string) $plan->id);
        }

        $groups = $query->get();

        return [
            'grupos' => $groups->count(),
            'actividades' => $groups->sum(fn (RQMinaActividadGrupo $group): int => $group->actividades->count()),
            'transportes' => $groups->sum(fn (RQMinaActividadGrupo $group): int => $group->transportes->count()),
            'detalle' => $groups->map(fn (RQMinaActividadGrupo $group): array => [
                'id' => (string) $group->id,
                'nombre' => (string) $group->nombre,
                'area' => (string) $group->area_operativa,
                'modulo' => (string) $group->modulo,
                'actividades' => $group->actividades->count(),
                'transportes' => $group->transportes->count(),
            ])->values()->all(),
        ];
    }

    private function executionSummary(RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        if (!Schema::hasTable('parada_ejecucion_resumen')) {
            return ['summary' => $this->emptyExecutionSummary(), 'rows' => []];
        }

        $query = ParadaEjecucionResumen::query()
            ->with(['grupoOperativo:id,nombre,area_operativa,modulo', 'actividad:id,sait,area,ait_trabajo'])
            ->where('rq_mina_id', (string) $rqMina->id);

        if ($plan) {
            $query->where('rq_mina_plan_id', (string) $plan->id);
        }

        if ($fecha !== '') {
            $query->whereDate('fecha', $fecha);
        }

        if ($turno !== '') {
            $query->where('turno', $turno);
        }

        $rows = $query->orderBy('fecha')->orderBy('turno')->get();
        $summary = [
            'filas' => $rows->count(),
            'filas_cerradas' => $rows->where('asistencia_cerrada', true)->count(),
            'filas_abiertas' => $rows->where('asistencia_cerrada', false)->count(),
            'planificado' => (int) $rows->sum('planificado'),
            'programado' => (int) $rows->sum('programado'),
            'presentes' => (int) $rows->sum('presentes'),
            'tardanzas' => (int) $rows->sum('tardanzas'),
            'ausentes' => (int) $rows->sum('ausentes'),
            'pendientes_marcacion' => (int) $rows->sum('pendientes_marcacion'),
            'brecha_plan_programado' => (int) $rows->sum('brecha_plan_programado'),
            'brecha_programado_real' => (int) $rows->sum('brecha_programado_real'),
            'brecha_plan_real' => (int) $rows->sum('brecha_plan_real'),
            'exceso_programado' => (int) $rows->sum('exceso_programado'),
            'exceso_real' => (int) $rows->sum('exceso_real'),
        ];

        $summary['porcentaje_programacion'] = $this->percent($summary['programado'], $summary['planificado']);
        $summary['porcentaje_asistencia'] = $this->percent($summary['presentes'], $summary['programado']);
        $summary['porcentaje_cumplimiento_real'] = $this->percent($summary['presentes'], $summary['planificado']);

        return [
            'summary' => $summary,
            'rows' => $rows->map(fn (ParadaEjecucionResumen $row): array => [
                'fecha' => $row->fecha?->toDateString(),
                'turno' => (string) $row->turno,
                'grupo' => (string) ($row->grupoOperativo?->nombre ?? 'Grupo'),
                'actividad' => (string) ($row->actividad?->sait ?: $row->actividad?->ait_trabajo ?: ($row->actividad_key === '__GRUPO__' ? 'Resumen grupo' : $row->actividad_key)),
                'planificado' => (int) $row->planificado,
                'programado' => (int) $row->programado,
                'presentes' => (int) $row->presentes,
                'ausentes' => (int) $row->ausentes,
                'brecha_plan_real' => (int) $row->brecha_plan_real,
                'porcentaje_cumplimiento_real' => (float) $row->porcentaje_cumplimiento_real,
                'asistencia_cerrada' => (bool) $row->asistencia_cerrada,
                'recalculated_at' => $row->recalculated_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    private function alerts(array $dashboard): array
    {
        $alerts = [];
        $coverage = $dashboard['coverage']['global'] ?? [];
        $manPower = $dashboard['man_power']['resumen'] ?? [];
        $execution = $dashboard['execution']['summary'] ?? [];
        $transport = $dashboard['transport']['resumen'] ?? [];

        if (($dashboard['coverage']['estado'] ?? '') === 'SIN_RQ_PROSERGE') {
            $alerts[] = ['tone' => 'warning', 'message' => 'La parada aun no tiene RQ Proserge asociado.'];
        }

        if ((int) ($coverage['brecha_titular'] ?? 0) > 0 || (int) ($coverage['brecha_respaldo'] ?? 0) > 0) {
            $alerts[] = ['tone' => 'danger', 'message' => 'RQ Proserge tiene brechas de titulares o respaldo.'];
        }

        if ((int) ($manPower['brecha'] ?? 0) > 0) {
            $alerts[] = ['tone' => 'warning', 'message' => 'Man Power no cubre todo lo planificado para la fecha y turno seleccionados.'];
        }

        if ((int) ($execution['filas_abiertas'] ?? 0) > 0) {
            $alerts[] = ['tone' => 'warning', 'message' => 'Hay asistencia sin cerrar; los datos reales aun pueden cambiar.'];
        }

        if ((int) ($transport['personas_sin_transporte'] ?? 0) > 0) {
            $alerts[] = ['tone' => 'warning', 'message' => 'Hay personal distribuido sin transporte asignado.'];
        }

        return $alerts;
    }

    private function normalizeDate(string $date, ?string $fallback): string
    {
        try {
            return CarbonImmutable::parse($date !== '' ? $date : ($fallback ?: now()->toDateString()))->toDateString();
        } catch (\Throwable) {
            return $fallback ?: now()->toDateString();
        }
    }

    private function normalizeTurno(string $turno): string
    {
        $value = strtoupper(trim($turno));

        return in_array($value, ['B', 'NOCHE', 'NIGHT'], true) ? 'NOCHE' : 'DIA';
    }

    private function percent(int|float $value, int|float $base): float
    {
        return $base > 0 ? round(($value / $base) * 100, 2) : 0.0;
    }

    private function emptyRqSummary(): array
    {
        return [
            'puestos' => 0,
            'titular_objetivo' => 0,
            'respaldo_objetivo' => 0,
            'total_objetivo' => 0,
            'atendido_snapshot' => 0,
            'detalle' => [],
        ];
    }

    private function emptyExecutionSummary(): array
    {
        return [
            'filas' => 0,
            'filas_cerradas' => 0,
            'filas_abiertas' => 0,
            'planificado' => 0,
            'programado' => 0,
            'presentes' => 0,
            'tardanzas' => 0,
            'ausentes' => 0,
            'pendientes_marcacion' => 0,
            'brecha_plan_programado' => 0,
            'brecha_programado_real' => 0,
            'brecha_plan_real' => 0,
            'exceso_programado' => 0,
            'exceso_real' => 0,
            'porcentaje_programacion' => 0.0,
            'porcentaje_asistencia' => 0.0,
            'porcentaje_cumplimiento_real' => 0.0,
        ];
    }
}
