<?php

namespace App\Modules\Evaluaciones\Policies;

use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Models\Personal;
use App\Support\Rbac\PermissionMatrix;

class EvaluacionesPolicy
{
    public function ownedBy(\Illuminate\Database\Eloquent\Builder $query, Usuario $usuario): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($owner) use ($usuario): void {
            $owner->where('created_by_usuario_id', $usuario->id);
            if ($usuario->personal_id) {
                $owner->orWhere(fn ($legacy) => $legacy->whereNull('created_by_usuario_id')->where('evaluador_id', $usuario->personal_id));
            }
        });
    }

    public function owns(Usuario $usuario, \App\Models\EvaluacionSupervisor $item): bool
    {
        return $item->created_by_usuario_id !== null
            ? (string) $item->created_by_usuario_id === (string) $usuario->id
            : ($usuario->personal_id !== null && (string) $item->evaluador_id === (string) $usuario->personal_id);
    }

    private const TYPE_ACTIONS = [
        'desempeno' => ['ver_desempeno', 'evaluar_desempeno'],
        'supervisores' => ['ver_supervisores', 'evaluar_supervisores'],
        'residentes' => ['ver_residentes', 'evaluar_residentes'],
    ];

    public function manage(Usuario $usuario): bool
    {
        return collect(self::TYPE_ACTIONS)
            ->flatten()
            ->contains(fn (string $action): bool => PermissionMatrix::userCanDirect($usuario, 'evaluaciones', $action))
            || PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'administrar');
    }

    public function canViewType(Usuario $usuario, string $type): bool
    {
        $actions = self::TYPE_ACTIONS[$type] ?? [];

        return PermissionMatrix::userCanDirectAny($usuario, 'evaluaciones', $actions)
            && $this->actorMatchesType($usuario, $type);
    }

    public function canEvaluateType(Usuario $usuario, string $type): bool
    {
        $action = match ($type) {
            'desempeno' => 'evaluar_desempeno',
            'supervisores' => 'evaluar_supervisores',
            'residentes' => 'evaluar_residentes',
            default => '',
        };

        return $action !== ''
            && PermissionMatrix::userCanDirect($usuario, 'evaluaciones', $action)
            && $this->actorMatchesType($usuario, $type);
    }

    public function canViewOthers(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'ver_todas_evaluaciones');
    }

    public function canEvaluateOthers(Usuario $usuario): bool
    {
        return $this->canEvaluateType($usuario, 'desempeno') && $this->isAdministrativeOverride($usuario);
    }

    public function canEvaluateSupervisorTarget(
        Usuario $usuario,
        string $personalId,
        string $destinationType,
        string $destinationId,
        ?string $groupId = null,
    ): bool
    {
        if (!$this->canEvaluateType($usuario, 'supervisores')) {
            return false;
        }

        return Personal::query()
            ->whereKey($personalId)
            ->where('es_supervisor', true)
            ->exists();
    }

    public function canEvaluateResidentTarget(Usuario $usuario, string $personalId): bool
    {
        return $this->canEvaluateType($usuario, 'residentes')
            && Personal::query()
                ->whereKey($personalId)
                ->whereRaw('UPPER(COALESCE(puesto, ?)) LIKE ?', ['', '%RESIDENTE%'])
                ->exists();
    }

    public function canAccessDestino(Usuario $usuario, ?string $destinoTipo, ?string $destinoId): bool
    {
        if (!$this->manage($usuario)) {
            return false;
        }

        $tipo = strtoupper((string) $destinoTipo);

        if ($tipo !== 'MINA') {
            return true;
        }

        if ($this->isPrivileged($usuario)) {
            return true;
        }

        if (!$destinoId) {
            return false;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $destinoId)
            ->exists();
    }

    public function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'administrar');
    }

    public function isAdministrativeOverride(Usuario $usuario): bool
    {
        $roles = $this->roleNames($usuario);

        if ($roles->contains(fn (string $role): bool => in_array($role, ['ADMIN', 'SUPERADMIN'], true))) {
            return true;
        }

        if ($roles->contains(fn (string $role): bool => str_contains($role, 'GERENTE'))) {
            return false;
        }

        return PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'administrar');
    }

    public function isResidentActor(Usuario $usuario): bool
    {
        if (!$usuario->personal_id) {
            return false;
        }

        $position = strtoupper(trim((string) $usuario->personal?->puesto));

        return str_contains($position, 'RESIDENTE')
            || $this->roleNames($usuario)->contains(fn (string $role): bool => str_contains($role, 'RESIDENTE'));
    }

    public function isManagementActor(Usuario $usuario): bool
    {
        return $this->isAdministrativeOverride($usuario)
            || PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'habilitar_evaluacion_residentes');
    }

    private function actorMatchesType(Usuario $usuario, string $type): bool
    {
        return match ($type) {
            'supervisores' => true,
            'residentes' => $this->isManagementActor($usuario),
            'desempeno' => true,
            default => false,
        };
    }

    private function roleNames(Usuario $usuario): \Illuminate\Support\Collection
    {
        $usuario->loadMissing(['rol:id,nombre', 'rolesAdicionales:id,nombre']);

        return collect([$usuario->rol?->nombre])
            ->merge($usuario->rolesAdicionales->pluck('nombre'))
            ->filter()
            ->map(fn ($role): string => strtoupper(trim((string) $role)))
            ->unique()
            ->values();
    }
}
