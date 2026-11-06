<?php

namespace App\Modules\Bienestar\Services;

use App\Models\PersonalBloqueo;
use App\Models\PersonalBloqueoEvento;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BienestarBloqueoService
{
    public function update(PersonalBloqueo $bloqueo, Usuario $actor, array $changes, string $reason): PersonalBloqueo
    {
        $this->authorize($actor, ['editar', 'actualizar']);

        return $this->change(
            $bloqueo,
            $actor,
            Arr::only($changes, ['tipo', 'motivo', 'detalle', 'fecha_inicio', 'fecha_fin']),
            $reason,
            PersonalBloqueoEvento::EDITADO,
        );
    }

    public function annul(PersonalBloqueo $bloqueo, Usuario $actor, string $reason): PersonalBloqueo
    {
        $this->authorize($actor, ['eliminar', 'anular']);

        return $this->change($bloqueo, $actor, ['estado' => 'ANULADO'], $reason, PersonalBloqueoEvento::ANULADO);
    }

    private function authorize(Usuario $actor, array $actions): void
    {
        if (!PermissionMatrix::userCanDirectAny($actor, 'bienestar', $actions)) {
            throw new AuthorizationException();
        }
    }

    private function change(PersonalBloqueo $bloqueo, Usuario $actor, array $changes, string $reason, string $type): PersonalBloqueo
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['motivo_decision' => 'Indica un motivo de decisión de hasta 1000 caracteres.']);
        }

        return DB::transaction(function () use ($bloqueo, $actor, $changes, $reason, $type): PersonalBloqueo {
            $current = PersonalBloqueo::query()->lockForUpdate()->findOrFail($bloqueo->id);
            if ($current->estado === 'ANULADO' && $type === PersonalBloqueoEvento::EDITADO) {
                throw ValidationException::withMessages(['motivo_decision' => 'El bloqueo ya está anulado y es de solo consulta.']);
            }

            $before = $this->snapshot($current);
            $current->fill($changes);
            if (!$current->isDirty()) {
                return $current;
            }
            if (!$current->save()) {
                throw new RuntimeException('No se pudo guardar el bloqueo de Bienestar.');
            }

            $event = PersonalBloqueoEvento::query()->create([
                'id' => (string) Str::uuid(),
                'personal_bloqueo_id' => $current->id,
                'actor_id' => $actor->id,
                'tipo' => $type,
                'motivo_decision' => $reason,
                'antes' => $before,
                'despues' => $this->snapshot($current),
                'ocurrido_at' => now(),
            ]);
            if (!$event->exists) {
                throw new RuntimeException('No se pudo registrar la decisión de Bienestar.');
            }

            return $current;
        });
    }

    private function snapshot(PersonalBloqueo $bloqueo): array
    {
        return [
            ...$bloqueo->only(['tipo', 'motivo', 'detalle', 'estado', 'bloqueado_por_id']),
            'fecha_inicio' => $bloqueo->fecha_inicio->toDateString(),
            'fecha_fin' => $bloqueo->fecha_fin->toDateString(),
        ];
    }
}
