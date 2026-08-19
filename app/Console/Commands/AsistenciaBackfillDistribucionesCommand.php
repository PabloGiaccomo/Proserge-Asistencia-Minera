<?php

namespace App\Console\Commands;

use App\Models\AsistenciaDetalle;
use App\Models\GrupoTrabajoDetalle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AsistenciaBackfillDistribucionesCommand extends Command
{
    protected $signature = 'asistencia:backfill-distribuciones {--dry-run : Revisar sin modificar datos}';

    protected $description = 'Vincula asistencia_detalle historico con grupo_trabajo_detalle cuando existe una unica coincidencia segura.';

    public function handle(): int
    {
        if (!Schema::hasColumn('asistencia_detalle', 'grupo_trabajo_detalle_id')) {
            $this->error('La columna asistencia_detalle.grupo_trabajo_detalle_id no existe. Ejecuta migraciones primero.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'encabezados_revisados' => 0,
            'detalles_revisados' => 0,
            'vinculados' => 0,
            'omitidos_sin_coincidencia' => 0,
            'omitidos_ambiguedad' => 0,
            'errores' => 0,
        ];
        $encabezados = [];

        DB::table('asistencia_detalle as ad')
            ->join('asistencia_encabezado as ae', 'ae.id', '=', 'ad.asistencia_id')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'ae.grupo_trabajo_id')
            ->whereNull('ad.grupo_trabajo_detalle_id')
            ->select([
                'ad.id',
                'ad.asistencia_id',
                'ad.trabajador_id',
                'ae.fecha',
                'gt.id as grupo_trabajo_id',
            ])
            ->orderBy('ad.id')
            ->chunkById(200, function ($rows) use (&$stats, &$encabezados, $dryRun): void {
                foreach ($rows as $row) {
                    $stats['detalles_revisados']++;
                    $encabezados[$row->asistencia_id] = true;

                    try {
                        $matches = GrupoTrabajoDetalle::query()
                            ->where('grupo_trabajo_id', $row->grupo_trabajo_id)
                            ->where('personal_id', $row->trabajador_id)
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
                            AsistenciaDetalle::query()
                                ->where('id', $row->id)
                                ->update($this->filterColumns('asistencia_detalle', [
                                    'grupo_trabajo_detalle_id' => $match->id,
                                    'rq_proserge_detalle_id' => $match->rq_proserge_detalle_id,
                                    'puesto_snapshot' => $match->puesto_asignado_snapshot,
                                    'posicion_asignacion_snapshot' => $match->posicion_asignacion_snapshot,
                                    'tipo_asignacion_snapshot' => $match->tipo_asignacion_snapshot,
                                    'estado_distribucion_snapshot' => $match->estado_distribucion,
                                    'origen_registro' => 'BACKFILL',
                                    'updated_at' => now(),
                                ]));
                        }

                        $stats['vinculados']++;
                    } catch (\Throwable) {
                        $stats['errores']++;
                    }
                }
            }, 'ad.id', 'id');

        $stats['encabezados_revisados'] = count($encabezados);

        $this->info($dryRun ? 'Backfill de asistencia revisado en dry-run.' : 'Backfill de asistencia ejecutado.');
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
