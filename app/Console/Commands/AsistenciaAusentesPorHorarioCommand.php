<?php

namespace App\Console\Commands;

use App\Models\GrupoTrabajo;
use App\Modules\Asistencia\Services\AsistenciaService;
use Illuminate\Console\Command;

class AsistenciaAusentesPorHorarioCommand extends Command
{
    protected $signature = 'asistencia:ausentes-por-horario';
    protected $description = 'Marca pendientes como ausentes en grupos programados de la jornada actual';

    public function handle(AsistenciaService $service): int
    {
        $count = 0;
        $failed = 0;
        GrupoTrabajo::query()
            ->whereDate('fecha', now(config('app.display_timezone', 'America/Lima'))->toDateString())
            ->where('estado', GrupoTrabajo::ESTADO_PROGRAMADO)
            ->whereDoesntHave('asistencia', fn ($query) => $query->where('estado', 'CERRADO'))
            ->chunkById(100, function ($grupos) use ($service, &$count, &$failed): void {
                foreach ($grupos as $grupo) {
                    try {
                        $count += $service->marcarAusentesPorHorario($grupo);
                    } catch (\Throwable $error) {
                        report($error);
                        $failed++;
                    }
                }
            });
        $this->info("Ausencias registradas: {$count}. Grupos con errores: {$failed}.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
