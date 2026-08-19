<?php

namespace App\Console\Commands;

use App\Models\RQMina;
use App\Modules\RQMina\Services\RQMinaPlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RQMinaBackfillPlanesCommand extends Command
{
    protected $signature = 'rq-mina:backfill-planes {--dry-run : Muestra los cambios sin insertar ni actualizar datos}';

    protected $description = 'Crea planes iniciales de RQ Mina y vincula grupos operativos legacy sin plan';

    public function handle(RQMinaPlanService $planService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'rq_revisados' => 0,
            'planes_creados' => 0,
            'grupos_vinculados' => 0,
            'omitidos' => 0,
            'errores' => 0,
        ];

        RQMina::query()
            ->whereHas('actividadGrupos')
            ->withCount([
                'planes',
                'actividadGrupos as grupos_sin_plan_count' => fn ($query) => $query->whereNull('rq_mina_plan_id'),
            ])
            ->orderBy('id')
            ->chunk(100, function ($items) use ($planService, $dryRun, &$stats): void {
                foreach ($items as $rqMina) {
                    $stats['rq_revisados']++;

                    try {
                        $missingGroups = (int) ($rqMina->grupos_sin_plan_count ?? 0);
                        if ($missingGroups === 0) {
                            $stats['omitidos']++;
                            continue;
                        }

                        if ($dryRun) {
                            $wouldCreate = (int) ($rqMina->planes_count ?? 0) === 0;
                            $stats['planes_creados'] += $wouldCreate ? 1 : 0;
                            $stats['grupos_vinculados'] += $missingGroups;
                            $this->line(sprintf(
                                '[dry-run] RQ %s: %s plan inicial, vincularia %d grupo(s).',
                                $rqMina->id,
                                $wouldCreate ? 'crearia' : 'usaria',
                                $missingGroups
                            ));
                            continue;
                        }

                        DB::transaction(function () use ($rqMina, $planService, &$stats): void {
                            $hadDefaultPlan = $rqMina->planes()
                                ->where('codigo', 'PLAN-001')
                                ->where('version', 1)
                                ->lockForUpdate()
                                ->exists();

                            $plan = $planService->ensureDefaultPlan($rqMina->fresh());
                            if (!$hadDefaultPlan) {
                                $stats['planes_creados']++;
                            }

                            $updated = $rqMina->actividadGrupos()
                                ->whereNull('rq_mina_plan_id')
                                ->update([
                                    'rq_mina_plan_id' => (string) $plan->id,
                                    'updated_at' => now(),
                                ]);

                            $stats['grupos_vinculados'] += $updated;
                        });
                    } catch (Throwable $exception) {
                        $stats['errores']++;
                        $this->error(sprintf('RQ %s: %s', $rqMina->id, $exception->getMessage()));
                    }
                }
            });

        $this->info(sprintf('RQ revisados: %d', $stats['rq_revisados']));
        $this->info(sprintf('Planes creados: %d', $stats['planes_creados']));
        $this->info(sprintf('Grupos vinculados: %d', $stats['grupos_vinculados']));
        $this->info(sprintf('Registros omitidos: %d', $stats['omitidos']));
        $this->info(sprintf('Errores: %d', $stats['errores']));

        return $stats['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
