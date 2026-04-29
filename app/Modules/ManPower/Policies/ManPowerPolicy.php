<?php

namespace App\Modules\ManPower\Policies;

use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionMatrix;

class ManPowerPolicy
{
    public function viewParadas(Usuario $usuario): bool
    {
        return PermissionMatrix::userCan($usuario, 'man_power', 'ver');
    }

    public function manageGrupos(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanAny($usuario, 'man_power', ['crear', 'editar', 'actualizar', 'asignar']);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }

    public function manageGrupo(Usuario $usuario, GrupoTrabajo $grupo): bool
    {
        if (!$this->manageGrupos($usuario)) {
            return false;
        }

        $minaId = $grupo->rqMina?->mina_id;

        if (!$minaId) {
            return false;
        }

        return $this->canAccessMina($usuario, $minaId);
    }

    private function isAllowedRole(Usuario $usuario): bool
    {
        return PermissionMatrix::userCan($usuario, 'man_power', 'ver');
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'man_power', 'administrar');
    }
}
