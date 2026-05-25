<?php

namespace App\Console\Commands;

use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use Illuminate\Console\Command;

class ParadaHerramientasDeadlineReminderCommand extends Command
{
    protected $signature = 'herramientas-parada:alertas-vencimiento';

    protected $description = 'Emite alertas cuando las listas de herramientas estan a dos dias de vencer.';

    public function handle(ParadaHerramientaService $service): int
    {
        $count = $service->emitDeadlineReminders();

        $this->info(sprintf('Alertas emitidas o sincronizadas: %d', $count));

        return self::SUCCESS;
    }
}
