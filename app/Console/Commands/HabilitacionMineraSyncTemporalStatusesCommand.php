<?php

namespace App\Console\Commands;

use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use Illuminate\Console\Command;

class HabilitacionMineraSyncTemporalStatusesCommand extends Command
{
    protected $signature = 'habilitacion-minera:sincronizar-vigencias';

    protected $description = 'Actualiza vencimientos de examenes y estados de habilitacion minera';

    public function handle(PersonalMinaHabilitacionService $service): int
    {
        $result = $service->syncTemporalStatuses();

        $this->info(sprintf(
            'Examenes actualizados: %d. Asignaciones actualizadas: %d. Errores: %d.',
            $result['examenes_actualizados'],
            $result['asignaciones_actualizadas'],
            $result['errores'],
        ));

        return $result['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
