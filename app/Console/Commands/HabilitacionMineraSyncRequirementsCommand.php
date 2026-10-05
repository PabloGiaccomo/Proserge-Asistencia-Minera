<?php

namespace App\Console\Commands;

use App\Modules\Personal\Services\MineRequirementSyncService;
use Illuminate\Console\Command;

class HabilitacionMineraSyncRequirementsCommand extends Command
{
    protected $signature = 'habilitacion-minera:sincronizar-requisitos';

    protected $description = 'Procesa por lotes los cambios pendientes de requisitos mineros';

    public function handle(MineRequirementSyncService $service): int
    {
        $started = microtime(true);
        $result = ['procesados' => 0, 'completados' => 0, 'errores' => 0];
        for ($pass = 0; $pass < 10 && microtime(true) - $started < 40; $pass++) {
            $step = $service->processPending();
            foreach ($result as $key => $count) {
                $result[$key] += $step[$key];
            }
            if ($step['errores'] || (! $step['procesados'] && ! $step['completados'])) {
                break;
            }
        }
        $this->info(sprintf(
            'Asignaciones procesadas: %d. Minas completadas: %d. Errores: %d.',
            $result['procesados'], $result['completados'], $result['errores']
        ));

        return $result['errores'] ? self::FAILURE : self::SUCCESS;
    }
}
