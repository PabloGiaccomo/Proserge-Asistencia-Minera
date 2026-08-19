<?php

namespace App\Console\Commands;

use App\Models\RQMinaActividadTransporte;
use App\Models\TransporteServicio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransporteBackfillServiciosCommand extends Command
{
    protected $signature = 'transporte:backfill-servicios {--dry-run : Solo reporta, no modifica datos}';

    protected $description = 'Crea servicios de transporte desde requerimientos legacy solo cuando la interpretacion es inequivoca.';

    public function handle(): int
    {
        if (!Schema::hasTable('transporte_servicios') || !Schema::hasTable('rq_mina_actividad_transportes')) {
            $this->error('Tablas de transporte no disponibles. Ejecuta migraciones primero.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = ['revisados' => 0, 'creados' => 0, 'omitidos' => 0];

        RQMinaActividadTransporte::query()
            ->with(['grupo.rqMina', 'actividad'])
            ->whereNotNull('placas_asignadas')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($dryRun, &$stats): void {
                foreach ($items as $item) {
                    $stats['revisados']++;
                    $reason = $this->skipReason($item);

                    if ($reason !== null) {
                        $stats['omitidos']++;
                        $this->line("OMITIDO {$item->id}: {$reason}");
                        continue;
                    }

                    $payload = $this->payload($item);
                    $exists = TransporteServicio::query()
                        ->where('rq_mina_id', $payload['rq_mina_id'])
                        ->whereDate('fecha', $payload['fecha'])
                        ->where('turno', $payload['turno'])
                        ->where('placa', $payload['placa'])
                        ->exists();

                    if ($exists) {
                        $stats['omitidos']++;
                        $this->line("OMITIDO {$item->id}: servicio equivalente ya existe");
                        continue;
                    }

                    if ($dryRun) {
                        $stats['creados']++;
                        $this->info("DRY-RUN crearia servicio {$payload['placa']} para transporte {$item->id}");
                        continue;
                    }

                    DB::transaction(function () use ($payload, $item): void {
                        $serviceId = (string) Str::uuid();
                        TransporteServicio::query()->create([
                            ...$payload,
                            'id' => $serviceId,
                            'estado' => TransporteServicio::ESTADO_BORRADOR,
                        ]);

                        DB::table('transporte_servicio_alcances')->insert([
                            'id' => (string) Str::uuid(),
                            'transporte_servicio_id' => $serviceId,
                            'rq_mina_actividad_grupo_id' => $item->grupo_id,
                            'rq_mina_actividad_id' => $item->actividad_id,
                            'sait_snapshot' => $item->actividad?->sait ?: $item->alcance,
                            'orden' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });

                    $stats['creados']++;
                    $this->info("CREADO servicio {$payload['placa']} para transporte {$item->id}");
                }
            });

        $this->table(['Metrica', 'Total'], collect($stats)->map(fn ($value, $key): array => [$key, $value])->all());

        return self::SUCCESS;
    }

    private function skipReason(RQMinaActividadTransporte $item): ?string
    {
        if (!$item->grupo || !$item->grupo->rqMina) {
            return 'sin grupo o parada';
        }

        $plate = $this->singleValue((string) $item->placas_asignadas);
        if ($plate === null) {
            return 'placa vacia o multiple';
        }

        if (!$item->fecha_inicio || ($item->fecha_fin && !$item->fecha_inicio->isSameDay($item->fecha_fin))) {
            return 'fecha multiple o no inequivoca';
        }

        if (!$item->actividad_id && trim((string) $item->alcance) === '') {
            return 'sin actividad ni alcance';
        }

        return null;
    }

    private function payload(RQMinaActividadTransporte $item): array
    {
        return [
            'rq_mina_id' => $item->grupo->rq_mina_id,
            'rq_mina_plan_id' => $item->rq_mina_plan_id ?: $item->grupo->rq_mina_plan_id,
            'tipo' => $this->inferType($item),
            'fecha' => $item->fecha_inicio->toDateString(),
            'turno' => $item->turno ?: 'A',
            'tramo' => TransporteServicio::TRAMO_IDA,
            'placa' => mb_strtoupper((string) $this->singleValue((string) $item->placas_asignadas), 'UTF-8'),
            'capacidad' => is_numeric($item->capacidad_camion) ? (int) $item->capacidad_camion : null,
            'origen' => $item->origen_snapshot ?: $item->origen,
            'destino' => $item->destino_snapshot,
            'observaciones' => $item->indicaciones,
        ];
    }

    private function singleValue(string $text): ?string
    {
        $value = trim($text);
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/[;,\\n]+/', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        return count($parts) === 1 ? $parts[0] : null;
    }

    private function inferType(RQMinaActividadTransporte $item): string
    {
        $text = mb_strtoupper(trim(($item->unidad_carga ?? '').' '.($item->unidades_transporte ?? '')), 'UTF-8');

        return str_contains($text, 'CARGA') || str_contains($text, 'CAMION')
            ? TransporteServicio::TIPO_CARGA
            : TransporteServicio::TIPO_PERSONAL;
    }
}
