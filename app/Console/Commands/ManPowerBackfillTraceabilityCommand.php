<?php

namespace App\Console\Commands;

use App\Models\GrupoTrabajoDetalle;
use App\Models\RQProsergeDetalle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManPowerBackfillTraceabilityCommand extends Command
{
    protected $signature = 'man-power:backfill-traceability {--dry-run : Revisar sin modificar datos}';

    protected $description = 'Vincula integrantes historicos de Man Power con asignaciones unicas de RQ Proserge.';

    public function handle(): int
    {
        if (!Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id')) {
            $this->error('La columna grupo_trabajo_detalle.rq_proserge_detalle_id no existe. Ejecuta migraciones primero.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'grupos_revisados' => 0,
            'integrantes_revisados' => 0,
            'vinculados' => 0,
            'omitidos_sin_coincidencia' => 0,
            'omitidos_ambiguedad' => 0,
            'errores' => 0,
        ];

        $grupoIds = [];

        DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->whereNull('gtd.rq_proserge_detalle_id')
            ->whereNotNull('gt.rq_proserge_id')
            ->select([
                'gtd.id',
                'gtd.personal_id',
                'gt.id as grupo_trabajo_id',
                'gt.rq_proserge_id',
                'gt.fecha',
            ])
            ->orderBy('gt.fecha')
            ->chunkById(200, function ($rows) use (&$stats, &$grupoIds, $dryRun): void {
                foreach ($rows as $row) {
                    $stats['integrantes_revisados']++;
                    $grupoIds[$row->grupo_trabajo_id] = true;

                    try {
                        $matches = RQProsergeDetalle::query()
                            ->where('rq_proserge_id', $row->rq_proserge_id)
                            ->where('personal_id', $row->personal_id)
                            ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
                            ->whereDate('fecha_inicio', '<=', $row->fecha)
                            ->whereDate('fecha_fin', '>=', $row->fecha)
                            ->get();

                        if ($matches->isEmpty()) {
                            $stats['omitidos_sin_coincidencia']++;
                            continue;
                        }

                        if ($matches->count() > 1) {
                            $stats['omitidos_ambiguedad']++;
                            continue;
                        }

                        $match = $matches->first();

                        if (!$dryRun) {
                            GrupoTrabajoDetalle::query()
                                ->where('id', $row->id)
                                ->update($this->filterColumns('grupo_trabajo_detalle', [
                                    'rq_proserge_detalle_id' => $match->id,
                                    'puesto_asignado_snapshot' => $match->puesto_asignado_snapshot ?: $match->puesto_asignado,
                                    'posicion_asignacion_snapshot' => $match->posicion_asignacion,
                                    'tipo_asignacion_snapshot' => $match->tipo_asignacion,
                                    'estado_habilitacion_snapshot' => $match->estado_habilitacion_snapshot,
                                    'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
                                    'updated_at' => now(),
                                ]));
                        }

                        $stats['vinculados']++;
                    } catch (\Throwable) {
                        $stats['errores']++;
                    }
                }
            }, 'gtd.id', 'id');

        $stats['grupos_revisados'] = count($grupoIds);

        $this->info($dryRun ? 'Backfill revisado en modo dry-run.' : 'Backfill ejecutado.');
        foreach ($stats as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }

        return $stats['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
