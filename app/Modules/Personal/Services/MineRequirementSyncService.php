<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalMina;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class MineRequirementSyncService
{
    public function __construct(private readonly PersonalMinaHabilitacionService $habilitation) {}

    public function ensureAvailable(): void
    {
        if (! Schema::hasTable('mina_requisito_syncs')) {
            throw ValidationException::withMessages([
                'mina_id' => 'Falta aplicar la migracion de sincronizacion de requisitos. No se guardaron cambios.',
            ]);
        }
    }

    public function request(string $mineId, Usuario $actor): void
    {
        $this->ensureAvailable();
        DB::table('mina_requisito_syncs')->insertOrIgnore([
            'mina_id' => $mineId,
            'usuario_id' => $actor->id,
            'estado' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mina_requisito_syncs')->where('mina_id', $mineId)->lockForUpdate()->first();

        $enabled = DB::table('personal_mina')
            ->where('mina_id', $mineId)
            ->where('activo', true)
            ->where(function ($query): void {
                $query->where('estado_habilitacion', PersonalMina::ESTADO_HABILITADO)
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('estado_habilitacion')
                            ->where('estado', PersonalMina::ESTADO_HABILITADO);
                    });
            });

        DB::table('personal_mina_historial')->insertUsing(
            ['id', 'personal_mina_id', 'estado_anterior', 'estado_nuevo', 'observacion', 'usuario_id', 'fecha_cambio', 'created_at', 'updated_at'],
            (clone $enabled)->selectRaw(
                "UUID(), id, 'HABILITADO', 'EN_PROCESO', ?, ?, NOW(), NOW(), NOW()",
                ['Requisitos de la mina en actualizacion.', $actor->id]
            )
        );

        $enabled->update([
            'estado' => PersonalMina::ESTADO_EN_PROCESO,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'fecha_habilitacion' => null,
            'usuario_actualizacion_id' => $actor->id,
        ]);

        DB::table('mina_requisito_syncs')->where('mina_id', $mineId)->update([
            'usuario_id' => $actor->id,
            'estado' => 'PENDIENTE',
            'cursor_id' => null,
            'procesados' => 0,
            'total' => DB::table('personal_mina')->where('mina_id', $mineId)->where('activo', true)->count(),
            'intentos_fallidos' => 0,
            'ultimo_error' => null,
            'updated_at' => now(),
        ]);
    }

    public function statusesByMine(): Collection
    {
        if (! Schema::hasTable('mina_requisito_syncs')) {
            return collect();
        }

        return DB::table('mina_requisito_syncs')
            ->whereIn('estado', ['PENDIENTE', 'EN_PROCESO', 'ERROR'])
            ->get(['mina_id', 'estado', 'procesados', 'total'])
            ->keyBy('mina_id');
    }

    public function processPending(int $mineLimit = 5, int $batchSize = 20): array
    {
        $this->ensureAvailable();
        $mineIds = DB::table('mina_requisito_syncs')
            ->whereIn('estado', ['PENDIENTE', 'EN_PROCESO', 'ERROR'])
            ->orderBy('updated_at')
            ->limit($mineLimit)
            ->pluck('mina_id');
        $result = ['procesados' => 0, 'completados' => 0, 'errores' => 0];

        foreach ($mineIds as $mineId) {
            try {
                $step = DB::transaction(function () use ($mineId, $batchSize): array {
                    $sync = DB::table('mina_requisito_syncs')->where('mina_id', $mineId)->lockForUpdate()->first();
                    if (! $sync || $sync->estado === 'COMPLETADO') {
                        return ['procesados' => 0, 'completado' => false];
                    }

                    $actor = Usuario::query()->findOrFail($sync->usuario_id);
                    $assignments = PersonalMina::query()
                        ->where('mina_id', $mineId)
                        ->where('activo', true)
                        ->when($sync->cursor_id, fn ($query) => $query->where('id', '>', $sync->cursor_id))
                        ->orderBy('id')
                        ->limit($batchSize)
                        ->get();

                    foreach ($assignments as $assignment) {
                        $this->habilitation->generateRequiredExams($assignment, $actor, false, false);
                        $this->habilitation->refreshAssignmentFromExams($assignment, $actor, false);
                    }

                    $lastId = $assignments->last()?->id;
                    $hasMore = $lastId && PersonalMina::query()
                        ->where('mina_id', $mineId)
                        ->where('activo', true)
                        ->where('id', '>', $lastId)
                        ->exists();

                    DB::table('mina_requisito_syncs')->where('mina_id', $mineId)->update([
                        'estado' => $hasMore ? 'EN_PROCESO' : 'COMPLETADO',
                        'cursor_id' => $lastId ?: $sync->cursor_id,
                        'procesados' => (int) $sync->procesados + $assignments->count(),
                        'ultimo_error' => null,
                        'updated_at' => now(),
                    ]);

                    return ['procesados' => $assignments->count(), 'completado' => ! $hasMore];
                });
                $result['procesados'] += $step['procesados'];
                $result['completados'] += (int) $step['completado'];
            } catch (Throwable $exception) {
                DB::table('mina_requisito_syncs')->where('mina_id', $mineId)->update([
                    'estado' => 'ERROR',
                    'intentos_fallidos' => DB::raw('intentos_fallidos + 1'),
                    'ultimo_error' => class_basename($exception),
                    'updated_at' => now(),
                ]);
                report($exception);
                $result['errores']++;
            }
        }

        return $result;
    }
}
