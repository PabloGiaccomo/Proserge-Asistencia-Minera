<?php

namespace App\Modules\Evaluaciones\Policies;

use App\Models\Usuario;
use App\Models\UsuarioMinaScope;

class EvaluacionesPolicy
{
    public function manage(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['SUPERVISOR', 'PLANNER', 'RRHH', 'ADMIN', 'GERENTE', 'SUPERADMIN'], true);
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

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
