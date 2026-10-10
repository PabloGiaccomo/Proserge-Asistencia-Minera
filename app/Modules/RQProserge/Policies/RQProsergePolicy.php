<?php

namespace App\Modules\RQProserge\Policies;

use App\Models\RQProserge;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionMatrix;

class RQProsergePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'ver');
    }

    public function view(Usuario $usuario, RQProserge $rqProserge): bool
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'ver')) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqProserge->mina_id);
    }

    public function assign(Usuario $usuario, RQProserge $rqProserge): bool
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'asignar')) {
            return false;
        }

        if (in_array($rqProserge->estado, ['CERRADO', 'CANCELADO'], true)) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqProserge->mina_id);
    }

    public function update(Usuario $usuario, RQProserge $rqProserge): bool
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'actualizar')) {
            return false;
        }

        if (in_array($rqProserge->estado, ['CERRADO', 'CANCELADO'], true)) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqProserge->mina_id);
    }

    public function unassign(Usuario $usuario, RQProserge $rqProserge): bool
    {
        return $this->assign($usuario, $rqProserge);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        if ($this->hasUnrestrictedMinaAccess($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }

    public function hasUnrestrictedMinaAccess(Usuario $usuario): bool
    {
        // An explicit mine scope takes precedence over role-level privileges.
        if ($usuario->scopesMina()->exists()) {
            return false;
        }

        return count(array_intersect(PermissionMatrix::activeRoleNames($usuario), ['ADMIN', 'GERENTE', 'SUPERADMIN'])) > 0;
    }
}
