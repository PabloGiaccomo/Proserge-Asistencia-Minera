<?php

namespace App\Modules\RQMina\Policies;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;

class RQMinaPolicy
{
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

    public function view(Usuario $usuario, RQMina $rqMina): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return $this->canAccessMina($usuario, $rqMina->mina_id);
    }

    public function update(Usuario $usuario, RQMina $rqMina): bool
    {
        if (in_array($rqMina->estado, ['CERRADO', 'CANCELADO'], true)) {
            return false;
        }

        if ($this->isPrivileged($usuario)) {
            return true;
        }

        if ((string) $rqMina->created_by_usuario_id !== (string) $usuario->id) {
            return false;
        }

        return $this->canAccessMina($usuario, $rqMina->mina_id);
    }

    public function send(Usuario $usuario, RQMina $rqMina): bool
    {
        if ((string) $rqMina->estado !== 'BORRADOR') {
            return false;
        }

        return $this->update($usuario, $rqMina);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
