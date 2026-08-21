<?php

namespace App\Modules\Evaluaciones\Policies;

use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionMatrix;

class EvaluacionesPolicy
{
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

        return PermissionMatrix::userCanDirectAny($usuario, 'evaluaciones', $actions);
    }

    public function canEvaluateType(Usuario $usuario, string $type): bool
    {
        $action = match ($type) {
            'desempeno' => 'evaluar_desempeno',
            'supervisores' => 'evaluar_supervisores',
            'residentes' => 'evaluar_residentes',
            default => '',
        };

        return $action !== '' && PermissionMatrix::userCanDirect($usuario, 'evaluaciones', $action);
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
}
