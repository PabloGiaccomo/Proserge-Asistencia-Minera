<?php

namespace App\Modules\RQProserge\Policies;

use App\Models\RQProserge;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;

class RQProsergePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $this->isRrhhOrPrivileged($usuario);
    }

    public function view(Usuario $usuario, RQProserge $rqProserge): bool
    {
        if (!$this->isRrhhOrPrivileged($usuario)) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqProserge->mina_id);
    }

    public function assign(Usuario $usuario, RQProserge $rqProserge): bool
    {
        if (!$this->isRrhhOrPrivileged($usuario)) {
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
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }

    private function isRrhhOrPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['RRHH', 'ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
