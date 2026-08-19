<?php

namespace App\Console\Commands;

use App\Modules\Asistencia\Services\ParadaExecutionMetricsService;
use Illuminate\Console\Command;

class ParadaRecalcularEjecucionCommand extends Command
{
    protected $signature = 'parada:recalcular-ejecucion
        {--rq-mina= : ID de RQ Mina a recalcular}
        {--plan= : ID de plan operativo a recalcular}
        {--dry-run : Calcular sin guardar cambios}';

    protected $description = 'Recalcula la proyeccion de ejecucion real desde Man Power y asistencia.';

    public function handle(ParadaExecutionMetricsService $service): int
    {
        $result = $service->recalculateAll([
            'rq_mina_id' => $this->option('rq-mina') ?: null,
            'rq_mina_plan_id' => $this->option('plan') ?: null,
        ], (bool) $this->option('dry-run'));

        $this->info($this->option('dry-run') ? 'Recalculo revisado en dry-run.' : 'Recalculo ejecutado.');
        foreach ($result as $label => $value) {
            if ($label === 'errores') {
                $this->line('errores: '.count($value));
                foreach ($value as $error) {
                    $this->warn(($error['grupo_trabajo_id'] ?? 'sin grupo').': '.($error['message'] ?? 'Error'));
                }
                continue;
            }

            $this->line(str_replace('_', ' ', $label).': '.$value);
        }

        return count($result['errores'] ?? []) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
