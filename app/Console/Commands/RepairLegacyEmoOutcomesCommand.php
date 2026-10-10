<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Modules\Personal\Services\LegacyEmoOutcomeRepairService;
use Illuminate\Console\Command;

class RepairLegacyEmoOutcomesCommand extends Command
{
    protected $signature = 'habilitacion-minera:repair-legacy-emo
        {--apply : Confirma la escritura; sin esta opcion solo muestra candidatos}
        {--actor= : UUID del usuario responsable}
        {--motivo= : Motivo operativo obligatorio al aplicar}';

    protected $description = 'Previsualiza o repara aptitudes EMO legacy inequivocas con auditoria';

    public function handle(LegacyEmoOutcomeRepairService $repair): int
    {
        $candidates = $repair->preview();
        $this->table(['Examen', 'Asignacion', 'Resultado', 'Detalle', 'Estado destino'], $candidates->map(fn (array $row) => [
            $row['exam_id'], $row['assignment_id'], $row['outcome'], $row['detail'] ?: 'No registrado', $row['to'],
        ])->all());
        if (! $this->option('apply')) {
            $this->info('Dry-run: no se modificaron datos. Use --apply --actor=UUID --motivo="..." para confirmar.');

            return self::SUCCESS;
        }
        $actorId = trim((string) $this->option('actor'));
        $reason = trim((string) $this->option('motivo'));
        $actor = $actorId !== '' ? Usuario::query()->find($actorId) : null;
        if (! $actor || $reason === '') {
            $this->error('Para aplicar debe indicar un actor existente y un motivo. No se modificaron datos.');

            return self::INVALID;
        }

        $result = $repair->apply($actor, $reason);
        $this->info("Reparados: {$result['reparados']}. Omitidos por cambio concurrente: {$result['omitidos']}.");

        return self::SUCCESS;
    }
}
