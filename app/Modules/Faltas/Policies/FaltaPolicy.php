<?php

namespace App\Modules\Faltas\Policies;

use App\Models\Falta;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;

class FaltaPolicy
{
    public function manage(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['PLANNER', 'RRHH', 'SUPERVISOR', 'ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function view(Usuario $usuario, Falta $falta): bool
    {
        return $this->canAccessDestino($usuario, (string) $falta->destino_tipo, (string) $falta->destino_id);
    }

    public function update(Usuario $usuario, Falta $falta): bool
    {
        if (!$this->view($usuario, $falta)) {
            return false;
        }

        return strtoupper((string) $falta->estado) !== 'ANULADA';
    }

    public function canAccessDestino(Usuario $usuario, string $destinoTipo, string $destinoId): bool
    {
        if (!$this->manage($usuario)) {
            return false;
        }

        if (strtoupper($destinoTipo) !== 'MINA') {
            return true;
        }

        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $destinoId)
            ->exists();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
