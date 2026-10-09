<?php

namespace App\Console\Commands;

use App\Models\EppEntrega;
use Illuminate\Console\Command;
use Throwable;

class EppLegacyBackfillCommand extends Command
{
    protected $signature = 'epp:backfill-legacy-traceability {--dry-run}';

    protected $description = 'Marca entregas EPP historicas como legado sin inventar operaciones ni firmas';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['scanned' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        EppEntrega::query()
            ->whereNull('operacion_id')
            ->orderBy('id')
            ->chunkById(200, function ($deliveries) use (&$counts, $dryRun): void {
                foreach ($deliveries as $delivery) {
                    $counts['scanned']++;
                    try {
                        $pending = $delivery->estado === EppEntrega::ESTADO_ENTREGADO
                            ? max(0, (int) $delivery->cantidad)
                            : 0;
                        $values = [
                            'cantidad_original' => max(0, (int) $delivery->cantidad),
                            'cantidad_pendiente_devolucion' => $pending,
                            'politica_devolucion_snapshot' => 'RETORNABLE',
                            'es_legacy' => true,
                            'estado_integridad' => EppEntrega::INTEGRIDAD_LEGACY,
                        ];

                        $dirty = collect($values)->contains(
                            fn ($value, $column): bool => $delivery->getAttribute($column) !== $value
                        );
                        if (! $dirty) {
                            $counts['skipped']++;

                            continue;
                        }
                        if (! $dryRun) {
                            $delivery->forceFill($values)->save();
                        }
                        $counts['updated']++;
                    } catch (Throwable $exception) {
                        $counts['errors']++;
                        $this->error('No se pudo procesar la entrega '.$delivery->id.': '.$exception->getMessage());
                    }
                }
            });

        $this->line('Simulacion: '.($dryRun ? 'si' : 'no'));
        $this->line('Revisados: '.$counts['scanned']);
        $this->line('Actualizados: '.$counts['updated']);
        $this->line('Omitidos: '.$counts['skipped']);
        $this->line('Errores: '.$counts['errors']);

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
